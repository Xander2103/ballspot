<?php

namespace Tests\Feature\Clubs\Security;

use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengePack;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\PackAttempt;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionService;
use Carbon\Carbon;
use Tests\Feature\Clubs\Competitions\CompetitionTestCase;

/**
 * Security review 2026-10, finding 1 (HIGH): the answer key must never cross
 * from one track to another. A reveal (ball_x/y) on the normal track — or in
 * a second competition on the same pack — used to be obtainable while a
 * competition attempt on that pack was open, so the player could submit the
 * exact position to the competition.
 *
 *  a) normal attempts on a pack reserved by a `blocked` competition are frozen
 *     (409 pack_reserved_for_competition) and resume after finalize/cancel/void;
 *  b) `allowed`: no competition start while a normal attempt is active, no
 *     normal play while a competition attempt is active; sequential play stays;
 *  c) no_replay counts any run with at least one guess;
 *  d) one published competition per pack and overlapping window.
 */
class CompetitionAnswerKeyTest extends CompetitionTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    /** A pack whose balls are NOT at the centre, so a leaked position matters. */
    private function secretPack(int $challenges = 2): ChallengePack
    {
        $pack = $this->makeClubPack($this->club, $challenges);
        foreach ($pack->challenges as $i => $challenge) {
            $challenge->update(['ball_x_ratio' => 0.83 - $i * 0.1, 'ball_y_ratio' => 0.27 + $i * 0.1]);
        }

        return $pack->fresh();
    }

    /** Publish a draft through the real service (overlap + playability checks). */
    private function publishLive(array $packs, array $attrs = []): ClubCompetition
    {
        $draft = $this->makeCompetition($this->club, $packs, array_merge([
            'starts_at' => now()->subMinute(),
            'ends_at'   => now()->addDays(7),
        ], $attrs));

        return app(ClubCompetitionService::class)->publish($draft, $this->admin);
    }

    private function currentNormalChallengeId(int $attemptId): int
    {
        $attempt = PackAttempt::findOrFail($attemptId);

        return (int) $attempt->challengeIds()[$attempt->current_index];
    }

    // ------------------------------------------------------------ the exploit

    public function test_exploit_normal_attempt_started_before_publish_cannot_leak_the_answer_to_a_blocked_competition(): void
    {
        foreach (['single_attempt', 'no_replay', 'multiple_attempts'] as $mode) {
            $pack   = $this->secretPack();
            $player = $this->member('p_' . $mode);

            // 1. Normal attempt started BEFORE the competition is published (no guess yet).
            $normalId = (int) $this->startClubPack($player, $this->club, $pack)->assertOk()->json('attempt.id');

            // 2. A blocked competition goes live on that pack.
            $rules = $mode === 'multiple_attempts' ? $this->multiple(2, 'best') : ['replay_mode' => $mode];
            $c = $this->publishLive([$pack], $rules + ['normal_play_policy' => 'blocked']);

            // 3. The competition attempt starts (the frozen normal attempt does not block it).
            $attemptId = (int) $this->startAttempt($player, $c, $pack)->assertCreated()->json('attempt.id');

            // 4. Guess on the NORMAL attempt first to read ball_x/y → refused, no reveal.
            $leak = $this->api($player)->postJson("/api/pack-attempts/{$normalId}/guess", [
                'challenge_id' => $this->currentNormalChallengeId($normalId),
                'guessed_x'    => 0.1,
                'guessed_y'    => 0.1,
            ]);
            $leak->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition')->assertJsonMissingPath('result');
            $this->assertSame(0, PackAttempt::find($normalId)->current_index, "{$mode}: the normal attempt stays frozen");

            // The competition guess is scored against the real position only.
            $this->guess($player, $attemptId, 40)->assertOk()->assertJsonPath('result.score', fn ($s) => $s < 100);

            app(ClubCompetitionService::class)->void($c->fresh(), $this->admin, 'test cleanup');
        }
    }

    public function test_exploit_two_live_competitions_on_one_pack_cannot_be_set_up_or_played_in_parallel(): void
    {
        $pack   = $this->secretPack();
        $player = $this->member('player');
        $first  = $this->publishLive([$pack]);

        // Through the service (admin + portal paths): refused at create, link and publish.
        $service = app(ClubCompetitionService::class);
        try {
            $service->create($this->club, [
                'name' => 'Second cup', 'starts_at' => now()->addHour(), 'ends_at' => now()->addDays(2), 'pack_ids' => [$pack->id],
            ], $this->admin);
            $this->fail('An overlapping competition on the same pack was created.');
        } catch (ClubDomainException $e) {
            $this->assertSame(ClubDomainException::COMPETITION_INVALID_PACK, $e->errorCode());
            $this->assertSame('pack_in_overlapping_competition', $e->extra()['reason']);
            $this->assertSame($pack->id, $e->extra()['pack_id']);
            $this->assertSame($first->public_id, $e->extra()['competition_id']);
            $this->assertStringContainsString($first->name, $e->getMessage());
        }

        // Legacy data with two overlapping live competitions: no parallel attempts.
        $second = $this->liveCompetition([$pack], ['name' => 'Legacy parallel cup']);
        $a = (int) $this->startAttempt($player, $first, $pack)->assertCreated()->json('attempt.id');
        $this->api($player)->getJson($this->competitionPath($second))->assertOk()
            ->assertJsonPath('data.packs.0.state.blocked_reason', 'attempt_in_progress')
            ->assertJsonPath('data.packs.0.state.active_attempt_id', null)
            ->assertJsonPath('data.packs.0.state.can_start', false);
        $this->startAttempt($player, $second, $pack)->assertStatus(409)->assertJsonPath('code', 'competition_attempt_in_progress');
        $this->assertSame(1, ClubCompetitionAttempt::where('user_id', $player->id)->count());

        // Finishing the first attempt releases the pack for the other competition.
        $this->guess($player, $a)->assertOk();
        $this->guess($player, $a)->assertOk()->assertJsonPath('pack_completed', true);
        $this->startAttempt($player, $second, $pack)->assertCreated();
    }

    // ------------------------------------------------------------ a) blocked: frozen, resumes

    public function test_frozen_normal_attempt_resumes_after_cancel_finalize_and_void(): void
    {
        $service = app(ClubCompetitionService::class);
        $player  = $this->member('player');

        // Cancel (upcoming).
        $p1 = $this->secretPack();
        $n1 = (int) $this->startClubPack($player, $this->club, $p1)->assertOk()->json('attempt.id');
        $up = $service->publish($this->makeCompetition($this->club, [$p1], ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]), $this->admin);
        $this->guessCurrent($player, $n1)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition');
        $service->cancel($up, $this->admin);
        $this->guessCurrent($player, $n1)->assertOk();

        // Void (live).
        $p2 = $this->secretPack();
        $n2 = (int) $this->startClubPack($player, $this->club, $p2)->assertOk()->json('attempt.id');
        $live = $this->publishLive([$p2]);
        $this->guessCurrent($player, $n2)->assertStatus(409);
        $service->void($live, $this->admin, 'Wrong photos');
        $this->guessCurrent($player, $n2)->assertOk();

        // Ended is still reserved; finalize releases.
        $p3 = $this->secretPack();
        $n3 = (int) $this->startClubPack($player, $this->club, $p3)->assertOk()->json('attempt.id');
        $short = $this->publishLive([$p3], ['ends_at' => now()->addHour()]);
        Carbon::setTestNow(now()->addHours(2));
        $this->guessCurrent($player, $n3)->assertStatus(409);
        app(ClubCompetitionCloseService::class)->finalize($short->fresh());
        $this->guessCurrent($player, $n3)->assertOk();
    }

    // ------------------------------------------------------------ b) allowed: no parallel tracks

    public function test_allowed_refuses_a_competition_start_while_a_normal_attempt_is_active(): void
    {
        $pack   = $this->secretPack();
        $player = $this->member('player');
        $c      = $this->publishLive([$pack], ['normal_play_policy' => 'allowed']);

        $normalId = (int) $this->startClubPack($player, $this->club, $pack)->assertOk()->json('attempt.id');
        $this->guessCurrent($player, $normalId)->assertOk(); // the reveal of challenge 1 …

        $this->api($player)->getJson($this->competitionPath($c))->assertOk()
            ->assertJsonPath('data.packs.0.state.blocked_reason', 'attempt_in_progress')
            ->assertJsonPath('data.packs.0.state.can_start', false);
        // … cannot be submitted to a competition attempt opened in parallel.
        $this->startAttempt($player, $c, $pack)->assertStatus(409)->assertJsonPath('code', 'competition_attempt_in_progress');
        $this->assertSame(0, ClubCompetitionAttempt::count());

        // Sequential play stays allowed by design (single_attempt): finish, then compete.
        $this->guessCurrent($player, $normalId)->assertOk()->assertJsonPath('pack_completed', true);
        $this->startAttempt($player, $c, $pack)->assertCreated();
    }

    public function test_allowed_refuses_normal_play_while_a_competition_attempt_is_active(): void
    {
        $pack   = $this->secretPack();
        $player = $this->member('player');
        $c      = $this->publishLive([$pack], ['normal_play_policy' => 'allowed']);
        $attemptId = (int) $this->startAttempt($player, $c, $pack)->assertCreated()->json('attempt.id');

        $this->startClubPack($player, $this->club, $pack)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition');
        $this->assertSame(0, PackAttempt::count());

        // A normal attempt that exists anyway (lost race) cannot guess either.
        $normal = PackAttempt::create(['user_id' => $player->id, 'challenge_pack_id' => $pack->id, 'status' => 'active', 'started_at' => now(),
            'current_index' => 0, 'total_score' => 0, 'metadata' => ['challenge_ids' => $pack->challenges()->pluck('challenges.id')->all()]]);
        $this->guessCurrent($player, $normal->id)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition')->assertJsonMissingPath('result');

        // Other members are not affected (allowed = the pack stays playable).
        $other = $this->member('other');
        $this->startClubPack($other, $this->club, $pack)->assertOk();

        // Once the competition attempt is finished, normal play resumes.
        $this->guess($player, $attemptId)->assertOk();
        $this->guess($player, $attemptId)->assertOk()->assertJsonPath('pack_completed', true);
        $this->guessCurrent($player, $normal->id)->assertOk();
    }

    public function test_an_expired_competition_attempt_no_longer_blocks_normal_play(): void
    {
        $pack   = $this->secretPack();
        $player = $this->member('player');
        $c      = $this->publishLive([$pack], ['normal_play_policy' => 'allowed', 'ends_at' => now()->addHour()]);
        $this->startAttempt($player, $c, $pack)->assertCreated();
        $this->assertSame(CompetitionAttemptStatus::Active, ClubCompetitionAttempt::firstOrFail()->status);

        Carbon::setTestNow(now()->addHours(2)); // window over, row not expired yet
        $this->startClubPack($player, $this->club, $pack)->assertOk();
    }

    // ------------------------------------------------------------ c) no_replay = any guess

    public function test_no_replay_counts_any_run_with_a_guess_not_only_completed_ones(): void
    {
        $pack = $this->secretPack(3);

        // Normal attempt with one guess (current_index 1) before the competition.
        $partial = $this->member('partial');
        $id = (int) $this->startClubPack($partial, $this->club, $pack)->assertOk()->json('attempt.id');
        $this->guessCurrent($partial, $id)->assertOk();

        // Normal attempt started but never guessed: reveals nothing, still eligible.
        $started = $this->member('started');
        $this->startClubPack($started, $this->club, $pack)->assertOk();

        // An earlier competition: one guess (attempt expired) vs no guess.
        $earlier = $this->liveCompetition([$pack], ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2), 'state' => CompetitionState::Finalized]);
        $guessed = $this->member('guessed');
        $idle    = $this->member('idle');
        foreach ([[$guessed, 1], [$idle, 0]] as [$user, $guesses]) {
            ClubCompetitionAttempt::create([
                'club_competition_id' => $earlier->id, 'challenge_pack_id' => $pack->id, 'user_id' => $user->id,
                'attempt_number' => 1, 'status' => CompetitionAttemptStatus::Expired, 'active_slot' => null,
                'challenge_ids' => $pack->challenges()->pluck('challenges.id')->all(), 'started_at' => now()->subDays(3),
                'guesses_count' => $guesses, 'current_index' => $guesses,
            ]);
        }

        $c = $this->publishLive([$pack], ['replay_mode' => 'no_replay']);

        foreach ([$partial, $guessed] as $user) {
            $this->api($user)->getJson($this->competitionPath($c))->assertJsonPath('data.packs.0.state.blocked_reason', 'already_played');
            $this->startAttempt($user, $c, $pack)->assertStatus(422)->assertJsonPath('code', 'competition_pack_already_played');
        }
        foreach ([$started, $idle] as $user) {
            $this->startAttempt($user, $c, $pack)->assertCreated();
        }
    }

    // ------------------------------------------------------------ d) one live competition per pack

    public function test_overlap_rule_on_create_update_publish_and_its_exceptions(): void
    {
        $service = app(ClubCompetitionService::class);
        $pack    = $this->secretPack();
        $other   = $this->secretPack();
        $base    = ['name' => 'Cup', 'pack_ids' => [$pack->id]];
        $first   = $service->publish($service->create($this->club, $base + ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(5)], $this->admin), $this->admin);

        $refused = function (callable $fn): void {
            try {
                $fn();
                $this->fail('Expected competition_invalid_pack (overlap).');
            } catch (ClubDomainException $e) {
                $this->assertSame(ClubDomainException::COMPETITION_INVALID_PACK, $e->errorCode());
                $this->assertSame('pack_in_overlapping_competition', $e->extra()['reason'] ?? null);
            }
        };

        // Create overlapping → refused; adjacent windows ([a, b) then [b, c)) → fine.
        $refused(fn () => $service->create($this->club, $base + ['starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(8)], $this->admin));
        $next = $service->create($this->club, $base + ['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(8)], $this->admin);

        // Moving the window into the overlap, or linking the pack later → refused.
        $refused(fn () => $service->update($next, ['starts_at' => now()->addDays(3)], $this->admin, $next->version));
        $draft = $service->create($this->club, ['name' => 'Other', 'pack_ids' => [$other->id], 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)], $this->admin);
        $refused(fn () => $service->update($draft, ['pack_ids' => [$other->id, $pack->id]], $this->admin, $draft->version));

        // Drafts do not conflict with each other; the second publish does.
        $d1 = $service->create($this->club, ['name' => 'D1', 'pack_ids' => [$other->id], 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(12)], $this->admin);
        $d2 = $service->create($this->club, ['name' => 'D2', 'pack_ids' => [$other->id], 'starts_at' => now()->addDays(11), 'ends_at' => now()->addDays(13)], $this->admin);
        $service->publish($d1, $this->admin);
        $refused(fn () => $service->publish($d2, $this->admin));

        // A cancelled competition no longer holds the pack.
        $service->cancel($first->fresh(), $this->admin);
        $this->assertSame(CompetitionState::Published, $service->publish($service->create($this->club, $base + ['starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(4)], $this->admin), $this->admin)->state);

        // Another club's competitions never conflict.
        $otherClub = $this->makeClub();
        $foreign   = $this->makeClubPack($otherClub, 1);
        $service->publish($service->create($otherClub, ['name' => 'Theirs', 'pack_ids' => [$foreign->id], 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4)], $this->admin), $this->admin);
    }

    public function test_overlap_rule_reaches_the_admin_form_with_a_clear_message(): void
    {
        $pack = $this->secretPack();
        $this->publishLive([$pack], ['name' => 'Running cup']);

        $this->actingAs($this->admin)->from('/admin/clubs/' . $this->club->id . '/competitions/create')
            ->post('/admin/clubs/' . $this->club->id . '/competitions', [
                'name' => 'Clash', 'starts_at' => '2026-11-12T10:00', 'ends_at' => '2026-11-20T10:00',
                'replay_mode' => 'single_attempt', 'normal_play_policy' => 'blocked',
                'pack_ids_present' => 1, 'pack_ids' => [$pack->id],
            ])
            ->assertRedirect('/admin/clubs/' . $this->club->id . '/competitions/create')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Running cup') && str_contains($m, $pack->name));
        $this->assertSame(1, ClubCompetition::count());
    }

    // ------------------------------------------------------------ unchanged surfaces

    public function test_public_pack_flow_is_unchanged_with_a_club_competition_running(): void
    {
        $public = $this->makePublicPack(2);
        $club   = $this->secretPack();
        $player = $this->member('player');
        $c      = $this->publishLive([$club]);
        $this->startAttempt($player, $c, $club)->assertCreated();

        $id = (int) $this->api($player)->postJson('/api/packs/' . $public->slug . '/start')->assertOk()->json('attempt.id');
        $this->guessCurrent($player, $id)->assertOk();
        $this->guessCurrent($player, $id)->assertOk()->assertJsonPath('pack_completed', true);

        // Flag off: public packs still play, club packs and competitions are gone.
        config(['ballspot.clubs.enabled' => false]);
        $public2 = $this->makePublicPack(1);
        $id2 = (int) $this->api($player)->postJson('/api/packs/' . $public2->slug . '/start')->assertOk()->json('attempt.id');
        $this->guessCurrent($player, $id2)->assertOk();
    }
}
