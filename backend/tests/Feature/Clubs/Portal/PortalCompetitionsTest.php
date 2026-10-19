<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubRole;
use App\Enums\CompetitionState;
use App\Models\ChallengePack;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionEntry;
use App\Models\User;
use Carbon\Carbon;

/**
 * Portal competitions: create drafts from the club's active packs, edit
 * before the lock, publish, cancel before the start, extend after the start
 * (the only change once rules are locked), standings, and the platform-only
 * actions (void / rebuild / finalize) having no portal route.
 */
class PortalCompetitionsTest extends PortalTestCase
{
    private User $admin;
    private ChallengePack $pack;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $this->club->forceFill(['timezone' => 'Europe/Brussels'])->save();
        $this->admin = $this->userWithRole(ClubRole::Admin);
        $this->pack  = $this->makeClubPack($this->club, 3, ['name' => 'Autumn pack']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Form payload; window in club time (Europe/Brussels = UTC+1 in November). */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name'               => 'Autumn Cup',
            'description'        => 'Play the autumn pack.',
            'starts_at'          => '2026-11-11T20:00',
            'ends_at'            => '2026-11-18T20:00',
            'replay_mode'        => 'multiple_attempts',
            'max_attempts'       => 3,
            'score_rule'         => 'best',
            'normal_play_policy' => 'blocked',
            'pack_ids_present'   => '1',
            'pack_ids'           => [$this->pack->id],
        ];
    }

    private function create(User $as, array $overrides = []): ClubCompetition
    {
        $this->portalAs($as)->post($this->url('/competitions'), $this->form($overrides))->assertRedirect();

        return ClubCompetition::where('club_id', $this->club->id)->latest('id')->firstOrFail();
    }

    private function path(ClubCompetition $c, string $suffix = ''): string
    {
        return $this->url('/competitions/' . $c->public_id . $suffix);
    }

    public function test_list_and_create_pages_render(): void
    {
        $this->portalAs($this->admin)->get($this->url('/competitions'))->assertOk()->assertSee('New competition');
        $this->portalAs($this->admin)->get($this->url('/competitions/create'))->assertOk()
            ->assertSee('Autumn pack')->assertSee('Replay rules')->assertSee('Europe/Brussels');
    }

    public function test_admin_creates_a_draft_audited_as_club_admin(): void
    {
        $c = $this->create($this->admin);

        $this->assertSame(CompetitionState::Draft, $c->state);
        $this->assertSame('Autumn Cup', $c->name);
        $this->assertSame('2026-11-11 19:00:00', $c->starts_at->utc()->format('Y-m-d H:i:s'), 'club time converted to UTC');
        $this->assertSame('multiple_attempts', $c->replay_mode->value);
        $this->assertSame(3, $c->max_attempts);
        $this->assertSame([$this->pack->id], $c->competitionPacks()->pluck('challenge_pack_id')->map(fn ($id) => (int) $id)->all());

        $event = ClubAuditEvent::where('action', 'competition.created')->firstOrFail();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);

        $this->portalAs($this->admin)->get($this->path($c))->assertOk()->assertSee('Autumn Cup')->assertSee('Publish');
    }

    public function test_only_active_packs_of_the_club_can_be_linked(): void
    {
        $draftPack = $this->makeClubPack($this->club, 2, ['status' => ChallengePack::STATUS_DRAFT, 'name' => 'Unfinished pack']);
        $otherOwner = User::factory()->create();
        $other = $this->makeClub(['slug' => 'other-fc'], $otherOwner);
        $foreignPack = $this->makeClubPack($other, 2);
        $publicPack = $this->makePublicPack(2);

        $this->portalAs($this->admin)->get($this->url('/competitions/create'))->assertOk()->assertDontSee('Unfinished pack');

        foreach ([$draftPack, $foreignPack, $publicPack] as $bad) {
            $this->portalAs($this->admin)->post($this->url('/competitions'), $this->form(['pack_ids' => [$bad->id]]))
                ->assertSessionHasErrors('pack_ids.0');
        }
        $this->assertSame(0, ClubCompetition::count());
    }

    public function test_validation_and_window_rules_come_from_the_shared_engine(): void
    {
        $this->portalAs($this->admin)->post($this->url('/competitions'), $this->form(['name' => '', 'replay_mode' => 'forever']))
            ->assertSessionHasErrors(['name', 'replay_mode']);
        $this->portalAs($this->admin)->post($this->url('/competitions'), $this->form(['ends_at' => '2026-11-11T10:00']))
            ->assertSessionHasErrors();
        $this->assertSame(0, ClubCompetition::count());
    }

    public function test_draft_can_be_edited_and_published(): void
    {
        $c = $this->create($this->admin);

        $this->portalAs($this->admin)->put($this->path($c), $this->form(['name' => 'Autumn Cup 2026', 'version' => $c->version, 'max_attempts' => 5]))
            ->assertRedirect($this->path($c))->assertSessionHas('success');
        $c->refresh();
        $this->assertSame('Autumn Cup 2026', $c->name);
        $this->assertSame(5, $c->max_attempts);

        $this->portalAs($this->admin)->from($this->path($c))->post($this->path($c, '/publish'), ['version' => $c->version])
            ->assertRedirect($this->path($c))->assertSessionHas('success');
        $c->refresh();
        $this->assertSame(CompetitionState::Published, $c->state);
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'competition.published')->firstOrFail()->actor_kind);

        // Upcoming (published, not started): still editable before the lock.
        $this->portalAs($this->admin)->put($this->path($c), $this->form(['version' => $c->version, 'max_attempts' => 4]))->assertSessionHas('success');
        $this->assertSame(4, $c->fresh()->max_attempts);
    }

    public function test_publish_without_packs_is_refused(): void
    {
        $c = $this->create($this->admin, ['pack_ids' => []]);

        $this->portalAs($this->admin)->from($this->path($c))->post($this->path($c, '/publish'), ['version' => $c->version])
            ->assertSessionHas('error', __('clubs.errors.competition_invalid_pack'));
        $this->assertSame(CompetitionState::Draft, $c->fresh()->state);
    }

    public function test_cancel_before_start_but_not_after(): void
    {
        $c = $this->create($this->admin);
        $this->portalAs($this->admin)->post($this->path($c, '/publish'), ['version' => $c->version]);

        $this->portalAs($this->admin)->post($this->path($c, '/cancel'), ['version' => $c->fresh()->version, 'reason' => 'rain'])
            ->assertSessionHas('success');
        $this->assertSame(CompetitionState::Cancelled, $c->fresh()->state);
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'competition.cancelled')->firstOrFail()->actor_kind);

        $started = $this->create($this->admin, ['name' => 'Second']);
        $this->portalAs($this->admin)->post($this->path($started, '/publish'), ['version' => $started->version]);
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00', 'UTC'));

        $this->portalAs($this->admin)->from($this->path($started))->post($this->path($started, '/cancel'), ['version' => $started->fresh()->version])
            ->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->assertSame(CompetitionState::Published, $started->fresh()->state);
    }

    public function test_after_the_lock_only_the_end_can_be_extended(): void
    {
        $c = $this->create($this->admin);
        $this->portalAs($this->admin)->post($this->path($c, '/publish'), ['version' => $c->version]);
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00', 'UTC'));
        $c->refresh();

        $this->portalAs($this->admin)->get($this->path($c))->assertOk()
            ->assertSee('Only the end can be extended.')
            ->assertSee('Extend end');

        // Rules, packs and even the name are refused in the portal once locked.
        $this->portalAs($this->admin)->from($this->path($c))->put($this->path($c), ['version' => $c->version, 'replay_mode' => 'no_replay'])
            ->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->portalAs($this->admin)->from($this->path($c))->put($this->path($c), ['version' => $c->version, 'name' => 'Renamed'])
            ->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->assertSame('multiple_attempts', $c->fresh()->replay_mode->value);
        $this->assertSame('Autumn Cup', $c->fresh()->name);

        // Shortening is refused, extending works and is audited.
        $this->portalAs($this->admin)->from($this->path($c))->post($this->path($c, '/extend'), ['version' => $c->version, 'ends_at' => '2026-11-15T20:00'])
            ->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->portalAs($this->admin)->from($this->path($c))->post($this->path($c, '/extend'), ['version' => $c->version, 'ends_at' => '2026-11-20T20:00'])
            ->assertSessionHas('success');
        $this->assertSame('2026-11-20 19:00:00', $c->fresh()->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'competition.ends_at_extended')->firstOrFail()->actor_kind);
    }

    public function test_standings_tab_shows_ranked_members(): void
    {
        $c = $this->makeCompetition($this->club, [$this->pack], [
            'state' => CompetitionState::Published, 'published_at' => now()->subDay(),
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3),
        ]);
        $player = $this->userWithRole(ClubRole::Member, null, ['username' => 'top_scorer']);
        ClubCompetitionEntry::create([
            'club_competition_id' => $c->id, 'user_id' => $player->id, 'total_score' => 270,
            'packs_counted' => 1, 'packs_completed' => 1, 'attempts_used' => 1, 'score_reached_at' => now()->subMinutes(5),
        ]);

        $this->portalAs($this->admin)->get($this->path($c, '?tab=standings'))->assertOk()
            ->assertSee('top_scorer')->assertSee('270');
    }

    public function test_platform_only_actions_have_no_portal_route(): void
    {
        $c = $this->create($this->admin);

        foreach (['/void', '/rebuild', '/finalize'] as $suffix) {
            $this->portalAs($this->owner)->post($this->path($c, $suffix), ['reason' => 'x'])->assertNotFound();
        }
    }

    public function test_member_and_other_clubs_cannot_manage(): void
    {
        $c = $this->create($this->admin);
        $member = $this->userWithRole(ClubRole::Member);
        $otherOwner = User::factory()->create();
        $this->makeClub(['slug' => 'other-fc'], $otherOwner);

        $this->portalAs($member)->get($this->path($c))->assertForbidden();
        $this->portalAs($otherOwner)->get($this->path($c))->assertNotFound();
        $this->portalAs($otherOwner)->post($this->path($c, '/publish'))->assertNotFound();
        $this->assertSame(CompetitionState::Draft, $c->fresh()->state);
    }

    public function test_suspended_club_competitions_are_read_only(): void
    {
        $c = $this->create($this->admin);
        $this->club->forceFill(['status' => 'suspended'])->save();

        $this->portalAs($this->admin)->get($this->path($c))->assertOk()->assertDontSee('Save draft');
        $this->portalAs($this->admin)->get($this->url('/competitions/create'))->assertForbidden();
        $this->portalAs($this->admin)->post($this->path($c, '/publish'), ['version' => $c->version])->assertSessionHas('error', __('clubs.errors.club_unavailable'));
        $this->assertSame(CompetitionState::Draft, $c->fresh()->state);
    }
}
