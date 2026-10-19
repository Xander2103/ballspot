<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubRole;
use App\Enums\PhotoSubmissionStatus;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use App\Services\Clubs\ClubPhotoReviewService;

/**
 * Portal photos: multi-upload with the mandatory rights confirmation lands
 * in BallPicker's admin queue as source club_upload (actor club_admin);
 * statuses + rejection reasons are shown; pending uploads can be withdrawn.
 */
class PortalPhotosTest extends PortalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole(ClubRole::Admin, null, ['username' => 'photo_admin']);
    }

    private function upload(array $files, array $extra = [])
    {
        return $this->portalAs($this->admin)->from($this->url('/photos'))
            ->post($this->url('/photos'), ['photos' => $files, 'club_note' => 'derby goal', 'rights_confirmed' => '1'] + $extra);
    }

    public function test_upload_ends_in_the_admin_queue_as_club_upload(): void
    {
        $this->upload([$this->jpeg(1), $this->jpeg(2)])
            ->assertRedirect($this->url('/photos'))
            ->assertSessionHas('success', '2 photos were sent to BallPicker.');

        $subs = ClubPhotoSubmission::where('club_id', $this->club->id)->get();
        $this->assertCount(2, $subs);
        foreach ($subs as $sub) {
            $this->assertSame(ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, $sub->source);
            $this->assertSame(PhotoSubmissionStatus::Pending, $sub->status);
            $this->assertSame($this->admin->id, $sub->uploaded_by_user_id);
            $this->assertNotNull($sub->rights_confirmed_at);
            $this->assertSame('derby goal', $sub->club_note);
        }
        $this->assertSame(2, ClubAuditEvent::where('action', 'photo.uploaded')->where('actor_kind', ClubAuditEvent::ACTOR_CLUB_ADMIN)->count());

        // BallPicker's review queue shows them.
        $platform = User::factory()->create(['is_admin' => true]);
        $this->actingAs($platform)->get('/admin/club-photos')->assertOk()->assertSee('Portal FC');
        $this->assertSame(2, app(ClubPhotoReviewService::class)->queue([], $platform)->total());
    }

    public function test_rights_confirmation_is_mandatory(): void
    {
        $this->portalAs($this->admin)->from($this->url('/photos'))
            ->post($this->url('/photos'), ['photos' => [$this->jpeg(3)]])
            ->assertSessionHasErrors('rights_confirmed');
        $this->assertSame(0, ClubPhotoSubmission::count());
    }

    public function test_bad_files_are_reported_and_duplicates_refused(): void
    {
        $this->upload([$this->jpeg(5)])->assertSessionHas('success');
        $this->upload([$this->jpeg(5), $this->jpeg(6)])
            ->assertSessionHas('success', '1 photo was sent to BallPicker.')
            ->assertSessionHas('warning');
        $this->assertSame(2, ClubPhotoSubmission::count());

        $this->upload([\Illuminate\Http\UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('photos.0');
    }

    public function test_status_list_shows_rejection_reason_and_withdraw(): void
    {
        $this->upload([$this->jpeg(7), $this->jpeg(8)]);
        [$a, $b] = ClubPhotoSubmission::orderBy('id')->get()->all();
        $platform = User::factory()->create(['is_admin' => true]);
        app(ClubPhotoReviewService::class)->reject($b, $platform, 'The ball is not visible.');

        $this->portalAs($this->admin)->get($this->url('/photos'))->assertOk()
            ->assertSee('Rejected')->assertSee('The ball is not visible.')
            ->assertSee('Withdraw');

        $this->portalAs($this->admin)->get($this->url('/photos?status=rejected'))->assertOk()->assertSee('The ball is not visible.');

        $this->portalAs($this->admin)->post($this->url('/photos/' . $a->id . '/withdraw'))->assertSessionHas('success');
        $this->assertNull(ClubPhotoSubmission::find($a->id));
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'photo.withdrawn')->firstOrFail()->actor_kind);

        // A rejected photo cannot be withdrawn.
        $this->portalAs($this->admin)->post($this->url('/photos/' . $b->id . '/withdraw'))
            ->assertSessionHas('error', __('clubs.errors.photo_status_invalid'));
        $this->assertNotNull(ClubPhotoSubmission::find($b->id));
    }

    public function test_photo_of_another_club_cannot_be_withdrawn(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->makeClub(['slug' => 'other-fc'], $otherOwner);
        $foreign = app(ClubPhotoReviewService::class)->submit($other, $this->jpeg(9), $otherOwner, ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, null, true, 'club_admin');

        $this->portalAs($this->owner)->post($this->url('/photos/' . $foreign->id . '/withdraw'))->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_member_cannot_upload(): void
    {
        $member = $this->userWithRole(ClubRole::Member);

        $this->portalAs($member)->post($this->url('/photos'), ['photos' => [$this->jpeg(10)], 'rights_confirmed' => '1'])->assertForbidden();
        $this->assertSame(0, ClubPhotoSubmission::count());
    }

    public function test_suspended_club_cannot_upload(): void
    {
        $this->club->forceFill(['status' => 'suspended'])->save();

        $this->upload([$this->jpeg(11)])->assertSessionHas('error', __('clubs.errors.club_unavailable'));
        $this->assertSame(0, ClubPhotoSubmission::count());
        $this->portalAs($this->admin)->get($this->url('/photos'))->assertOk()->assertDontSee('name="photos[]"', false);
    }
}
