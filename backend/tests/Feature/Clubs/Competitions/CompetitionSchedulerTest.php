<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Models\ClubAuditEvent;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubCompetitionPack;
use App\Services\Clubs\ClubCompetitionCloseService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * Console side (plan §5.10, §5.11): the minute tick (lock → expire →
 * finalize), finalize idempotency (AT-CMP-16), rebuild-standings with
 * dry-run / refinalize, reconcile-counters and the scheduler registration.
 */
class CompetitionSchedulerTest extends CompetitionTestCase
{
    public function test_tick_locks_rules_at_start(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], ['starts_at' => now()->addMinutes(2)]);

        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();
        $this->assertNull($c->fresh()->rules_locked_at);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('Locked: 1')->assertSuccessful();
        $this->assertNotNull($c->fresh()->rules_locked_at);
        $this->assertCount(2, ClubCompetitionPack::first()->snapshotIds());

        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('Locked: 0')->assertSuccessful();
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.rules_locked')->count());
    }

    public function test_tick_expires_attempts_and_finalizes_ended_competitions(): void
    {
        $pack = $this->makeClubPack($this->club, 3);
        $c    = $this->liveCompetition([$pack], ['ends_at' => now()->addMinutes(30)]);
        $a    = $this->member('anna');
        $b    = $this->member('bob');
        $this->play($a, $c, $pack, [100, 100, 100]);
        $partial = $this->play($b, $c, $pack, [90]);

        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();
        $this->assertSame('active', ClubCompetitionAttempt::find($partial)->status->value);
        $this->assertSame('published', $c->fresh()->state->value);

        Carbon::setTestNow($c->ends_at->copy());
        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('expired attempts: 1 · finalized: 1')->assertSuccessful();

        $this->assertSame('expired', ClubCompetitionAttempt::find($partial)->status->value);
        $c = $c->fresh();
        $this->assertSame('finalized', $c->state->value);
        $this->assertNotNull($c->finalized_at);
        $this->assertSame([$a->id => 1, $b->id => 2], ClubCompetitionFinish::pluck('placement', 'user_id')->all());
        $this->assertSame(90, ClubCompetitionFinish::where('user_id', $b->id)->value('total_score'));
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.finalized')->count());

        // AT-CMP-16: a second tick / finalize changes nothing.
        $ids = ClubCompetitionFinish::orderBy('id')->pluck('id')->all();
        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('finalized: 0')->assertSuccessful();
        $this->assertFalse(app(ClubCompetitionCloseService::class)->finalize($c)['finalized']);
        $this->assertSame($ids, ClubCompetitionFinish::orderBy('id')->pluck('id')->all());
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.finalized')->count());
        $this->api($a)->getJson($this->competitionPath($c))->assertJsonPath('data.phase', 'finalized');
    }

    public function test_finalize_refuses_competitions_that_have_not_ended(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack]);
        $this->expectException(\App\Exceptions\ClubDomainException::class);
        app(ClubCompetitionCloseService::class)->finalize($c);
    }

    public function test_rebuild_standings_command_dry_run_apply_and_refinalize(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], ['ends_at' => now()->addHour()]);
        $user = $this->member('player');
        $this->play($user, $c, $pack, [100, 50]);

        $this->artisan('ballspot:clubs:rebuild-standings', ['--dry-run' => true])->expectsOutputToContain('Dry run: 0 differences.')->assertSuccessful();

        // Drift the derived data.
        ClubCompetitionEntry::query()->update(['total_score' => 5]);
        $this->artisan('ballspot:clubs:rebuild-standings', ['competition' => $c->public_id, '--dry-run' => true])
            ->expectsOutputToContain('entry user:' . $user->id . ' pack:- total_score 5 → 150')
            ->expectsOutputToContain('Dry run: 1 difference.')->assertSuccessful();
        $this->assertSame(5, ClubCompetitionEntry::first()->total_score);

        $this->artisan('ballspot:clubs:rebuild-standings', ['competition' => (string) $c->id])->expectsOutputToContain('Done: 1 difference.')->assertSuccessful();
        $this->assertSame(150, ClubCompetitionEntry::first()->total_score);
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.rebuilt')->count());

        // Finalized: skipped without --refinalize, rewritten with it.
        Carbon::setTestNow(now()->addHours(2));
        app(ClubCompetitionCloseService::class)->finalize($c);
        ClubCompetitionEntry::query()->update(['total_score' => 7]);
        ClubCompetitionFinish::query()->update(['total_score' => 7]);
        $this->artisan('ballspot:clubs:rebuild-standings', ['competition' => $c->public_id])->expectsOutputToContain('use --refinalize')->assertSuccessful();
        $this->assertSame(7, ClubCompetitionEntry::first()->total_score);
        $this->artisan('ballspot:clubs:rebuild-standings', ['competition' => $c->public_id, '--refinalize' => true])
            ->expectsOutputToContain('finishes rewritten.')->assertSuccessful();
        $this->assertSame(150, ClubCompetitionEntry::first()->total_score);
        $this->assertSame(150, ClubCompetitionFinish::first()->total_score);

        $this->artisan('ballspot:clubs:rebuild-standings', ['competition' => 'nope'])->assertFailed();
    }

    public function test_reconcile_counters(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack]);
        $this->play($this->member('player'), $c, $pack, [100]);
        $c->forceFill(['participants_count' => 99])->saveQuietly();
        \DB::table('clubs')->where('id', $this->club->id)->update(['members_count' => 0, 'live_competitions_count' => 0]);

        $this->artisan('ballspot:clubs:reconcile-counters')->assertSuccessful();
        $this->assertSame(1, $c->fresh()->participants_count);
        $this->assertSame(2, $this->club->fresh()->members_count);
        $this->assertSame(1, $this->club->fresh()->live_competitions_count);
    }

    public function test_commands_are_scheduled_and_the_tick_is_feature_gated(): void
    {
        Artisan::call('list'); // boot console routes
        $events = collect(app(Schedule::class)->events());
        $tick = $events->first(fn ($e) => str_contains((string) $e->command, 'ballspot:clubs:competition-tick'));
        $nightly = $events->first(fn ($e) => str_contains((string) $e->command, 'ballspot:clubs:reconcile-counters'));

        $this->assertNotNull($tick);
        $this->assertSame('* * * * *', $tick->expression);
        $this->assertTrue($tick->withoutOverlapping);
        $this->assertNotNull($nightly);
        $this->assertSame('20 3 * * *', $nightly->expression);

        config(['ballspot.clubs.enabled' => false]);
        $this->assertFalse($tick->filtersPass($this->app));
        config(['ballspot.clubs.enabled' => true]);
        $this->assertTrue($tick->filtersPass($this->app));
    }
}
