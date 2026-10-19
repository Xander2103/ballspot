<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengePack;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubCompetitionPack;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionRulesGuard;
use App\Services\Clubs\ClubCompetitionService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ClubCompetitionService + RulesGuard: create / publish / cancel / void /
 * extend, editability matrix and rule lock (AT-CMP-11, AT-ADM-07 at the
 * service level, §4.8 invariants, optimistic version).
 */
class CompetitionLifecycleTest extends CompetitionTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'username' => 'admin']);
    }

    private function service(): ClubCompetitionService
    {
        return app(ClubCompetitionService::class);
    }

    private function draft(array $packs, array $data = []): ClubCompetition
    {
        return $this->service()->create($this->club, array_merge([
            'name'      => 'Autumn Cup',
            'starts_at' => now()->addDay(),
            'ends_at'   => now()->addDays(10),
            'pack_ids'  => array_map(fn (ChallengePack $p) => $p->id, $packs),
        ], $data), $this->admin);
    }

    private function assertDomain(string $code, \Closure $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$code}.");
        } catch (ClubDomainException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    private function assertInvalid(string $field, \Closure $fn): void
    {
        try {
            $fn();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_create_defaults_rules_and_audits(): void
    {
        $a = $this->makeClubPack($this->club, 2);
        $b = $this->makeClubPack($this->club, 2);
        $c = $this->draft([$b, $a]);

        $this->assertSame(CompetitionState::Draft, $c->state);
        $this->assertSame('single_attempt', $c->replay_mode->value);
        $this->assertSame(1, $c->max_attempts);
        $this->assertSame('first', $c->score_rule->value);
        $this->assertSame('blocked', $c->normal_play_policy->value);
        $this->assertSame('Europe/Brussels', $c->timezone);
        $this->assertSame([$b->id, $a->id], ClubCompetitionPack::where('club_competition_id', $c->id)->orderBy('sort_order')->pluck('challenge_pack_id')->all());
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.created')->count());
        $this->assertSame(2, ClubAuditEvent::where('action', 'pack.linked')->count());

        $multi = $this->draft([$a], ['replay_mode' => 'multiple_attempts', 'max_attempts' => 4, 'score_rule' => 'best']);
        $this->assertSame([4, 'best'], [$multi->max_attempts, $multi->score_rule->value]);
        // Single-attempt modes force max 1 + first.
        $single = $this->draft([$a], ['replay_mode' => 'no_replay', 'max_attempts' => 5, 'score_rule' => 'best']);
        $this->assertSame([1, 'first'], [$single->max_attempts, $single->score_rule->value]);
    }

    public function test_window_and_rule_validation(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $this->assertInvalid('ends_at', fn () => $this->draft([$pack], ['ends_at' => now()->addDay()]));
        $this->assertInvalid('ends_at', fn () => $this->draft([$pack], ['ends_at' => now()->addDays(200)]));
        $this->assertInvalid('max_attempts', fn () => $this->draft([$pack], ['replay_mode' => 'multiple_attempts', 'max_attempts' => 1]));
        $this->assertInvalid('max_attempts', fn () => $this->draft([$pack], ['replay_mode' => 'multiple_attempts', 'max_attempts' => 11]));
    }

    public function test_at_adm_07_linking_another_clubs_pack_or_a_public_pack_is_a_validation_error(): void
    {
        $other = $this->makeClub(['name' => 'Other club']);
        $foreign = $this->makeClubPack($other, 1);
        $public  = $this->makePublicPack(1);
        $own     = $this->makeClubPack($this->club, 1);

        $this->assertInvalid('pack_ids', fn () => $this->draft([$own, $foreign]));
        $this->assertInvalid('pack_ids', fn () => $this->draft([$public]));
        $c = $this->draft([$own]);
        $this->assertInvalid('pack_ids', fn () => $this->service()->update($c, ['pack_ids' => [$own->id, $foreign->id]], $this->admin, $c->version));
        $this->assertSame(0, ClubCompetition::where('name', '!=', 'Autumn Cup')->count());
    }

    public function test_publish_requires_playable_packs_of_the_club(): void
    {
        $empty = $this->makeClubPack($this->club, 0);
        $draftPack = $this->makeClubPack($this->club, 2, ['status' => ChallengePack::STATUS_DRAFT]);
        $ok = $this->makeClubPack($this->club, 2);

        $this->assertDomain('competition_invalid_pack', fn () => $this->service()->publish($this->draft([]), $this->admin));
        $this->assertDomain('competition_invalid_pack', fn () => $this->service()->publish($this->draft([$ok, $empty]), $this->admin));
        $this->assertDomain('competition_invalid_pack', fn () => $this->service()->publish($this->draft([$draftPack]), $this->admin));

        $c = $this->service()->publish($this->draft([$ok]), $this->admin);
        $this->assertSame(CompetitionState::Published, $c->state);
        $this->assertSame('upcoming', $c->phase()->value);
        $this->assertNull($c->rules_locked_at);
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.published')->count());
        $this->assertDomain('competition_not_editable', fn () => $this->service()->publish($c, $this->admin));
        $this->assertSame(1, $this->club->fresh()->live_competitions_count);
    }

    public function test_stale_version_is_refused(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c = $this->draft([$pack]);
        $this->service()->update($c, ['name' => 'Renamed'], $this->admin, $c->version);
        $this->assertDomain('stale_version', fn () => $this->service()->update($c, ['name' => 'Lost update'], $this->admin, $c->version));
        $this->assertSame('Renamed', $c->fresh()->name);
    }

    public function test_upcoming_competitions_stay_editable_and_audited(): void
    {
        $a = $this->makeClubPack($this->club, 1);
        $b = $this->makeClubPack($this->club, 1);
        $c = $this->service()->publish($this->draft([$a]), $this->admin);

        $c = $this->service()->update($c, ['replay_mode' => 'multiple_attempts', 'max_attempts' => 3, 'score_rule' => 'best',
            'pack_ids' => [$b->id, $a->id], 'starts_at' => now()->addHours(2)], $this->admin, $c->version);
        $this->assertSame(['multiple_attempts', 3, 'best'], [$c->replay_mode->value, $c->max_attempts, $c->score_rule->value]);
        $fields = ClubAuditEvent::where('action', 'competition.updated')->latest('id')->first()->metadata['fields'];
        $this->assertEqualsCanonicalizing(['replay_mode', 'max_attempts', 'score_rule', 'pack_ids', 'starts_at'], $fields);

        // An upcoming competition cannot be moved to a start in the past.
        $this->assertInvalid('starts_at', fn () => $this->service()->update($c, ['starts_at' => now()->subMinute()], $this->admin, $c->version));
    }

    public function test_at_cmp_11_rules_lock_at_start_and_ends_at_may_only_be_extended(): void
    {
        $a = $this->makeClubPack($this->club, 1);
        $b = $this->makeClubPack($this->club, 1);
        $c = $this->service()->publish($this->draft([$a], ['starts_at' => now()->addMinutes(5)]), $this->admin);
        Carbon::setTestNow($c->starts_at->copy()); // the scheduler has NOT run
        $c = $c->fresh();
        $this->assertNull($c->rules_locked_at);
        $this->assertTrue($c->isLocked());

        foreach ([
            ['replay_mode' => 'no_replay'],
            ['replay_mode' => 'multiple_attempts', 'max_attempts' => 3],
            ['normal_play_policy' => 'allowed'],
            ['pack_ids' => [$a->id, $b->id]],
            ['starts_at' => now()->addDay()],
            ['ends_at' => $c->ends_at->copy()->subHour()],
        ] as $change) {
            $this->assertDomain('competition_rules_locked', fn () => $this->service()->update($c->fresh(), $change, $this->admin, $c->fresh()->version));
        }

        // Name/description stay editable; unchanged rule values are not a change.
        $c = $this->service()->update($c->fresh(), ['name' => 'Renamed', 'replay_mode' => 'single_attempt', 'pack_ids' => [$a->id]], $this->admin, $c->fresh()->version);
        $this->assertSame('Renamed', $c->name);

        // Extend: allowed and audited separately.
        $from = $c->ends_at->copy();
        $c = $this->service()->extend($c, $from->copy()->addDays(2), $this->admin, $c->version);
        $this->assertTrue($c->ends_at->equalTo($from->copy()->addDays(2)));
        $event = ClubAuditEvent::where('action', 'competition.ends_at_extended')->firstOrFail();
        $this->assertSame([$from->format(DATE_ATOM), $c->ends_at->format(DATE_ATOM)], [$event->metadata['ends_at_from'], $event->metadata['ends_at_to']]);
        $this->assertDomain('competition_rules_locked', fn () => $this->service()->extend($c, $c->ends_at->copy()->subMinute(), $this->admin, $c->version));

        // Ended: ends_at locked too, cancel impossible.
        Carbon::setTestNow($c->ends_at->copy()->addMinute());
        $this->assertDomain('competition_rules_locked', fn () => $this->service()->extend($c->fresh(), $c->ends_at->copy()->addDay(), $this->admin, $c->fresh()->version));
        $this->assertDomain('competition_rules_locked', fn () => $this->service()->cancel($c->fresh(), $this->admin));
    }

    public function test_lock_is_idempotent_and_snapshots_every_pack(): void
    {
        $a = $this->makeClubPack($this->club, 3);
        $b = $this->makeClubPack($this->club, 2);
        $c = $this->service()->publish($this->draft([$a, $b], ['starts_at' => now()->addMinute()]), $this->admin);
        $guard = app(ClubCompetitionRulesGuard::class);

        $this->assertFalse($guard->lock($this->draft([$this->makeClubPack($this->club, 1)])), 'drafts never lock');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertTrue($guard->lock($c));
        $this->assertFalse($guard->lock($c->fresh()));
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.rules_locked')->count());
        $links = ClubCompetitionPack::where('club_competition_id', $c->id)->orderBy('sort_order')->get();
        $this->assertSame([3, 2], $links->map(fn ($l) => count($l->snapshotIds()))->all());
        $this->assertNotNull($links[0]->snapshotted_at);
    }

    public function test_publishing_an_already_started_window_locks_immediately(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c = $this->draft([$pack]);
        Carbon::setTestNow($c->starts_at->copy()->addMinute());
        $c = $this->service()->publish($c->fresh(), $this->admin);
        $this->assertNotNull($c->rules_locked_at);
        $this->assertSame('active', $c->phase()->value);
    }

    public function test_cancel_before_start_only(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $draft = $this->service()->cancel($this->draft([$pack]), $this->admin, 'Typo');
        $this->assertSame(CompetitionState::Cancelled, $draft->state);
        $this->assertDomain('competition_not_editable', fn () => $this->service()->update($draft, ['replay_mode' => 'no_replay'], $this->admin, $draft->version));
        $this->assertSame('Typo', ClubAuditEvent::where('action', 'competition.cancelled')->first()->metadata['reason']);

        $upcoming = $this->service()->publish($this->draft([$pack]), $this->admin);
        $this->assertSame(CompetitionState::Cancelled, $this->service()->cancel($upcoming, $this->admin)->state);

        $live = $this->liveCompetition([$pack]);
        $this->assertDomain('competition_rules_locked', fn () => $this->service()->cancel($live, $this->admin));
    }

    public function test_void_competition_expires_attempts_and_withdraws_finishes(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], ['ends_at' => now()->addHour()]);
        $user = $this->member('player');
        $id   = $this->play($user, $c, $pack, [100]);

        $this->assertDomain('competition_not_editable', fn () => $this->service()->void($this->draft([$pack]), $this->admin, 'x'));

        $voided = $this->service()->void($c->fresh(), $this->admin, 'Leaked photos');
        $this->assertSame('voided', $voided->phase()->value);
        $this->assertSame('expired', ClubCompetitionAttempt::find($id)->status->value);
        $this->assertSame('Leaked photos', ClubAuditEvent::where('action', 'competition.voided')->first()->metadata['reason']);
        $this->api($user)->getJson($this->competitionPath($c))->assertOk()->assertJsonPath('data.phase', 'voided')
            ->assertJsonPath('data.packs.0.state.blocked_reason', 'ended');

        // A finalized one loses its finishes.
        $other = $this->liveCompetition([$pack], ['ends_at' => now()->addHour(), 'normal_play_policy' => 'allowed']);
        $this->play($user, $other, $pack, [100, 100]);
        Carbon::setTestNow(now()->addHours(2));
        app(ClubCompetitionCloseService::class)->finalize($other);
        $this->assertSame(1, ClubCompetitionFinish::where('club_competition_id', $other->id)->count());
        $this->service()->void($other->fresh(), $this->admin, 'Recount');
        $this->assertSame(0, ClubCompetitionFinish::where('club_competition_id', $other->id)->count());
    }

    public function test_void_attempt_after_finalize_refinalizes(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack], ['ends_at' => now()->addHour()]);
        $cheat = $this->member('cheat');
        $fair  = $this->member('fair');
        $cheatId = $this->play($cheat, $c, $pack, [100]);
        $this->play($fair, $c, $pack, [60]);
        Carbon::setTestNow(now()->addHours(2));
        app(ClubCompetitionCloseService::class)->finalize($c);
        $this->assertSame(1, ClubCompetitionFinish::where('user_id', $cheat->id)->value('placement'));

        $this->service()->voidAttempt(ClubCompetitionAttempt::find($cheatId), $this->admin, 'Cheating');

        $this->assertSame([$fair->id => 1], ClubCompetitionFinish::pluck('placement', 'user_id')->all());
        $this->assertSame(1, ClubAuditEvent::where('action', 'competition.rebuilt')->count());
        $this->assertDomain('attempt_not_active', fn () => $this->service()->voidAttempt(ClubCompetitionAttempt::find($cheatId), $this->admin, 'again'));
    }

    public function test_void_attempt_before_start_is_refused(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c = $this->service()->publish($this->draft([$pack]), $this->admin);
        $attempt = ClubCompetitionAttempt::factory()->create(['club_competition_id' => $c->id, 'challenge_pack_id' => $pack->id]);
        $this->assertDomain('competition_not_editable', fn () => $this->service()->voidAttempt($attempt, $this->admin, 'x'));
    }
}
