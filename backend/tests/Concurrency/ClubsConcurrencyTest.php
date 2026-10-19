<?php

namespace Tests\Concurrency;

use App\Enums\NormalPlayPolicy;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionAttemptGuess;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubMember;
use App\Models\ClubPackRewardClaim;
use App\Models\PackAttempt;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\Clubs\ClubCompetitionPlayService;
use App\Services\Clubs\ClubInviteCodeService;

/**
 * Parallel processes racing on the same club rows (see ConcurrencyTestCase
 * for how to run). Each scenario asserts the invariant the row locks and
 * unique constraints are meant to guarantee.
 */
class ClubsConcurrencyTest extends ConcurrencyTestCase
{
    public function test_parallel_joins_never_exceed_max_members(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub(['max_members' => 5], $owner);
        $code  = app(ClubInviteCodeService::class)->create($club, [], $owner, 'platform_admin');
        $users = User::factory()->count(16)->create();

        $results = $this->runWorkers('join', $users->map(fn ($u) => ['user_id' => $u->id, 'code' => $code->code])->all());

        $this->assertSame(4, $this->okCount($results), json_encode($results));
        $this->assertSame(5, ClubMember::where('club_id', $club->id)->where('status', 'active')->count());
        $this->assertSame(4, (int) $code->fresh()->uses_count);
        $this->assertSame(5, (int) $club->fresh()->members_count);
        foreach ($results as $r) {
            if (!$r['ok']) {
                $this->assertSame('club_full', $r['code'], json_encode($r));
            }
        }
    }

    public function test_parallel_joins_respect_code_max_uses(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $code  = app(ClubInviteCodeService::class)->create($club, ['max_uses' => 3], $owner, 'platform_admin');
        $users = User::factory()->count(12)->create();

        $results = $this->runWorkers('join', $users->map(fn ($u) => ['user_id' => $u->id, 'code' => $code->code])->all());

        $this->assertSame(3, $this->okCount($results), json_encode($results));
        $this->assertSame(3, (int) $code->fresh()->uses_count);
        $this->assertSame(4, ClubMember::where('club_id', $club->id)->where('status', 'active')->count());
    }

    public function test_same_user_parallel_joins_create_one_membership(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $code  = app(ClubInviteCodeService::class)->create($club, [], $owner, 'platform_admin');
        $user  = User::factory()->create();

        $results = $this->runWorkers('join', array_fill(0, 8, ['user_id' => $user->id, 'code' => strtolower($code->code)]));

        $this->assertSame(8, $this->okCount($results), json_encode($results));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['joined'] === true)));
        $this->assertSame(1, ClubMember::where('club_id', $club->id)->where('user_id', $user->id)->count());
        $this->assertSame(1, (int) $code->fresh()->uses_count);
    }

    public function test_parallel_competition_starts_create_one_attempt(): void
    {
        [$user, $competition, $pack] = $this->liveCompetition();

        $results = $this->runWorkers('competition_start', array_fill(0, 10, [
            'user_id' => $user->id, 'competition_id' => $competition->id, 'pack_id' => $pack->id, 'attempt_number' => 1,
        ]));

        $this->assertSame(10, $this->okCount($results), json_encode($results));
        $this->assertSame(1, count(array_unique(array_column($results, 'attempt_id'))));
        $this->assertSame(1, ClubCompetitionAttempt::where('club_competition_id', $competition->id)->count());
        $this->assertSame(1, ClubPackRewardClaim::where('user_id', $user->id)->count());
    }

    public function test_parallel_next_attempt_starts_never_exceed_max_attempts(): void
    {
        [$user, $competition, $pack] = $this->liveCompetition(['replay_mode' => 'multiple_attempts', 'max_attempts' => 2, 'score_rule' => 'best'], 1);
        $play = app(ClubCompetitionPlayService::class);
        $club = $competition->club;

        // Finish attempt 1 so attempt 2 becomes startable, then race 2 and 3.
        $first = $play->start($user, $club, $competition, $pack, 1)['attempt'];
        $play->guess($user, $first, $play->currentChallenge($first)->id, 0.5, 0.5);

        $payloads = [];
        foreach ([2, 2, 2, 3, 3, 3, 2, 3] as $n) {
            $payloads[] = ['user_id' => $user->id, 'competition_id' => $competition->id, 'pack_id' => $pack->id, 'attempt_number' => $n];
        }
        $results = $this->runWorkers('competition_start', $payloads);

        $this->assertSame(2, ClubCompetitionAttempt::where('club_competition_id', $competition->id)->count(), json_encode($results));
        $this->assertSame(1, ClubCompetitionAttempt::where('club_competition_id', $competition->id)->whereNotNull('active_slot')->count());
    }

    public function test_parallel_duplicate_guesses_store_once_and_keep_standings_consistent(): void
    {
        [$user, $competition, $pack] = $this->liveCompetition();
        $play    = app(ClubCompetitionPlayService::class);
        $attempt = $play->start($user, $competition->club, $competition, $pack, 1)['attempt'];
        $challenge = $play->currentChallenge($attempt);

        $results = $this->runWorkers('competition_guess', array_fill(0, 10, [
            'user_id' => $user->id, 'attempt_id' => $attempt->id, 'challenge_id' => $challenge->id, 'x' => 0.52, 'y' => 0.5,
        ]));

        $this->assertSame(10, $this->okCount($results), json_encode($results));
        $guesses = ClubCompetitionAttemptGuess::where('attempt_id', $attempt->id)->get();
        $this->assertCount(1, $guesses);
        $score = (int) $guesses->first()->score;
        $this->assertSame($score, (int) $attempt->fresh()->total_score);
        $this->assertSame($score, (int) ClubCompetitionEntry::where('club_competition_id', $competition->id)->where('user_id', $user->id)->value('total_score'));
        $this->assertSame(1, XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_CLUB_COMPETITION_GUESS)->count());
    }

    public function test_parallel_first_runs_on_both_tracks_claim_one_reward_and_never_run_in_parallel(): void
    {
        [$user, $competition, $pack] = $this->liveCompetition(['normal_play_policy' => NormalPlayPolicy::Allowed->value]);

        $payloads = [];
        for ($i = 0; $i < 10; $i++) {
            $payloads[] = $i % 2 === 0
                ? ['user_id' => $user->id, 'pack_id' => $pack->id]
                : ['user_id' => $user->id, 'competition_id' => $competition->id, 'pack_id' => $pack->id, 'attempt_number' => 1];
        }
        $scenarioOf = fn (int $i) => $i % 2 === 0 ? 'pack_start' : 'competition_start';

        $results = $this->runWorkers($scenarioOf, $payloads);

        // Security review 2026-10: under `allowed` a competition start is refused
        // while the player's normal run on the pack is active (and the normal
        // gate refuses the reverse), so the tracks never run in parallel. The
        // worker's pack_start calls PackPlayService directly (no gate), so only
        // the competition side can be refused here — always with the same code.
        $refused = array_values(array_filter($results, fn ($r) => !($r['ok'] ?? false)));
        foreach ($refused as $r) {
            $this->assertSame('competition_attempt_in_progress', $r['code'] ?? null, json_encode($results));
        }
        $this->assertGreaterThanOrEqual(5, $this->okCount($results), json_encode($results));
        $this->assertSame(1, ClubPackRewardClaim::where('user_id', $user->id)->where('challenge_pack_id', $pack->id)->count());
        $this->assertSame(1, PackAttempt::where('user_id', $user->id)->where('challenge_pack_id', $pack->id)->count());
        $this->assertLessThanOrEqual(1, ClubCompetitionAttempt::where('user_id', $user->id)->count());
        if ($refused !== []) {
            $this->assertSame(0, ClubCompetitionAttempt::where('user_id', $user->id)->count(), 'a refused start never leaves an attempt');
        }
    }

    /**
     * @return array{0: User, 1: ClubCompetition, 2: \App\Models\ChallengePack}
     */
    private function liveCompetition(array $attrs = [], int $challenges = 3): array
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);
        $user  = User::factory()->create();
        $this->addMember($club, $user);
        $pack  = $this->makeClubPack($club, $challenges);

        $competition = ClubCompetition::factory()->live()->create(array_merge(['club_id' => $club->id], $attrs));
        \App\Models\ClubCompetitionPack::create([
            'club_competition_id' => $competition->id,
            'challenge_pack_id'   => $pack->id,
            'sort_order'          => 0,
        ]);

        return [$user, $competition->fresh(), $pack];
    }
}
