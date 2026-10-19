<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubCompetitionPack;
use App\Models\PackAttempt;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Central admin competition editor (plan §8.4): cross-club list, create /
 * edit with club-timezone windows, AT-ADM-07 through the form, lock-aware
 * read-only fields, publish / cancel / extend / void / rebuild (dry run →
 * apply) / finalize, standings with attempt void, access (AT-ADM-01) and
 * pinned strings.
 */
class AdminCompetitionTest extends CompetitionTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'username' => 'admin']);
    }

    private function base(): string
    {
        return '/admin/clubs/' . $this->club->id . '/competitions';
    }

    private function form(array $packIds, array $overrides = []): array
    {
        return array_merge([
            'name'               => 'Autumn Cup',
            'description'        => 'Derby week',
            'starts_at'          => '2026-11-12T10:00',
            'ends_at'            => '2026-11-30T22:00',
            'replay_mode'        => 'single_attempt',
            'max_attempts'       => 3,
            'score_rule'         => 'best',
            'normal_play_policy' => 'blocked',
            'pack_ids_present'   => 1,
            'pack_ids'           => $packIds,
        ], $overrides);
    }

    public function test_create_draft_in_club_timezone_and_open_the_editor(): void
    {
        $a = $this->makeClubPack($this->club, 2, ['name' => 'Autumn Pack 1']);
        $b = $this->makeClubPack($this->club, 1, ['name' => 'Derby Pack']);
        // A normal attempt in progress on the Derby pack → warning in the editor.
        PackAttempt::create(['user_id' => $this->member('busy')->id, 'challenge_pack_id' => $b->id, 'status' => 'active', 'started_at' => now(), 'metadata' => ['challenge_ids' => []]]);

        $create = $this->actingAs($this->admin)->get($this->base() . '/create')->assertOk();
        foreach (['New competition', 'Autumn Pack 1', 'Derby Pack', '⚠ "Derby Pack" has 1 normal attempt in progress', 'No replay — players who already played a pack (at least one guess) cannot play it',
                  'One competition attempt per pack', 'Multiple attempts', 'Tie-break:', 'total score → reached first → fewer attempts (shared rank if equal)',
                  '🔒 Rules lock automatically at the start time.', 'club time: Europe/Brussels', 'Save draft'] as $needle) {
            $create->assertSee($needle, false);
        }

        $response = $this->actingAs($this->admin)->post($this->base(), $this->form([$b->id, $a->id], [
            'replay_mode' => 'multiple_attempts', 'max_attempts' => 4, 'score_rule' => 'first',
        ]));
        $c = ClubCompetition::firstOrFail();
        $response->assertRedirect($this->base() . '/' . $c->id)->assertSessionHas('success');

        $this->assertSame('draft', $c->state->value);
        $this->assertSame('2026-11-12 09:00:00', $c->starts_at->format('Y-m-d H:i:s'), 'Brussels (UTC+1) → UTC');
        $this->assertSame('2026-11-30 21:00:00', $c->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(['multiple_attempts', 4, 'first'], [$c->replay_mode->value, $c->max_attempts, $c->score_rule->value]);
        $this->assertSame([$b->id, $a->id], ClubCompetitionPack::orderBy('sort_order')->pluck('challenge_pack_id')->all());
        $this->assertSame($this->admin->id, ClubAuditEvent::where('action', 'competition.created')->value('actor_user_id'));

        $show = $this->actingAs($this->admin)->get($this->base() . '/' . $c->id)->assertOk();
        $show->assertSee('Competition: Autumn Cup')->assertSee('2026-11-12T10:00', false)->assertSee('Publish')->assertSee('Cancel competition')
            ->assertDontSee('disabled', false);

        // Club detail tab links the editor; the cross-club list shows it.
        $this->actingAs($this->admin)->get('/admin/clubs/' . $this->club->id . '?tab=competitions')->assertOk()
            ->assertSee($this->base() . '/' . $c->id, false)->assertSee('+ New competition');
        $this->actingAs($this->admin)->get('/admin/clubs/' . $this->club->id . '/competitions')->assertRedirect('/admin/clubs/' . $this->club->id . '?tab=competitions');
    }

    public function test_at_adm_07_linking_another_clubs_pack_or_a_public_pack_fails_validation(): void
    {
        $own     = $this->makeClubPack($this->club, 1);
        $foreign = $this->makeClubPack($this->makeClub(['name' => 'Other']), 1);
        $public  = $this->makePublicPack(1);

        foreach ([$foreign, $public] as $bad) {
            $this->actingAs($this->admin)->from($this->base() . '/create')->post($this->base(), $this->form([$own->id, $bad->id]))
                ->assertRedirect($this->base() . '/create')->assertSessionHasErrors('pack_ids.1');
        }
        $this->assertSame(0, ClubCompetition::count());

        $this->actingAs($this->admin)->post($this->base(), $this->form([$own->id], ['ends_at' => '2026-11-12T09:00']))->assertSessionHasErrors('ends_at');
        $this->actingAs($this->admin)->post($this->base(), $this->form([$own->id], ['replay_mode' => 'multiple_attempts', 'max_attempts' => 1]))->assertSessionHasErrors('max_attempts');
        $this->assertSame(0, ClubCompetition::count());
    }

    public function test_publish_then_edit_upcoming_then_locked_fields_are_read_only_and_refused(): void
    {
        $a = $this->makeClubPack($this->club, 2);
        $b = $this->makeClubPack($this->club, 2);
        $this->actingAs($this->admin)->post($this->base(), $this->form([$a->id]));
        $c = ClubCompetition::firstOrFail();

        $this->actingAs($this->admin)->post("{$this->base()}/{$c->id}/publish", ['version' => $c->version])->assertSessionHas('success');
        $c = $c->fresh();
        $this->assertSame('upcoming', $c->phase()->value);

        // Upcoming: rules still editable (audited).
        $this->actingAs($this->admin)->put("{$this->base()}/{$c->id}", $this->form([$a->id, $b->id], ['version' => $c->version, 'replay_mode' => 'no_replay']))
            ->assertSessionHas('success');
        $c = $c->fresh();
        $this->assertSame('no_replay', $c->replay_mode->value);
        $this->assertSame(2, ClubCompetitionPack::count());

        // Stale page → stale_version.
        $this->actingAs($this->admin)->put("{$this->base()}/{$c->id}", $this->form([$a->id], ['version' => $c->version - 1, 'name' => 'Lost']))
            ->assertSessionHas('error', __('clubs.errors.stale_version'));

        // After the start: 🔒 read-only fields…
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:30:00', 'UTC'));
        $html = $this->actingAs($this->admin)->get("{$this->base()}/{$c->id}")->assertOk()->getContent();
        $this->assertStringContainsString('The competition has started: replay rules, packs, start time and normal-play policy are read-only.', $html);
        foreach (['no_replay', 'single_attempt', 'multiple_attempts'] as $mode) {
            $this->assertMatchesRegularExpression('/name="replay_mode" id="mode-[a-z-]+" value="' . $mode . '"( checked)?\s+disabled/', $html);
        }
        $this->assertMatchesRegularExpression('/name="pack_ids\[\]" value="' . $a->id . '" id="pack-' . $a->id . '" checked\s+disabled/', $html);
        $this->assertMatchesRegularExpression('/id="starts_at" name="starts_at"[^>]*disabled/', $html);
        $this->assertStringContainsString('Replay rules 🔒', $html);
        $this->assertStringContainsString('Extend end', $html);
        $this->assertStringNotContainsString('Cancel competition', $html);

        // …a crafted POST changing a locked field is refused, nothing changes.
        $this->actingAs($this->admin)->put("{$this->base()}/{$c->id}", ['version' => $c->version, 'name' => 'Autumn Cup', 'replay_mode' => 'multiple_attempts', 'max_attempts' => 5])
            ->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->actingAs($this->admin)->put("{$this->base()}/{$c->id}", ['version' => $c->version, 'name' => 'Autumn Cup', 'pack_ids_present' => 1, 'pack_ids' => [$a->id]])
            ->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->assertSame('no_replay', $c->fresh()->replay_mode->value);

        // …but name / description stay editable with the disabled fields absent.
        $this->actingAs($this->admin)->put("{$this->base()}/{$c->id}", ['version' => $c->version, 'name' => 'Autumn Cup 2026', 'description' => 'New text'])
            ->assertSessionHas('success');
        $this->assertSame('Autumn Cup 2026', $c->fresh()->name);

        // Extend end: later only, audited.
        $c = $c->fresh();
        $this->actingAs($this->admin)->post("{$this->base()}/{$c->id}/extend", ['version' => $c->version, 'ends_at' => '2026-11-29T22:00'])
            ->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->actingAs($this->admin)->post("{$this->base()}/{$c->id}/extend", ['version' => $c->version, 'ends_at' => '2026-12-02T22:00'])
            ->assertSessionHas('success');
        $this->assertSame('2026-12-02 21:00:00', $c->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.ends_at_extended')->count());
    }

    public function test_cancel_void_and_finalize_actions(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $draft = $this->makeCompetition($this->club, [$pack]);
        $this->actingAs($this->admin)->post("{$this->base()}/{$draft->id}/cancel", ['reason' => 'Duplicate'])->assertSessionHas('success');
        $this->assertSame('cancelled', $draft->fresh()->state->value);

        $live = $this->liveCompetition([$pack], ['ends_at' => now()->addHour()]);
        $this->actingAs($this->admin)->post("{$this->base()}/{$live->id}/cancel")->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
        $this->actingAs($this->admin)->post("{$this->base()}/{$live->id}/finalize")->assertSessionHas('error', __('clubs.errors.competition_not_editable'));
        $this->actingAs($this->admin)->post("{$this->base()}/{$live->id}/void", [])->assertSessionHasErrors('reason');
        $this->assertSame('published', $live->fresh()->state->value);

        $this->play($this->member('player'), $live, $pack, [100]);
        Carbon::setTestNow(now()->addHours(2));
        $this->actingAs($this->admin)->get("{$this->base()}/{$live->id}")->assertSee('Finalize now');
        $this->actingAs($this->admin)->post("{$this->base()}/{$live->id}/finalize")->assertSessionHas('success', 'Competition finalized: 1 placements written.');
        $this->actingAs($this->admin)->post("{$this->base()}/{$live->id}/finalize")->assertSessionHas('success', 'This competition was already finalized.');
        $this->assertSame(1, ClubCompetitionFinish::count());
        $this->assertSame($this->admin->id, ClubAuditEvent::where('action', 'competition.finalized')->value('actor_user_id'));

        $this->actingAs($this->admin)->post("{$this->base()}/{$live->id}/void", ['reason' => 'Leaked photos'])->assertSessionHas('success');
        $this->assertSame('voided', $live->fresh()->state->value);
        $this->assertSame(0, ClubCompetitionFinish::count());
    }

    public function test_standings_tab_rebuild_preview_then_apply_and_attempt_void(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], $this->multiple(2, 'best'));
        $anna = $this->member('anna');
        $bob  = $this->member('bob');
        $this->play($anna, $c, $pack, [100, 100]);
        $bobAttempt = $this->play($bob, $c, $pack, [90, 90]);
        $left = $this->member('leaver');
        $this->play($left, $c, $pack, [10]);
        \App\Models\ClubMember::where('user_id', $left->id)->update(['status' => 'left']);

        $page = $this->actingAs($this->admin)->get("{$this->base()}/{$c->id}?tab=standings")->assertOk();
        $page->assertSeeInOrder(['anna', '200', 'bob', '180', 'leaver', 'left · hidden'])->assertSee('2 ranked players')->assertSee('Void attempt');

        // Drift, preview (dry run: nothing written), then apply.
        ClubCompetitionEntry::where('user_id', $bob->id)->update(['total_score' => 1]);
        $this->actingAs($this->admin)->post("{$this->base()}/{$c->id}/rebuild", ['dry_run' => 1])
            ->assertRedirect("{$this->base()}/{$c->id}?tab=standings")->assertSessionHas('rebuild_preview.count', 1);
        $this->assertSame(1, ClubCompetitionEntry::where('user_id', $bob->id)->value('total_score'));
        $this->actingAs($this->admin)->withSession(['rebuild_preview' => ['count' => 1, 'players' => 3, 'changes' => [
            ['type' => 'entry', 'user_id' => $bob->id, 'pack_id' => null, 'field' => 'total_score', 'from' => 1, 'to' => 180]]]])
            ->get("{$this->base()}/{$c->id}?tab=standings")->assertSee('Rebuild preview (dry run)')->assertSee('Apply rebuild');
        $this->actingAs($this->admin)->post("{$this->base()}/{$c->id}/rebuild", ['dry_run' => 0])
            ->assertSessionHas('success', 'Standings rebuilt: 1 difference corrected.');
        $this->assertSame(180, ClubCompetitionEntry::where('user_id', $bob->id)->value('total_score'));
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.rebuilt')->count());

        // Void an attempt (reason required).
        $this->actingAs($this->admin)->post("{$this->base()}/{$c->id}/attempts/{$bobAttempt}/void", [])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("{$this->base()}/{$c->id}/attempts/{$bobAttempt}/void", ['reason' => 'Two phones'])->assertSessionHas('success');
        $this->assertSame('voided', ClubCompetitionAttempt::find($bobAttempt)->status->value);
        $this->assertNull(ClubCompetitionEntry::where('user_id', $bob->id)->first());
        $this->assertSame($this->admin->id, ClubAuditEvent::where('action', 'attempt.voided')->value('actor_user_id'));
    }

    public function test_cross_club_list_filters_search_and_paginates(): void
    {
        $other = $this->makeClub(['name' => 'Other Club']);
        $pack  = $this->makeClubPack($this->club, 1);
        $this->liveCompetition([$pack], ['name' => 'Live Cup']);
        $this->makeCompetition($this->club, [$pack], ['name' => 'Draft Cup']);
        $this->makeCompetition($other, [], ['name' => 'Other Cup', 'state' => 'finalized', 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(8)]);

        $all = $this->actingAs($this->admin)->get('/admin/club-competitions')->assertOk();
        $all->assertSee('Live Cup')->assertSee('Draft Cup')->assertSee('Other Cup')->assertSee('Showing 1–3 of 3.');
        $this->actingAs($this->admin)->get('/admin/club-competitions?phase=active')->assertSee('Live Cup')->assertDontSee('Draft Cup')->assertDontSee('Other Cup');
        $this->actingAs($this->admin)->get('/admin/club-competitions?club=' . $other->id)->assertSee('Other Cup')->assertDontSee('Live Cup');
        $this->actingAs($this->admin)->get('/admin/club-competitions?q=draft')->assertSee('Draft Cup')->assertDontSee('Live Cup');
        $this->actingAs($this->admin)->get('/admin/club-competitions?q=nothing')->assertSee('No competitions match.');

        foreach (range(1, 26) as $i) {
            $this->makeCompetition($other, [], ['name' => "Bulk {$i}"]);
        }
        $this->actingAs($this->admin)->get('/admin/club-competitions?club=' . $other->id . '&page=2')->assertOk()
            ->assertSee('Showing 26–27 of 27.')->assertSee('club=' . $other->id, false);
    }

    public function test_another_clubs_competition_or_attempt_is_404(): void
    {
        $other = $this->makeClub(['name' => 'Other']);
        $theirs = $this->makeCompetition($other, []);
        $pack = $this->makeClubPack($this->club, 1);
        $mine = $this->liveCompetition([$pack]);
        $foreignAttempt = ClubCompetitionAttempt::factory()->create(['club_competition_id' => $theirs->id, 'challenge_pack_id' => $this->makeClubPack($other, 1)->id]);

        $this->actingAs($this->admin)->get("{$this->base()}/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->admin)->post("{$this->base()}/{$theirs->id}/publish")->assertNotFound();
        $this->actingAs($this->admin)->post("{$this->base()}/{$mine->id}/attempts/{$foreignAttempt->id}/void", ['reason' => 'x'])->assertNotFound();
        $this->assertSame('active', $foreignAttempt->fresh()->status->value);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function adminRoutes(): array
    {
        return [
            'list'         => ['GET', '/admin/club-competitions'],
            'club index'   => ['GET', '{base}'],
            'create'       => ['GET', '{base}/create'],
            'store'        => ['POST', '{base}'],
            'show'         => ['GET', '{base}/{c}'],
            'update'       => ['PUT', '{base}/{c}'],
            'publish'      => ['POST', '{base}/{c}/publish'],
            'cancel'       => ['POST', '{base}/{c}/cancel'],
            'extend'       => ['POST', '{base}/{c}/extend'],
            'void'         => ['POST', '{base}/{c}/void'],
            'rebuild'      => ['POST', '{base}/{c}/rebuild'],
            'finalize'     => ['POST', '{base}/{c}/finalize'],
            'attempt void' => ['POST', '{base}/{c}/attempts/{a}/void'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_at_adm_01_guests_and_non_admins_including_the_club_owner_are_refused(string $method, string $template): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c = $this->makeCompetition($this->club, [$pack]);
        $a = ClubCompetitionAttempt::factory()->create(['club_competition_id' => $c->id, 'challenge_pack_id' => $pack->id]);
        $uri = str_replace(['{base}', '{c}', '{a}'], [$this->base(), $c->id, $a->id], $template);
        $payload = $this->form([$pack->id], ['reason' => 'x', 'version' => $c->version]);
        $before = [$c->fresh()->toArray(), ClubAuditEvent::count()];

        $this->call($method, $uri, $payload)->assertRedirect('/admin/login');
        $this->actingAs($this->owner)->call($method, $uri, $payload)->assertForbidden();
        $this->assertSame($before, [$c->fresh()->toArray(), ClubAuditEvent::count()]);
    }
}
