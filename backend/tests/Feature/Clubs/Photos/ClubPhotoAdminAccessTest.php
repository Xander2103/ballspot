<?php

namespace Tests\Feature\Clubs\Photos;

use App\Enums\ClubRole;
use App\Enums\PhotoSubmissionStatus;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Authorization of every photo route (non-admins — even the club's own
 * owner — are refused in the central admin), the nav badge
 * (pending + in review) and the club-challenge audit gap closed by D1.
 */
class ClubPhotoAdminAccessTest extends ClubPhotoTestCase
{
    public static function routes(): array
    {
        return [
            'queue'             => ['GET', '/admin/club-photos'],
            'prepare'           => ['GET', '/admin/club-photos/{s}/prepare'],
            'download'          => ['GET', '/admin/club-photos/{s}/original'],
            'assign'            => ['POST', '/admin/club-photos/{s}/assign'],
            'in review'         => ['POST', '/admin/club-photos/{s}/in-review'],
            'start preparation' => ['POST', '/admin/club-photos/{s}/start-preparation'],
            'reject'            => ['POST', '/admin/club-photos/{s}/reject'],
            'convert'           => ['POST', '/admin/club-photos/{s}/convert'],
            'upload'            => ['POST', '/admin/clubs/{club}/photos'],
        ];
    }

    #[DataProvider('routes')]
    public function test_guests_and_non_admins_are_refused(string $method, string $template): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $s     = $this->submitPhoto($club);
        $uri   = str_replace(['{s}', '{club}'], [$s->id, $club->id], $template);
        $payload = ['reason' => 'Nope', 'photos' => [$this->jpeg(9)], 'hidden_image' => $this->jpeg(10)] + $this->challengeData();

        $this->call($method, $uri, $payload)->assertRedirect('/admin/login');

        // The club's own owner is not a platform admin.
        $this->addMember($club, $owner, ClubRole::Owner);
        $this->actingAs($owner)->call($method, $uri, $payload)->assertForbidden();

        $fresh = $s->fresh();
        $this->assertSame([PhotoSubmissionStatus::Pending, null, null], [$fresh->status, $fresh->assigned_to_user_id, $fresh->challenge_id]);
        $this->assertSame(1, ClubPhotoSubmission::count());
        $this->assertSame(1, ClubAuditEvent::count(), 'only the fixture upload was audited');
    }

    public function test_unknown_submission_is_404(): void
    {
        $this->actingAs($this->admin)->get('/admin/club-photos/999999/prepare')->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/club-photos/999999/assign')->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/clubs/999999/photos', ['photos' => [$this->jpeg(1)]])->assertNotFound();
    }

    public function test_nav_badge_counts_pending_and_in_review_only(): void
    {
        $club = $this->makeClub();
        $this->submitPhoto($club, 1);
        $this->service()->markInReview($this->submitPhoto($club, 2), $this->admin);
        $this->service()->startPreparation($this->submitPhoto($club, 3), $this->admin);
        $this->service()->reject($this->submitPhoto($club, 4), $this->admin, 'No ball');

        $html = $this->actingAs($this->admin)->get('/admin/club-photos')->assertOk()->getContent();
        $this->assertStringContainsString('>Photo review<span class="badge rounded-pill bg-danger ms-1" title="2 awaiting review">2</span></a>', $html);
        // Status pills show every bucket.
        $this->assertMatchesRegularExpression('/Open <span class="badge[^"]*">3<\/span>/', $html);
        $this->assertMatchesRegularExpression('/In preparation <span class="badge[^"]*">1<\/span>/', $html);
    }

    public function test_upload_validation_and_club_status(): void
    {
        $club = $this->makeClub(['status' => \App\Enums\ClubStatus::Archived]);

        $this->actingAs($this->admin)->post(route('admin.clubs.photos.store', $club), [])->assertSessionHasErrors('photos');
        $this->actingAs($this->admin)->post(route('admin.clubs.photos.store', $club), [
            'photos' => array_fill(0, 21, UploadedFile::fake()->image('x.jpg', 400, 400)),
        ])->assertSessionHasErrors('photos');

        $this->actingAs($this->admin)->post(route('admin.clubs.photos.store', $club), ['photos' => [$this->jpeg(1)]])
            ->assertSessionHas('error', __('clubs.errors.club_status_invalid'));
        $this->assertSame(0, ClubPhotoSubmission::count());
    }

    public function test_admin_challenge_form_audits_club_challenges(): void
    {
        $club = $this->makeClub();

        $this->actingAs($this->admin)->post('/admin/challenges', [
            'title' => 'Club form', 'difficulty' => 'easy', 'status' => 'active', 'club_id' => $club->id,
            'ball_x_ratio' => '0.5', 'ball_y_ratio' => '0.5',
            'hidden_image' => UploadedFile::fake()->image('h.jpg', 400, 300),
        ])->assertRedirect('/admin/challenges?club=' . $club->id);
        $challenge = \App\Models\Challenge::where('title', 'Club form')->firstOrFail();
        $this->assertSame(1, ClubAuditEvent::where('action', 'challenge.created')->where('subject_id', $challenge->id)->count());

        $this->actingAs($this->admin)->put('/admin/challenges/' . $challenge->id, [
            'title' => 'Club form 2', 'difficulty' => 'hard', 'status' => 'active', 'ball_x_ratio' => '0.4', 'ball_y_ratio' => '0.4',
        ])->assertRedirect();
        $updated = ClubAuditEvent::where('action', 'challenge.updated')->firstOrFail();
        $this->assertSame(['challenge_id' => $challenge->id, 'name' => 'Club form 2', 'status' => 'active', 'fields' => []], $updated->metadata);

        // BallPicker challenges never write club audit rows.
        $this->actingAs($this->admin)->post('/admin/challenges', [
            'title' => 'Public one', 'difficulty' => 'easy', 'status' => 'active', 'usage_pool' => 'general',
            'ball_x_ratio' => '0.5', 'ball_y_ratio' => '0.5', 'hidden_image' => UploadedFile::fake()->image('p.jpg', 400, 300),
        ])->assertRedirect('/admin/challenges');
        $this->assertSame(2, ClubAuditEvent::whereIn('action', ['challenge.created', 'challenge.updated'])->count());
    }
}
