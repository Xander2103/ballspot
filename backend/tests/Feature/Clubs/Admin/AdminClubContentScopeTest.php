<?php

namespace Tests\Feature\Clubs\Admin;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\ClubAuditEvent;
use App\Models\DailyChallenge;
use App\Models\User;
use App\Services\MediaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * G6 + invariants §4.8: club content in the existing admin screens.
 *  - club challenges are stored on the private disk (never public) with
 *    usage_pool `club`, can never become public and never be a Daily;
 *  - the challenge / pack lists default to BallPicker content with an
 *    explicit club scope;
 *  - pack challenge pickers and saves enforce the club invariant server-side;
 *  - a club pack's trophy badge carries badges.club_id;
 *  - media export excludes club content unless explicitly asked.
 */
class AdminClubContentScopeTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function challengePayload(array $overrides = []): array
    {
        return array_merge([
            'title'        => 'Derby goal',
            'difficulty'   => 'medium',
            'status'       => 'active',
            'usage_pool'   => 'general',
            'sport_id'     => $this->clubSport()->id,
            'ball_x_ratio' => '0.4',
            'ball_y_ratio' => '0.6',
            'hidden_image' => UploadedFile::fake()->image('hidden.jpg', 800, 600),
            'original_image' => UploadedFile::fake()->image('original.png', 800, 600),
        ], $overrides);
    }

    // ------------------------------------------------------------ challenges

    public function test_club_challenge_images_go_to_the_private_disk_only(): void
    {
        Storage::fake('public');
        Storage::fake('club_private');
        $club = $this->makeClub(['name' => 'Private FC']);

        $this->actingAs($this->admin)->post('/admin/challenges', $this->challengePayload(['club_id' => $club->id]))
            ->assertRedirect('/admin/challenges?club=' . $club->id)
            ->assertSessionHas('success');

        $challenge = Challenge::firstOrFail();
        $this->assertSame($club->id, $challenge->club_id);
        $this->assertSame(Challenge::POOL_CLUB, $challenge->usage_pool, 'the submitted pool is ignored for club content');
        $this->assertStringStartsWith($club->id . '/challenges/hidden/', $challenge->hidden_image_path);
        $this->assertStringStartsWith($club->id . '/challenges/original/', $challenge->original_image_path);
        Storage::disk('club_private')->assertExists([$challenge->hidden_image_path, $challenge->original_image_path]);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'club images never touch the public disk');

        // Replacing an image keeps it private and deletes the old file; the owner cannot be changed.
        $oldHidden = $challenge->hidden_image_path;
        $this->actingAs($this->admin)->patch('/admin/challenges/' . $challenge->id, [
            'title' => 'Derby goal 2', 'difficulty' => 'hard', 'status' => 'active', 'usage_pool' => 'general',
            'ball_x_ratio' => '0.5', 'ball_y_ratio' => '0.5', 'club_id' => '',
            'hidden_image' => UploadedFile::fake()->image('new.jpg', 640, 480),
        ])->assertRedirect('/admin/challenges?club=' . $club->id);

        $fresh = $challenge->fresh();
        $this->assertSame($club->id, $fresh->club_id);
        $this->assertSame(Challenge::POOL_CLUB, $fresh->usage_pool);
        Storage::disk('club_private')->assertMissing($oldHidden);
        Storage::disk('club_private')->assertExists($fresh->hidden_image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Admin preview/edit use signed media URLs, never /storage.
        foreach (['/admin/challenges/' . $challenge->id . '/preview', '/admin/challenges/' . $challenge->id . '/edit'] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('/media/clubs/', $html);
            $this->assertStringContainsString('signature=', $html);
            $this->assertStringNotContainsString('/storage/' . $fresh->hidden_image_path, $html);
        }
        $this->actingAs($this->admin)->get('/admin/challenges/' . $challenge->id . '/edit')->assertSee('Club: Private FC')->assertDontSee('name="usage_pool"', false);

        // Deleting an unplayed club challenge removes its private files.
        $this->actingAs($this->admin)->delete('/admin/challenges/' . $challenge->id)->assertRedirect('/admin/challenges');
        $this->assertSame([], Storage::disk('club_private')->allFiles());
    }

    public function test_ballpicker_challenges_are_unchanged(): void
    {
        Storage::fake('public');
        Storage::fake('club_private');

        $this->actingAs($this->admin)->post('/admin/challenges', $this->challengePayload())->assertRedirect('/admin/challenges');

        $challenge = Challenge::firstOrFail();
        $this->assertNull($challenge->club_id);
        $this->assertSame('general', $challenge->usage_pool);
        Storage::disk('public')->assertExists($challenge->hidden_image_path);
        $this->assertSame([], Storage::disk('club_private')->allFiles());
    }

    public function test_set_as_daily_is_refused_for_club_challenges(): void
    {
        $challenge = $this->makeClubChallenge($this->makeClub(), ['usage_pool' => Challenge::POOL_GENERAL]);

        $this->actingAs($this->admin)->from('/admin/challenges')
            ->post('/admin/challenges/' . $challenge->id . '/set-as-daily', ['date' => now()->addDay()->toDateString()])
            ->assertRedirect('/admin/challenges')
            ->assertSessionHas('error', "\"{$challenge->title}\" is a club challenge. Club photos are private to their club and can never be scheduled as a Daily.");
        $this->assertSame(0, DailyChallenge::count());
    }

    public function test_challenge_list_defaults_to_ballpicker_with_an_explicit_club_scope(): void
    {
        $a = $this->makeClub(['name' => 'Alpha FC']);
        $b = $this->makeClub(['name' => 'Beta FC']);
        $this->makePublicChallenge(['title' => 'Public One']);
        $this->makeClubChallenge($a, ['title' => 'Alpha Photo']);
        $this->makeClubChallenge($b, ['title' => 'Beta Photo']);

        $titles = fn (string $q) => $this->actingAs($this->admin)->get('/admin/challenges' . $q)->assertOk()
            ->viewData('challenges')->pluck('title')->sort()->values()->all();

        $this->assertSame(['Public One'], $titles(''));
        $this->assertSame(['Alpha Photo', 'Beta Photo'], $titles('?club=all'));
        $this->assertSame(['Alpha Photo'], $titles('?club=' . $a->id));
        $this->assertSame(['Public One'], $titles('?club=nonsense'));

        $default = $this->actingAs($this->admin)->get('/admin/challenges');
        $this->assertSame(1, $default->viewData('summary')['total'], 'summary tiles count BallPicker content only');
        $this->assertSame(2, $default->viewData('summary')['club']);
        $default->assertSee('Scope: BallPicker')->assertSee('Club: all clubs (2)')->assertSee('Club: Alpha FC');

        $scoped = $this->actingAs($this->admin)->get('/admin/challenges?club=' . $a->id)->getContent();
        $this->assertStringContainsString('Club: Alpha FC</span>', $scoped, 'club badge on the row');
        $this->assertStringContainsString('+ New club challenge', $scoped);
        $this->assertStringContainsString('href="/admin/challenges/create?club=' . $a->id . '"', $scoped);
        $this->assertStringNotContainsString('data-section="segments"', $scoped, 'pool segments are BallPicker-only');
        $this->assertStringContainsString('<input type="hidden" name="club" value="' . $a->id . '">', $scoped, 'sorting keeps the scope');

        $this->actingAs($this->admin)->get('/admin/challenges/create?club=' . $a->id)->assertOk()
            ->assertSee('<option value="' . $a->id . '" selected>Club: Alpha FC</option>', false);
    }

    // ------------------------------------------------------------ packs

    public function test_club_pack_create_preset_audit_and_club_trophy(): void
    {
        $club = $this->makeClub(['name' => 'Pack FC']);

        $this->actingAs($this->admin)->get('/admin/packs/create?club=' . $club->id)->assertOk()
            ->assertSee('<option value="' . $club->id . '" selected>Club: Pack FC</option>', false);

        $this->actingAs($this->admin)->post('/admin/packs', [
            'name' => 'Autumn Pack', 'status' => 'active', 'visibility' => 'public', 'club_id' => $club->id, 'award_completion_trophy' => '1',
        ])->assertRedirect();

        $pack = ChallengePack::where('name', 'Autumn Pack')->firstOrFail();
        $this->assertSame($club->id, $pack->club_id);
        $this->assertSame($club->id, Badge::findOrFail($pack->completion_badge_id)->club_id, 'club trophy');
        $this->assertSame(1, $club->fresh()->active_packs_count);
        $this->assertSame(1, ClubAuditEvent::where('club_id', $club->id)->where('action', 'pack.created')->count());
        $this->assertSame(0, Badge::global()->where('code', 'pack_' . $pack->id . '_completed')->count());

        // A public pack's trophy stays global.
        $this->actingAs($this->admin)->post('/admin/packs', ['name' => 'Public Pack', 'status' => 'active', 'visibility' => 'public', 'award_completion_trophy' => '1']);
        $public = ChallengePack::where('name', 'Public Pack')->firstOrFail();
        $this->assertNull($public->club_id);
        $this->assertNull(Badge::findOrFail($public->completion_badge_id)->club_id);

        // Archiving is audited and refreshes the counters; the owner can never change.
        $this->actingAs($this->admin)->post('/admin/packs/' . $pack->id . '/status', ['status' => 'archived'])->assertRedirect('/admin/packs?club=' . $club->id);
        $this->assertSame(1, ClubAuditEvent::where('action', 'pack.archived')->count());
        $this->assertSame(0, $club->fresh()->active_packs_count);

        $this->actingAs($this->admin)->from('/admin/packs/' . $public->id . '/edit')->put('/admin/packs/' . $public->id, [
            'name' => 'Public Pack', 'status' => 'active', 'visibility' => 'public', 'club_id' => $club->id,
        ])->assertSessionHasErrors('club_id');
        $this->assertNull($public->fresh()->club_id);
    }

    public function test_pack_challenge_invariant_is_enforced_server_side(): void
    {
        $club  = $this->makeClub(['name' => 'Own FC']);
        $other = $this->makeClub(['name' => 'Other FC']);
        $own        = $this->makeClubChallenge($club, ['title' => 'Own Photo']);
        $foreign    = $this->makeClubChallenge($other, ['title' => 'Foreign Photo']);
        $ballpicker = $this->makePublicChallenge(['title' => 'BallPicker Photo']);

        $clubPack   = ChallengePack::create(['name' => 'Club Pack', 'slug' => 'club-pack-x', 'status' => 'draft', 'visibility' => 'public', 'club_id' => $club->id]);
        $publicPack = ChallengePack::create(['name' => 'Open Pack', 'slug' => 'open-pack-x', 'status' => 'draft', 'visibility' => 'public']);
        $put = fn (ChallengePack $p, array $ids) => $this->actingAs($this->admin)->from('/admin/packs/' . $p->id . '/edit')
            ->put('/admin/packs/' . $p->id, ['name' => $p->name, 'slug' => $p->slug, 'status' => 'draft', 'visibility' => 'public', 'challenges' => $ids]);

        // Picker lists: club pack = own club + BallPicker; public pack = BallPicker only.
        $this->assertEqualsCanonicalizing(['Own Photo', 'BallPicker Photo'], $this->actingAs($this->admin)->get('/admin/packs/' . $clubPack->id . '/edit')->viewData('available')->pluck('title')->all());
        $this->assertSame(['BallPicker Photo'], $this->actingAs($this->admin)->get('/admin/packs/' . $publicPack->id . '/edit')->viewData('available')->pluck('title')->all());

        // Club pack: own + BallPicker challenges (D4) are fine.
        $put($clubPack, [$own->id, $ballpicker->id])->assertRedirect('/admin/packs?club=' . $club->id);
        $this->assertSame([$own->id, $ballpicker->id], $clubPack->challenges()->orderBy('challenge_pack_challenge.sort_order')->pluck('challenges.id')->all());
        $this->assertSame(1, ClubAuditEvent::where('action', 'pack.updated')->count());

        // Another club's challenge → validation error, nothing changes.
        $put($clubPack, [$own->id, $foreign->id])
            ->assertSessionHasErrors(['challenges' => 'A club pack can only contain challenges of its own club or BallPicker challenges.']);
        $this->assertSame(2, $clubPack->challenges()->count());
        $this->assertFalse($clubPack->challenges()->whereKey($foreign->id)->exists());

        // Public pack + any club challenge → validation error.
        $put($publicPack, [$ballpicker->id, $own->id])
            ->assertSessionHasErrors(['challenges' => 'A BallPicker pack can only contain BallPicker challenges — club challenges stay private to their club.']);
        $this->assertSame(0, $publicPack->challenges()->count());

        // Unknown ids are refused too.
        $put($publicPack, [999999])->assertSessionHasErrors('challenges');
        $put($publicPack, [$ballpicker->id])->assertRedirect('/admin/packs');
        $this->assertSame(1, $publicPack->challenges()->count());
    }

    public function test_pack_list_defaults_to_ballpicker_with_club_scope_and_owner_column(): void
    {
        $club = $this->makeClub(['name' => 'Lister FC']);
        $this->makeClubPack($club, 1, ['name' => 'Club List Pack']);
        $this->makePublicPack(1, ['name' => 'Public List Pack']);

        $names = fn (string $q) => $this->actingAs($this->admin)->get('/admin/packs' . $q)->assertOk()->viewData('packs')->pluck('name')->all();

        $this->assertSame(['Public List Pack'], $names(''));
        $this->assertSame(['Club List Pack'], $names('?club=all'));
        $this->assertSame(['Club List Pack'], $names('?club=' . $club->id));

        $html = $this->actingAs($this->admin)->get('/admin/packs?club=' . $club->id)->getContent();
        $this->assertStringContainsString('Club: Lister FC</a>', $html);
        $this->assertStringContainsString('+ New club pack', $html);
        $default = $this->actingAs($this->admin)->get('/admin/packs')->getContent();
        $this->assertStringContainsString('Content only — no prices', $default);
        $this->assertStringNotContainsString('â€', $default, 'no mojibake');
    }

    // ------------------------------------------------------------ media export

    public function test_media_export_excludes_club_content_by_default(): void
    {
        Storage::fake('public');
        Storage::fake('club_private');
        $club = $this->makeClub(['name' => 'Export FC', 'slug' => 'export-fc']);
        $public = $this->makePublicChallenge(['title' => 'Public Export']);
        $private = $this->makeClubChallenge($club, ['title' => 'Club Export']);
        Storage::disk('public')->put($public->hidden_image_path, 'x');
        Storage::disk('club_private')->put($private->hidden_image_path, 'yy');
        Storage::disk('club_private')->put($private->original_image_path, 'zzz');

        $service = app(MediaExportService::class);

        $this->assertSame(['Public Export'], $service->preview([])->pluck('title')->all());
        $this->assertSame(1, $service->summary([])['challenges']);
        $this->assertSame(['Club Export'], $service->preview(['club' => 'all'])->pluck('title')->all());
        $this->assertSame(['Club Export'], $service->preview(['club' => (string) $club->id])->pluck('title')->all());
        $this->assertSame([], $service->preview(['club' => (string) ($club->id + 99)])->pluck('title')->all());

        $clubSummary = $service->summary(['club' => 'all']);
        $this->assertSame([1, 1, 1, 0, 5], [$clubSummary['challenges'], $clubSummary['guess_images'], $clubSummary['reveal_images'], $clubSummary['missing'], $clubSummary['bytes']],
            'club images are resolved on the private club disk');
        $this->assertNull($service->resolve('../' . $private->hidden_image_path, 'club_private'));

        $meta = $service->metadata($private->load(['sport', 'category', 'subcategories', 'packs', 'dailyChallenges', 'club']));
        $this->assertSame([$club->id, 'Export FC'], [$meta['club_id'], $meta['club']]);

        $page = $this->actingAs($this->admin)->get('/admin/media-export')->assertOk();
        $page->assertSee('BallPicker content only (default — club photos excluded)')->assertSee('Club content: Export FC');
        $this->assertSame(['Public Export'], $page->viewData('preview')->pluck('title')->all());
        $this->assertSame(['Club Export'], $this->actingAs($this->admin)->get('/admin/media-export?club=' . $club->id)->viewData('preview')->pluck('title')->all());
    }
}
