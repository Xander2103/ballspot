<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Enums\ClubMemberStatus;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubCompetitionPackResult;
use App\Models\ClubMember;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionService;
use App\Services\Clubs\ClubStandingsService;
use Carbon\Carbon;

/**
 * Score selection, ranking, ties, voids, D22 hiding and the
 * incremental == rebuild invariant (AT-CMP-04, 05, 10, 14, 15; D16, D17, D22).
 */
class CompetitionStandingsTest extends CompetitionTestCase
{
    private function standings(): ClubStandingsService
    {
        return app(ClubStandingsService::class);
    }

    public function test_at_cmp_04_best_counts_the_first_attempt_that_reached_the_best_score(): void
    {
        $pack = $this->makeClubPack($this->club, 10);
        $c    = $this->liveCompetition([$pack], $this->multiple(3, 'best'));
        $user = $this->member('player');

        $this->play($user, $c, $pack, array_fill(0, 10, 60), 1);  // 600
        $second = $this->play($user, $c, $pack, array_fill(0, 10, 84), 2); // 840
        $reached = ClubCompetitionAttempt::find($second)->last_scored_at;
        $this->tick(3600);
        $this->play($user, $c, $pack, array_fill(0, 10, 84), 3);  // 840 again, later

        $result = ClubCompetitionPackResult::first();
        $this->assertSame($second, (int) $result->counted_attempt_id);
        $this->assertSame(840, $result->counted_score);
        $this->assertSame(840, $result->best_score);
        $this->assertSame(600, $result->first_score);
        $this->assertTrue($result->score_reached_at->equalTo($reached));
        $this->assertSame(3, $result->attempts_used);

        $entry = ClubCompetitionEntry::first();
        $this->assertSame(840, $entry->total_score);
        $this->assertTrue($entry->score_reached_at->equalTo($reached));
        $this->assertSame(3, $entry->attempts_used);

        $this->api($user)->getJson($this->competitionPath($c))->assertOk()
            ->assertJsonPath('data.packs.0.state.counted_score', 840)
            ->assertJsonPath('data.packs.0.state.best_score', 840)
            ->assertJsonPath('data.packs.0.state.first_score', 600)
            ->assertJsonPath('data.my_entry.total_score', 840)
            ->assertJsonPath('data.my_entry.score_reached_at', $reached->toISOString());
    }

    public function test_at_cmp_05_first_counts_the_earliest_attempt(): void
    {
        $pack = $this->makeClubPack($this->club, 10);
        $c    = $this->liveCompetition([$pack], $this->multiple(2, 'first'));
        $user = $this->member('player');

        $this->play($user, $c, $pack, array_fill(0, 10, 50), 1);
        $this->play($user, $c, $pack, array_fill(0, 10, 90), 2);

        $this->assertSame(500, ClubCompetitionPackResult::first()->counted_score);
        $this->assertSame(900, ClubCompetitionPackResult::first()->best_score);
        $this->assertSame(500, ClubCompetitionEntry::first()->total_score);
    }

    public function test_d16_partial_attempts_count_and_totals_sum_over_packs(): void
    {
        $a = $this->makeClubPack($this->club, 3);
        $b = $this->makeClubPack($this->club, 3);
        $c = $this->liveCompetition([$a, $b]);
        $user = $this->member('player');

        $this->play($user, $c, $a, [100, 100, 100]);
        $this->play($user, $c, $b, [40]); // still active

        $entry = ClubCompetitionEntry::first();
        $this->assertSame(340, $entry->total_score);
        $this->assertSame(2, $entry->packs_counted);
        $this->assertSame(1, $entry->packs_completed);
        $this->assertSame(2, $entry->attempts_used);
        $this->assertSame(1, $c->fresh()->participants_count);
    }

    public function test_started_attempt_without_guesses_is_not_on_the_board(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $this->startAttempt($user, $c, $pack)->assertCreated();

        $this->assertSame(0, ClubCompetitionEntry::count());
        $this->api($user)->getJson($this->competitionPath($c, '/leaderboard'))->assertOk()
            ->assertJsonPath('meta.total_players', 0)->assertJsonPath('data', []);
        $this->api($user)->getJson($this->competitionPath($c))->assertJsonPath('data.my_entry', null);
    }

    public function test_at_cmp_10_ties_share_ranks_and_placements(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack], ['ends_at' => now()->addHours(2)]);
        [$anna, $bob, $cleo, $dave, $eve] = [$this->member('anna'), $this->member('bob'), $this->member('Cleo'), $this->member('dave'), $this->member('eve')];

        $this->play($anna, $c, $pack, [100]);           // 100 @ T+1
        $this->tick();
        // bob and cleo: 80 at exactly the same second → fully equal.
        $b = $this->startAttempt($bob, $c, $pack)->json('attempt.id');
        $cl = $this->startAttempt($cleo, $c, $pack)->json('attempt.id');
        $this->guess($bob, $b, 80)->assertOk();
        $this->guess($cleo, $cl, 80)->assertOk();
        $this->tick();
        $this->play($dave, $c, $pack, [80]);            // 80 but later
        $this->play($eve, $c, $pack, [10]);

        $board = $this->api($dave)->getJson($this->competitionPath($c, '/leaderboard'))->assertOk();
        $this->assertSame(['anna', 'bob', 'Cleo', 'dave', 'eve'], array_column($board->json('data'), 'username'));
        $this->assertSame([1, 2, 2, 4, 5], array_column($board->json('data'), 'rank'));
        $board->assertJsonPath('meta.current_user_rank', 4)->assertJsonPath('meta.total_players', 5)
            ->assertJsonPath('meta.phase', 'active')->assertJsonPath('meta.rules.replay_mode', 'single_attempt');

        // The single-entry SQL rank agrees with the in-memory ranking.
        foreach ([$anna, $bob, $cleo, $dave, $eve] as $i => $u) {
            $this->assertSame([1, 2, 2, 4, 5][$i], $this->standings()->rankOf($c, ClubCompetitionEntry::where('user_id', $u->id)->first()));
        }
        $this->api($cleo)->getJson($this->competitionPath($c))->assertJsonPath('data.my_entry.rank', 2)->assertJsonPath('data.me.rank', 2);

        // Finalize → shared placements.
        Carbon::setTestNow($c->ends_at->copy()->addMinute());
        app(ClubCompetitionCloseService::class)->finalize($c);
        $placements = ClubCompetitionFinish::orderBy('placement')->orderBy('user_id')->get()
            ->mapWithKeys(fn ($f) => [User::find($f->user_id)->username => $f->placement])->all();
        $this->assertSame(['anna' => 1, 'bob' => 2, 'Cleo' => 2, 'dave' => 4, 'eve' => 5], $placements);
        $this->assertSame([5], ClubCompetitionFinish::pluck('total_players')->unique()->values()->all());
    }

    public function test_fewer_attempts_break_a_tie_on_total_and_time(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack], $this->multiple(3, 'best'));
        $x = $this->member('xavier');
        $y = $this->member('yara');

        $this->tick();
        $ax = $this->startAttempt($x, $c, $pack, 1)->json('attempt.id');
        $ay = $this->startAttempt($y, $c, $pack, 1)->json('attempt.id');
        $this->guess($x, $ax, 90)->assertOk();
        $this->guess($y, $ay, 90)->assertOk();
        $this->play($x, $c, $pack, [50], 2); // extra attempt that does not improve

        $board = $this->api($x)->getJson($this->competitionPath($c, '/leaderboard'))->json('data');
        $this->assertSame(['yara', 'xavier'], array_column($board, 'username'));
        $this->assertSame([1, 2], array_column($board, 'rank'));
        $this->assertSame([1, 2], array_column($board, 'attempts_used'));
    }

    public function test_at_cmp_15_void_removes_the_attempt_and_first_falls_back(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], $this->multiple(3, 'first'));
        $user = $this->member('cheater');
        $rival = $this->member('rival');

        $one = $this->play($user, $c, $pack, [100, 100], 1);
        $this->play($user, $c, $pack, [30, 30], 2);
        $this->play($rival, $c, $pack, [70, 70]);
        $this->assertSame(200, ClubCompetitionEntry::where('user_id', $user->id)->first()->total_score);

        app(ClubCompetitionService::class)->voidAttempt(ClubCompetitionAttempt::find($one), $this->owner, 'Shared screen');

        $result = ClubCompetitionPackResult::where('user_id', $user->id)->first();
        $this->assertSame(60, $result->counted_score);
        $this->assertSame(1, $result->attempts_used);
        $this->assertSame(60, ClubCompetitionEntry::where('user_id', $user->id)->first()->total_score);
        $this->assertSame('voided', ClubCompetitionAttempt::find($one)->status->value);

        $event = ClubAuditEvent::where('action', 'attempt.voided')->firstOrFail();
        $this->assertSame('Shared screen', $event->metadata['reason']);
        $this->assertSame($one, $event->metadata['attempt_id']);

        // A void never hands out a new slot: attempt 3 is the last one.
        $state = $this->api($user)->getJson($this->competitionPath($c))->json('data.packs.0.state');
        $this->assertSame(2, $state['attempts_used']);
        $this->assertSame(3, $state['next_attempt_number']);

        $board = $this->api($rival)->getJson($this->competitionPath($c, '/leaderboard'))->json('data');
        $this->assertSame(['rival', 'cheater'], array_column($board, 'username'));
    }

    public function test_voiding_the_only_attempt_drops_the_player_from_the_board(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $id   = $this->play($user, $c, $pack, [100]);

        app(ClubCompetitionService::class)->voidAttempt(ClubCompetitionAttempt::find($id), $this->owner, 'Cheating');
        $this->assertSame(0, ClubCompetitionEntry::count());
        $this->assertSame(0, ClubCompetitionPackResult::count());
        $this->assertSame(0, $c->fresh()->participants_count);
    }

    public function test_d22_left_removed_and_banned_members_are_hidden_but_kept(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack]);
        $top   = $this->member('top');
        $left  = $this->member('leaver');
        $ban   = $this->member('banned');
        $me    = $this->member('me');
        $this->play($top, $c, $pack, [100]);
        $this->play($left, $c, $pack, [90]);
        $this->play($ban, $c, $pack, [80]);
        $this->play($me, $c, $pack, [70]);

        $this->api($me)->getJson($this->competitionPath($c))->assertJsonPath('data.my_entry.rank', 4);

        ClubMember::where('user_id', $left->id)->update(['status' => ClubMemberStatus::Left->value]);
        ClubMember::where('user_id', $ban->id)->update(['status' => ClubMemberStatus::Banned->value]);
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();

        $board = $this->api($me)->getJson($this->competitionPath($c, '/leaderboard'))->assertOk();
        $this->assertSame(['top', 'me'], array_column($board->json('data'), 'username'));
        $this->assertSame([1, 2], array_column($board->json('data'), 'rank'));
        $board->assertJsonPath('meta.total_players', 2);
        $this->api($me)->getJson($this->competitionPath($c))->assertJsonPath('data.my_entry.rank', 2)
            ->assertJsonPath('data.leaderboard_preview.1.username', 'me');
        // Their rows stay for rebuilds and a re-join.
        $this->assertSame(4, ClubCompetitionEntry::count());

        Carbon::setTestNow($c->ends_at->copy()->addSecond());
        app(ClubCompetitionCloseService::class)->finalize($c);
        $this->assertEqualsCanonicalizing([$top->id, $me->id], ClubCompetitionFinish::pluck('user_id')->all());
    }

    public function test_leaderboard_cursor_pages_keep_shared_ranks(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack]);
        $players = [];
        foreach (range(1, 7) as $i) {
            $players[] = $u = $this->member('p' . $i);
            $id = $this->startAttempt($u, $c, $pack)->json('attempt.id');
            $this->guess($u, $id, $i <= 3 ? 100 : 50)->assertOk(); // same second: 3-way tie, 4-way tie
        }

        $page1 = $this->api($players[0])->getJson($this->competitionPath($c, '/leaderboard?limit=4'))->assertOk();
        $this->assertSame([1, 1, 1, 4], array_column($page1->json('data'), 'rank'));
        $this->assertNotNull($cursor = $page1->json('meta.next_cursor'));
        $page2 = $this->api($players[0])->getJson($this->competitionPath($c, '/leaderboard?limit=4&cursor=' . $cursor))->assertOk();
        $this->assertSame([4, 4, 4], array_column($page2->json('data'), 'rank'));
        $this->assertNull($page2->json('meta.next_cursor'));
        $this->assertSame(4, $page2->json('meta.per_page'));
    }

    /**
     * AT-CMP-14: after a random sequence of starts, guesses, voids, ends_at
     * extensions and expiries, the incrementally maintained standings equal a
     * full rebuild (dry-run diff empty), and rebuilding from wiped derived
     * tables reproduces them exactly.
     */
    public function test_at_cmp_14_randomized_sequence_incremental_equals_rebuild(): void
    {
        foreach ([[7, 'best'], [11, 'first'], [23, 'best']] as [$seed, $rule]) {
            mt_srand($seed);
            $packs = [$this->makeClubPack($this->club, 3), $this->makeClubPack($this->club, 2)];
            $c = $this->liveCompetition($packs, $this->multiple(3, $rule) + ['ends_at' => now()->addHours(6)]);
            $users = [];
            for ($i = 0; $i < 5; $i++) {
                $users[] = $this->member("s{$seed}u{$i}");
            }

            for ($step = 0; $step < 120; $step++) {
                $this->tick(mt_rand(0, 2) * 30);
                $user = $users[mt_rand(0, 4)];
                $pack = $packs[mt_rand(0, 1)];
                $roll = mt_rand(1, 100);
                $active = ClubCompetitionAttempt::where('club_competition_id', $c->id)->where('user_id', $user->id)
                    ->where('challenge_pack_id', $pack->id)->where('status', 'active')->first();

                if ($roll <= 60 && $active) {
                    $this->guess($user, $active->id, mt_rand(0, 100))->assertOk();
                } elseif ($roll <= 80) {
                    $next = (int) ClubCompetitionAttempt::where('club_competition_id', $c->id)->where('user_id', $user->id)
                        ->where('challenge_pack_id', $pack->id)->max('attempt_number') + 1;
                    $this->startAttempt($user, $c, $pack, $next); // may be refused (limit) — fine
                } elseif ($roll <= 88) {
                    $victim = ClubCompetitionAttempt::where('club_competition_id', $c->id)->where('status', '!=', 'voided')->inRandomOrder()->first();
                    if ($victim) {
                        app(ClubCompetitionService::class)->voidAttempt($victim, $this->owner, 'random');
                    }
                } elseif ($roll <= 94) {
                    $fresh = $c->fresh();
                    app(ClubCompetitionService::class)->extend($fresh, $fresh->ends_at->copy()->addMinutes(mt_rand(1, 30)), $this->owner, $fresh->version);
                } elseif ($active) {
                    // Duplicate retry of the last accepted guess.
                    $last = $active->fresh()->current_index - 1;
                    if ($last >= 0) {
                        $this->guess($user, $active->id, 100, $active->challengeIds()[$last])->assertOk()->assertJsonPath('replayed', true);
                    }
                }
            }

            // Close the window: remaining attempts expire, then compare.
            Carbon::setTestNow($c->fresh()->ends_at->copy()->addMinute());
            app(ClubCompetitionCloseService::class)->expireAttempts($c->fresh());

            $c = $c->fresh();
            // The sequence really exercised voids, extensions, expiries and completions.
            $statuses = ClubCompetitionAttempt::where('club_competition_id', $c->id)->pluck('status')->map->value->countBy();
            $this->assertGreaterThan(0, $statuses['voided'] ?? 0, "seed {$seed}: no void");
            $this->assertGreaterThan(0, $statuses['completed'] ?? 0, "seed {$seed}: no completion");
            $this->assertGreaterThan(1, $c->version, "seed {$seed}: no extension");
            $incremental = $this->snapshot($c);
            $this->assertNotEmpty($incremental['entries'], "seed {$seed} produced no standings");
            $this->assertSame([], $this->standings()->rebuild($c, dryRun: true)['changes'], "seed {$seed}: dry-run diff not empty");

            // Wipe the derived tables and rebuild from source rows.
            ClubCompetitionEntry::where('club_competition_id', $c->id)->delete();
            ClubCompetitionPackResult::where('club_competition_id', $c->id)->delete();
            ClubCompetitionAttempt::where('club_competition_id', $c->id)->update(['total_score' => 0, 'last_scored_at' => null]);
            $diff = $this->standings()->rebuild($c);
            $this->assertNotEmpty($diff['changes']);
            $this->assertEquals($incremental, $this->snapshot($c->fresh()), "seed {$seed}: rebuild differs from incremental");
            $this->assertSame([], $this->standings()->rebuild($c->fresh(), dryRun: true)['changes']);
        }
    }

    private function snapshot(ClubCompetition $c): array
    {
        $ts = fn ($v) => ClubStandingsService::norm($v);

        return [
            'attempts' => ClubCompetitionAttempt::where('club_competition_id', $c->id)->orderBy('id')->get()
                ->map(fn ($a) => [$a->id, $a->total_score, $ts($a->last_scored_at)])->all(),
            'results' => ClubCompetitionPackResult::where('club_competition_id', $c->id)->orderBy('user_id')->orderBy('challenge_pack_id')->get()
                ->map(fn ($r) => [$r->user_id, $r->challenge_pack_id, $r->attempts_used, $r->counted_attempt_id, $r->counted_score,
                    $r->best_score, $r->first_score, $r->completed, $ts($r->score_reached_at)])->all(),
            'entries' => ClubCompetitionEntry::where('club_competition_id', $c->id)->orderBy('user_id')->get()
                ->map(fn ($e) => [$e->user_id, $e->total_score, $e->packs_counted, $e->packs_completed, $e->attempts_used, $ts($e->score_reached_at)])->all(),
            'ranking' => $this->standings()->ranked($c)->map(fn ($e) => [$e['user_id'], $e['rank']])->all(),
            'participants' => (int) $c->fresh()->participants_count,
        ];
    }
}
