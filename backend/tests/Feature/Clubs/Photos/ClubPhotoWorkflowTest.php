<?php

namespace Tests\Feature\Clubs\Photos;

use App\Enums\ClubRole;
use App\Enums\PhotoSubmissionStatus;
use App\Models\Challenge;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * AT-ADM-08 end to end over the real admin + member routes:
 * admin upload → assign → in review → prepare → convert (club challenge with
 * private images, attached to a club pack) → a member plays it with signed
 * image URLs; an unsigned media URL is refused. Reject stores the reason.
 */
class ClubPhotoWorkflowTest extends ClubPhotoTestCase
{
    public function test_at_adm_08_upload_assign_prepare_convert_and_play(): void
    {
        $this->enableClubs();
        $owner  = User::factory()->create();
        $club   = $this->makeClub(['name' => 'KFC Example'], $owner);
        $player = User::factory()->create();
        $this->addMember($club, $player, ClubRole::Member);
        $pack = $this->makeClubPack($club, 1, ['name' => 'Autumn pack']);

        // 1. Upload on behalf of the club (multi-file) from the Photos tab.
        $this->actingAs($this->admin)->post(route('admin.clubs.photos.store', $club), [
            'photos'           => [$this->jpeg(1, 800, 600), $this->jpeg(2, 800, 600)],
            'club_note'        => 'derby goal min 89',
            'rights_confirmed' => '1',
        ])->assertRedirect(route('admin.clubs.show', ['club' => $club, 'tab' => 'photos']))
          ->assertSessionHas('success', '2 photos added to the review queue.');

        $this->assertSame(2, ClubPhotoSubmission::where('club_id', $club->id)->count());
        $submission = ClubPhotoSubmission::where('club_id', $club->id)->orderBy('id')->first();
        $this->assertSame(PhotoSubmissionStatus::Pending, $submission->status);
        $this->assertSame('admin_upload', $submission->source);
        $this->assertNotNull($submission->rights_confirmed_at);
        Storage::disk('club_private')->assertExists($submission->path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Queue: oldest first, thumbnails are signed media URLs, badge = pending + in review.
        $queue = $this->actingAs($this->admin)->get(route('admin.club-photos.index'))->assertOk();
        $queue->assertSeeInOrder(['#' . $submission->id, '#' . ($submission->id + 1)])
              ->assertSee('KFC Example')->assertSee('rights ✓')->assertSee('derby goal min 89')
              ->assertSee('/media/clubs/', false)->assertSee('signature=', false)
              ->assertSee('Prepare challenge →')->assertSee('title="2 open"', false);

        // 2. Assign to me → shows under the "Me" filter.
        $this->actingAs($this->admin)->post(route('admin.club-photos.assign', $submission))->assertRedirect();
        $this->assertSame($this->admin->id, $submission->fresh()->assigned_to_user_id);
        $mine = $this->actingAs($this->admin)->get(route('admin.club-photos.index', ['assignee' => 'me']))->assertOk();
        $mine->assertSee('data-submission="' . $submission->id . '"', false)
             ->assertDontSee('data-submission="' . ($submission->id + 1) . '"', false);

        // 3. In review → in preparation via the prepare page.
        $this->actingAs($this->admin)->post(route('admin.club-photos.in-review', $submission))->assertRedirect();
        $this->assertSame(PhotoSubmissionStatus::InReview, $submission->fresh()->status);
        $this->actingAs($this->admin)->post(route('admin.club-photos.start-preparation', $submission))
            ->assertRedirect(route('admin.club-photos.prepare', $submission));
        $this->assertSame(PhotoSubmissionStatus::InPreparation, $submission->fresh()->status);

        $prepare = $this->actingAs($this->admin)->get(route('admin.club-photos.prepare', $submission))->assertOk();
        $prepare->assertSee('Download original')->assertSee('Create club challenge')->assertSee('Autumn pack')
                ->assertSee('/media/clubs/', false)->assertSee('Photo preparation started');

        // Download the original for offline ball removal.
        $download = $this->actingAs($this->admin)->get(route('admin.club-photos.download', $submission))->assertOk();
        $this->assertStringContainsString('club-' . $club->id . '-photo-' . $submission->id . '.jpg', (string) $download->headers->get('content-disposition'));

        // 4. Convert: ball-removed image + ball position + pack.
        $this->actingAs($this->admin)->post(route('admin.club-photos.convert', $submission), [
            'hidden_image' => $this->jpeg(99, 800, 600, false, 'removed.jpg'),
            'title'        => 'Derby goal',
            'difficulty'   => 'hard',
            'status'       => 'active',
            'ball_x_ratio' => '0,4321',
            'ball_y_ratio' => '0.65',
            'tags'         => 'derby, goal',
            'pack_id'      => $pack->id,
        ])->assertRedirect(route('admin.club-photos.prepare', $submission))->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(PhotoSubmissionStatus::Converted, $submission->status);
        $this->assertSame($this->admin->id, $submission->reviewed_by_user_id);
        $challenge = Challenge::findOrFail($submission->challenge_id);
        $this->assertSame($club->id, $challenge->club_id);
        $this->assertSame(Challenge::POOL_CLUB, $challenge->usage_pool);
        $this->assertSame(['hard', 'active', 0.432, 0.65], [$challenge->difficulty, $challenge->status, (float) $challenge->ball_x_ratio, (float) $challenge->ball_y_ratio]);
        $this->assertSame(['derby', 'goal'], $challenge->tags()->orderBy('name')->pluck('name')->all());

        // Private files only: hidden + original (a COPY — the submission keeps its own file).
        $this->assertStringStartsWith($club->id . '/challenges/hidden/', $challenge->hidden_image_path);
        $this->assertStringStartsWith($club->id . '/challenges/original/', $challenge->original_image_path);
        Storage::disk('club_private')->assertExists([$challenge->hidden_image_path, $challenge->original_image_path, $submission->path]);
        $this->assertSame(
            Storage::disk('club_private')->get($submission->path),
            Storage::disk('club_private')->get($challenge->original_image_path),
        );
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Appended to the club pack after its existing challenge.
        $this->assertSame($challenge->id, $pack->challenges()->get()->last()->id);

        // Audit trail: uploaded, assigned, in review, preparation, challenge.created, pack.updated, converted.
        $actions = ClubAuditEvent::where('club_id', $club->id)->orderBy('id')->pluck('action')->all();
        foreach (['photo.uploaded', 'photo.assigned', 'photo.in_review', 'photo.preparation_started', 'challenge.created', 'pack.updated', 'photo.converted'] as $action) {
            $this->assertContains($action, $actions);
        }

        // Converted page links to the challenge and the pack.
        $done = $this->actingAs($this->admin)->get(route('admin.club-photos.prepare', $submission))->assertOk();
        $done->assertSee('Converted.')->assertSee(route('challenges.edit', $challenge), false)
             ->assertSee(route('admin.packs.edit', $pack), false)->assertSee('Autumn pack')->assertDontSee('Create club challenge');

        // 5. A member starts the club pack via the member API and gets signed URLs.
        $token = $this->clubToken($player);
        $start = $this->actingWithToken($token)->postJson('/api/clubs/' . $club->public_id . '/packs/' . $pack->slug . '/start')->assertOk();
        $attemptId = $start->json('attempt.id');
        $hiddenUrl = $start->json('challenge.hidden_image_url');
        $this->assertStringContainsString('/media/clubs/', $hiddenUrl);
        $this->assertStringContainsString('signature=', $hiddenUrl);
        $this->assertStringNotContainsString('/storage/', $hiddenUrl);

        // Play the first (fixture) challenge; the next one is the converted photo.
        $next = $this->actingWithToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $start->json('challenge.id'), 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ])->assertOk();
        $this->assertSame($challenge->id, $next->json('next_challenge.id'));
        $convertedUrl = $next->json('next_challenge.hidden_image_url');
        $this->assertStringContainsString(basename($challenge->hidden_image_path), urldecode($convertedUrl));

        // The signed URL streams the private file; without the signature it is refused.
        $relative = parse_url($convertedUrl, PHP_URL_PATH) . '?' . parse_url($convertedUrl, PHP_URL_QUERY);
        $this->get($relative)->assertOk();
        $this->get(parse_url($convertedUrl, PHP_URL_PATH))->assertForbidden();
        $this->assertNotSame(200, $this->get('/storage/' . $challenge->hidden_image_path)->getStatusCode());
    }

    public function test_reject_stores_the_reason_visible_to_the_club(): void
    {
        $club = $this->makeClub(['name' => 'Reject FC']);
        $submission = $this->submitPhoto($club);

        $this->actingAs($this->admin)->post(route('admin.club-photos.reject', $submission), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(PhotoSubmissionStatus::Pending, $submission->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.club-photos.reject', $submission), ['reason' => 'Ball not visible'])
            ->assertRedirect()->assertSessionHas('success');

        $fresh = $submission->fresh();
        $this->assertSame(PhotoSubmissionStatus::Rejected, $fresh->status);
        $this->assertSame('Ball not visible', $fresh->rejection_reason);
        $this->assertSame($this->admin->id, $fresh->reviewed_by_user_id);
        $this->assertNotNull($fresh->reviewed_at);
        $event = ClubAuditEvent::where('action', 'photo.rejected')->firstOrFail();
        $this->assertSame('Ball not visible', $event->metadata['reason']);

        // Visible on the club tab and the queue (rejected filter); no further actions.
        $this->actingAs($this->admin)->get(route('admin.clubs.show', ['club' => $club, 'tab' => 'photos']))->assertSee('Ball not visible');
        $this->actingAs($this->admin)->get(route('admin.club-photos.index', ['status' => 'rejected']))->assertSee('Rejected: Ball not visible');
        $this->actingAs($this->admin)->get(route('admin.club-photos.prepare', $submission))->assertOk()
            ->assertSee('Reason (visible to the club): Ball not visible')->assertDontSee('Create club challenge');

        // Converting a rejected photo is refused with the domain error.
        $this->actingAs($this->admin)->from(route('admin.club-photos.prepare', $submission))
            ->post(route('admin.club-photos.convert', $submission), $this->challengeData(['hidden_image' => $this->jpeg(5)]))
            ->assertRedirect(route('admin.club-photos.prepare', $submission))
            ->assertSessionHas('error', __('clubs.errors.photo_status_invalid'));
        $this->assertNull($submission->fresh()->challenge_id);
        $this->assertSame([$submission->path], Storage::disk('club_private')->allFiles());
    }

    public function test_multi_file_upload_reports_duplicates_and_invalid_files_per_file(): void
    {
        $club = $this->makeClub();
        $this->submitPhoto($club, 7);

        $this->actingAs($this->admin)->post(route('admin.clubs.photos.store', $club), [
            'photos' => [
                $this->jpeg(7, 640, 480, true, 'again.jpg'),          // duplicate of the existing photo
                UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
                $this->jpeg(8, 640, 480, true, 'fresh.jpg'),
            ],
        ])->assertRedirect()
          ->assertSessionHas('success', '1 photo added to the review queue.')
          ->assertSessionHas('warning', fn ($w) => str_contains($w, 'again.jpg: ' . __('clubs.errors.photo_duplicate'))
              && str_contains($w, 'notes.pdf: ' . __('clubs.errors.photo_invalid')));

        $this->assertSame(2, ClubPhotoSubmission::where('club_id', $club->id)->count());
        $this->assertCount(2, Storage::disk('club_private')->allFiles());
    }

    public function test_convert_validation_errors_keep_the_submission_and_leave_no_files(): void
    {
        $club = $this->makeClub();
        $submission = $this->submitPhoto($club);

        $this->actingAs($this->admin)->post(route('admin.club-photos.convert', $submission), [
            'title' => 'No image', 'difficulty' => 'easy', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
        ])->assertSessionHasErrors('hidden_image');

        $this->actingAs($this->admin)->post(route('admin.club-photos.convert', $submission), [
            'hidden_image' => $this->jpeg(3), 'title' => '', 'difficulty' => 'extreme', 'ball_x_ratio' => 1.5, 'ball_y_ratio' => 0.5,
        ])->assertSessionHasErrors(['title', 'difficulty', 'ball_x_ratio']);

        $this->assertSame(PhotoSubmissionStatus::Pending, $submission->fresh()->status);
        $this->assertSame(0, Challenge::count());
        $this->assertSame([$submission->path], Storage::disk('club_private')->allFiles());
    }
}
