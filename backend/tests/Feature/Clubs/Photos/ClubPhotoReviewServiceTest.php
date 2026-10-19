<?php

namespace Tests\Feature\Clubs\Photos;

use App\Enums\ClubStatus;
use App\Enums\CompetitionState;
use App\Enums\PhotoSubmissionStatus;
use App\Exceptions\ClubDomainException;
use App\Models\Challenge;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * ClubPhotoReviewService rules (D1): validation, EXIF stripping, duplicates,
 * rights, limits, the status machine, pack attach invariants, withdraw and
 * the query helpers used by the admin and the club portal.
 */
class ClubPhotoReviewServiceTest extends ClubPhotoTestCase
{
    private function assertDomainError(string $code, \Closure $fn): void
    {
        try {
            $fn();
            $this->fail("Expected ClubDomainException [{$code}]");
        } catch (ClubDomainException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertSame(422, $e->status());
        }
    }

    // ------------------------------------------------------------ submit

    public function test_submit_re_encodes_strips_exif_and_records_metadata(): void
    {
        $club = $this->makeClub();
        $upload = $this->jpeg(1, 1000, 750);
        $this->assertStringContainsString('GPS-SECRET', file_get_contents($upload->getRealPath()));

        $s = $this->service()->submit($club, $upload, $this->admin, ClubPhotoSubmission::SOURCE_ADMIN_UPLOAD, "  derby \x07goal ", false);

        $this->assertMatchesRegularExpression('#^' . $club->id . '/photos/[A-Za-z0-9]{40}\.jpg$#', $s->path);
        $bytes = Storage::disk('club_private')->get($s->path);
        $this->assertStringNotContainsString('GPS-SECRET', $bytes);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertSame([1000, 750, 'image/jpeg', 'club_private'], [$s->width, $s->height, $s->mime, $s->disk]);
        $this->assertSame(hash('sha256', file_get_contents($upload->getRealPath())), $s->sha256);
        $this->assertSame('derby goal', $s->club_note);
        $this->assertNull($s->rights_confirmed_at);
        $this->assertSame(PhotoSubmissionStatus::Pending, $s->status);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $event = ClubAuditEvent::where('action', 'photo.uploaded')->firstOrFail();
        $this->assertSame(['submission_id' => $s->id, 'source' => 'admin_upload'], $event->metadata);
        $this->assertSame('platform_admin', $event->actor_kind);
    }

    public function test_duplicates_are_detected_per_club(): void
    {
        $a = $this->makeClub();
        $b = $this->makeClub();
        $first = $this->submitPhoto($a, 42);

        $this->assertDomainError('photo_duplicate', fn () => $this->submitPhoto($a, 42));
        // Still a duplicate after the first one was rejected.
        $this->service()->reject($first, $this->admin, 'Too dark');
        $this->assertDomainError('photo_duplicate', fn () => $this->submitPhoto($a, 42));

        // Another club may submit the same picture.
        $this->assertSame($b->id, $this->submitPhoto($b, 42)->club_id);
        $this->assertSame(1, ClubPhotoSubmission::where('club_id', $a->id)->count());
        $this->assertCount(2, Storage::disk('club_private')->allFiles());
    }

    public function test_club_uploads_require_the_rights_confirmation(): void
    {
        $club = $this->makeClub();
        $clubAdmin = User::factory()->create();

        $this->assertDomainError('photo_rights_required', fn () => $this->service()->submit(
            $club, $this->jpeg(1), $clubAdmin, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, null, false, 'club_admin'));
        $this->assertSame(0, ClubPhotoSubmission::count());
        $this->assertSame([], Storage::disk('club_private')->allFiles());

        $s = $this->service()->submit($club, $this->jpeg(1), $clubAdmin, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, null, true, 'club_admin');
        $this->assertNotNull($s->rights_confirmed_at);
        $this->assertSame('club_admin', ClubAuditEvent::where('action', 'photo.uploaded')->value('actor_kind'));
    }

    public function test_open_limit_applies_to_club_uploads_only(): void
    {
        config(['ballspot.clubs.photos.max_open_per_club' => 2]);
        $club = $this->makeClub();
        $this->submitPhoto($club, 1, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD);
        $done = $this->submitPhoto($club, 2, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD);

        $this->assertDomainError('photo_pending_limit', fn () => $this->submitPhoto($club, 3, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD));
        // BallPicker can still upload on behalf of the club.
        $this->submitPhoto($club, 4);

        // Closed submissions free the slot.
        $this->service()->reject($done, $this->admin, 'Blurry');
        config(['ballspot.clubs.photos.max_open_per_club' => 3]);
        $this->submitPhoto($club, 5, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD);
        $this->assertSame(4, ClubPhotoSubmission::where('club_id', $club->id)->count());
    }

    public function test_invalid_files_are_refused_before_anything_is_stored(): void
    {
        $club = $this->makeClub();
        $cases = [
            'too small'   => fn () => $this->jpeg(1, 200, 150),
            'not image'   => fn () => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            'gif'         => fn () => UploadedFile::fake()->image('anim.gif', 640, 480),
        ];
        foreach ($cases as $label => $make) {
            $this->assertDomainError('photo_invalid', fn () => $this->service()->submit($club, $make(), $this->admin, 'admin_upload', null, true));
        }

        config(['ballspot.clubs.media.max_kb' => 1]);
        $this->assertDomainError('photo_invalid', fn () => $this->service()->submit($club, $this->jpeg(2, 800, 600), $this->admin, 'admin_upload', null, true));

        $this->assertSame(0, ClubPhotoSubmission::count());
        $this->assertSame([], Storage::disk('club_private')->allFiles());
    }

    public function test_club_status_gates_uploads(): void
    {
        $archived = $this->makeClub(['status' => ClubStatus::Archived]);
        $suspended = $this->makeClub(['status' => ClubStatus::Suspended]);

        $this->assertDomainError('club_status_invalid', fn () => $this->submitPhoto($archived));
        try {
            $this->submitPhoto($suspended, 2, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD);
            $this->fail('suspended club accepted a club upload');
        } catch (ClubDomainException $e) {
            $this->assertSame('club_unavailable', $e->errorCode());
        }
        // BallPicker may still prepare photos for a suspended club.
        $this->assertSame($suspended->id, $this->submitPhoto($suspended, 3)->club_id);
    }

    // ------------------------------------------------------------ transitions

    public function test_illegal_transitions_are_refused_and_change_nothing(): void
    {
        $club = $this->makeClub();

        $prep = $this->service()->startPreparation($this->submitPhoto($club, 1), $this->admin);
        $this->assertSame($this->admin->id, $prep->assigned_to_user_id, 'a workflow step assigns the actor when unassigned');
        $this->assertDomainError('photo_status_invalid', fn () => $this->service()->markInReview($prep, $this->admin));

        $rejected = $this->service()->reject($this->submitPhoto($club, 2), $this->admin, 'No ball');
        $this->assertDomainError('photo_status_invalid', fn () => $this->service()->startPreparation($rejected, $this->admin));
        $this->assertDomainError('photo_status_invalid', fn () => $this->service()->reject($rejected, $this->admin, 'again'));
        $this->assertDomainError('photo_status_invalid', fn () => $this->service()->assign($rejected, $this->admin));

        $converted = $this->service()->convert($this->submitPhoto($club, 3), $this->admin, $this->challengeData(), $this->jpeg(30))['submission'];
        $this->assertDomainError('photo_status_invalid', fn () => $this->service()->reject($converted, $this->admin, 'late'));
        $this->assertDomainError('photo_status_invalid', fn () => $this->service()->convert($converted, $this->admin, $this->challengeData(), $this->jpeg(31)));

        $this->assertSame(PhotoSubmissionStatus::InPreparation, $prep->fresh()->status);
        $this->assertSame(PhotoSubmissionStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame('No ball', $rejected->fresh()->rejection_reason);
        $this->assertSame(1, Challenge::count());

        // Allowed next steps drive the buttons.
        $this->assertSame(['in_review', 'in_preparation', 'rejected', 'converted'], $this->service()->allowedNext($this->submitPhoto($club, 4)));
        $this->assertSame([], $this->service()->allowedNext($converted->fresh()));
    }

    public function test_reject_requires_a_reason(): void
    {
        $s = $this->submitPhoto($this->makeClub());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $this->service()->reject($s, $this->admin, '  ');
        } finally {
            $this->assertSame(PhotoSubmissionStatus::Pending, $s->fresh()->status);
        }
    }

    // ------------------------------------------------------------ convert + packs

    public function test_convert_without_a_pack_creates_a_private_club_challenge(): void
    {
        $club = $this->makeClub();
        $s = $this->submitPhoto($club);

        $result = $this->service()->convert($s, $this->admin, $this->challengeData(['status' => 'draft']), $this->jpeg(50));

        $challenge = $result['challenge'];
        $this->assertNull($result['pack']);
        $this->assertSame([$club->id, 'club', 'draft', 0.432], [$challenge->club_id, $challenge->usage_pool, $challenge->status, (float) $challenge->ball_x_ratio]);
        $this->assertSame(0, $challenge->packs()->count());
        $this->assertSame($challenge->id, $result['submission']->challenge_id);
        $this->assertTrue(Challenge::publicContent()->whereKey($challenge->id)->doesntExist());
        $this->assertSame(1, ClubAuditEvent::where('action', 'challenge.created')->where('subject_id', $challenge->id)->count());
    }

    public function test_pack_attach_refuses_other_clubs_public_archived_and_locked_packs(): void
    {
        $club  = $this->makeClub();
        $other = $this->makeClub();
        $s = $this->submitPhoto($club);

        $foreign  = $this->makeClubPack($other, 1);
        $public   = $this->makePublicPack(1);
        $archived = $this->makeClubPack($club, 1, ['status' => 'archived']);
        $locked   = $this->makeClubPack($club, 1);
        $this->makeCompetition($club, [$locked], ['state' => CompetitionState::Published, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $challengesBefore = Challenge::count();

        foreach ([$foreign->id => 'photo_pack_invalid', $public->id => 'photo_pack_invalid', $archived->id => 'photo_pack_invalid',
                  999999 => 'photo_pack_invalid', $locked->id => 'competition_rules_locked'] as $packId => $code) {
            $this->assertDomainError($code, fn () => $this->service()->convert($s, $this->admin, $this->challengeData(), $this->jpeg(60), $packId));
        }

        $this->assertSame($challengesBefore, Challenge::count());
        $this->assertSame(PhotoSubmissionStatus::Pending, $s->fresh()->status);
        $this->assertSame([$s->path], Storage::disk('club_private')->allFiles(), 'no orphan challenge files');
        $this->assertSame(1, $foreign->challenges()->count());

        // An upcoming (not yet locked) competition pack is fine.
        $upcoming = $this->makeClubPack($club, 1);
        $this->makeCompetition($club, [$upcoming], ['state' => CompetitionState::Published, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);
        $result = $this->service()->convert($s, $this->admin, $this->challengeData(), $this->jpeg(61), $upcoming->id);
        $this->assertSame($upcoming->id, $result['pack']->id);
        $this->assertSame(2, $upcoming->challenges()->count());
    }

    public function test_cross_club_pack_attach_is_refused_over_http(): void
    {
        $club = $this->makeClub();
        $foreign = $this->makeClubPack($this->makeClub(), 1);
        $s = $this->submitPhoto($club);

        $this->actingAs($this->admin)->from(route('admin.club-photos.prepare', $s))
            ->post(route('admin.club-photos.convert', $s), $this->challengeData(['hidden_image' => $this->jpeg(70), 'pack_id' => $foreign->id]))
            ->assertRedirect(route('admin.club-photos.prepare', $s))
            ->assertSessionHas('error', __('clubs.errors.photo_pack_invalid'));
        $this->assertSame(PhotoSubmissionStatus::Pending, $s->fresh()->status);
    }

    // ------------------------------------------------------------ withdraw

    public function test_withdraw_deletes_a_pending_club_upload_and_keeps_the_audit_row(): void
    {
        $club = $this->makeClub();
        $clubAdmin = User::factory()->create();
        $s = $this->submitPhoto($club, 1, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, $clubAdmin);
        $path = $s->path;

        $this->service()->withdraw($s, $clubAdmin, 'club_admin');

        $this->assertNull(ClubPhotoSubmission::find($s->id));
        Storage::disk('club_private')->assertMissing($path);
        $event = ClubAuditEvent::where('action', 'photo.withdrawn')->firstOrFail();
        $this->assertSame([$s->id, 'club_admin', $clubAdmin->id], [$event->subject_id, $event->actor_kind, $event->actor_user_id]);

        // Same photo may be submitted again after a withdrawal.
        $this->submitPhoto($club, 1, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, $clubAdmin);
    }

    public function test_withdraw_is_refused_once_bp_picked_it_up_or_for_admin_uploads(): void
    {
        $club = $this->makeClub();
        $clubAdmin = User::factory()->create();

        $adminUpload = $this->submitPhoto($club, 1);
        $inReview = $this->service()->markInReview($this->submitPhoto($club, 2, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, $clubAdmin), $this->admin);
        $assigned = $this->service()->assign($this->submitPhoto($club, 3, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, $clubAdmin), $this->admin);

        foreach ([$adminUpload, $inReview, $assigned] as $s) {
            $this->assertDomainError('photo_status_invalid', fn () => $this->service()->withdraw($s, $clubAdmin));
            $this->assertNotNull($s->fresh());
            Storage::disk('club_private')->assertExists($s->path);
        }
    }

    // ------------------------------------------------------------ queries

    public function test_queue_filters_order_and_counts(): void
    {
        $a = $this->makeClub(['name' => 'A Club']);
        $b = $this->makeClub(['name' => 'B Club']);
        $colleague = User::factory()->create(['is_admin' => true]);

        $this->travel(-3)->days();
        $oldest = $this->submitPhoto($a, 1);
        $this->travelBack();
        $mine = $this->service()->assign($this->submitPhoto($b, 2), $this->admin);
        $theirs = $this->service()->markInReview($this->submitPhoto($a, 3), $colleague);
        $prep = $this->service()->startPreparation($this->submitPhoto($b, 4), $this->admin);
        $rejected = $this->service()->reject($this->submitPhoto($a, 5), $this->admin, 'Nope');

        $ids = fn (array $f) => $this->service()->queue($f, $this->admin)->pluck('id')->all();

        $this->assertSame([$oldest->id, $mine->id, $theirs->id, $prep->id], $ids([]), 'default = open, oldest first');
        $this->assertSame([$oldest->id, $mine->id, $theirs->id], $ids(['status' => 'awaiting']));
        $this->assertSame([$rejected->id], $ids(['status' => 'rejected']));
        $this->assertCount(5, $ids(['status' => 'all']));
        $this->assertSame([$oldest->id, $theirs->id], $ids(['club' => (string) $a->id]));
        $this->assertSame([$mine->id, $prep->id], $ids(['assignee' => 'me']));
        $this->assertSame([$oldest->id], $ids(['assignee' => 'unassigned']));
        $this->assertSame([$theirs->id], $ids(['assignee' => (string) $colleague->id]));
        $this->assertSame([$oldest->id, $mine->id, $theirs->id, $prep->id], $ids(['status' => 'bogus']));

        $this->assertSame(['pending' => 2, 'in_review' => 1, 'in_preparation' => 1, 'converted' => 0, 'rejected' => 1], $this->service()->statusCounts());
        $this->assertSame(['pending' => 1, 'in_review' => 1, 'in_preparation' => 0, 'converted' => 0, 'rejected' => 1], $this->service()->statusCounts($a));
        $this->assertSame(3, $this->service()->awaitingReviewCount());
        $this->assertSame([$rejected->id, $theirs->id, $oldest->id], $this->service()->forClub($a)->pluck('id')->all(), 'club list newest first');
        $this->assertSame([$theirs->id], $this->service()->forClub($a, 'in_review')->pluck('id')->all());
    }
}
