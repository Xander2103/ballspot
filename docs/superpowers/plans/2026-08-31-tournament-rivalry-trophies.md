# Tournament Rivalry Text + Completion Trophies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show compact rivalry status ("You are leading by X points" / "X days left") on the active tournament screen, and award two new completion trophies (Sharpshooter, Most Consistent) plus a ≥3-players guard on the existing Top-3 badge — all through the existing badge system.

**Architecture:** Rivalry text is pure client-side math over the leaderboard response the tournament detail screen already fetches (`mobile/src/utils/rivalry.ts`, Jest-tested). Trophies hook into the existing `TournamentCompletionService::completeIfFinished()` transaction, which is already idempotent (atomic status claim + `user_badges` unique index + `BadgeService::award()` no-op on re-award). No new tables, no new endpoints, no notifications, no realtime.

**Tech Stack:** Laravel 11 (PHPUnit, SQLite in-memory tests), Expo/React Native TypeScript (ts-jest for `src/utils` only).

**Spec:** User prompt 2026-08-31 (rivalry text + tournament trophies). Key naming fact: tournaments are the `League` model everywhere in the backend; only finish records use "tournament" (`tournament_finishes`).

## Global Constraints

- No gambling / money / paid rewards — badges + XP ledger only.
- No push notifications, no chat, no realtime systems.
- Do not break old tournaments: already-earned badges stay; old leagues must still load; rivalry text hides (never crashes) when data is missing.
- Badges awarded once per user via `user_badges` unique(['user_id','badge_id']); `BadgeService::award()` returns null when already held.
- Trophies only on fresh completion of an `active` league (atomic claim `where status='active'`); never for lobby/cancelled; never double-awarded on replay.
- Reward gate: `config('ballspot.tournaments.min_players_for_rewards')` (=2) still withholds ALL trophies from solo tournaments.
- Skip (don't guess) any award whose fairness can't be determined.
- Validation: `cd backend && php artisan test`; `cd mobile && npx tsc --noEmit && npx expo export --platform web`.

---

### Task 1: Seed the two new badges (+ fix 37→39 count assertions)

**Files:**
- Modify: `backend/database/seeders/BadgeSeeder.php` (append to `$badges` array, before closing `];` at line 70)
- Modify: `backend/tests/Feature/BadgeTest.php:50,147,163` (37 → 39)
- Modify: `backend/tests/Feature/BadgeSprintV186Test.php:78-80` (rename + 37 → 39)

**Interfaces:**
- Produces: badge codes `sharpshooter` and `most_consistent` (category `tournament`, rarity `rare`) available to `BadgeService::award()`.

- [x] **Step 1: Append badges to the seeder** (after the `tournament_beast` line):

```php
            // --- v1.9.3 tournament skill trophies (awarded on completion) ------
            ['code' => 'sharpshooter',       'name' => 'Sharpshooter',       'icon' => '🏹', 'category' => 'tournament',  'rarity' => 'rare',      'description' => 'Hit the closest single guess of a tournament.'],
            ['code' => 'most_consistent',    'name' => 'Most Consistent',    'icon' => '📊', 'category' => 'tournament',  'rarity' => 'rare',      'description' => 'Post the best average score of a tournament.'],
```

(Names deliberately differ from the existing "Sharp Shooter" = `top_10_percent_daily` and "Sharp Scorer" = `sharp_scorer`.)

- [x] **Step 2: Update the three `37` assertions in `BadgeTest.php` and the one in `BadgeSprintV186Test.php` to `39`; rename `test_seeder_contains_37_badges_including_new_codes` → `test_seeder_contains_39_badges_including_new_codes`.**

- [x] **Step 3: Run** `php artisan test --filter=BadgeTest` → PASS.

- [x] **Step 4: Commit** `feat: seed sharpshooter + most_consistent tournament badges`

---

### Task 2: Top-3 badge requires ≥3 players

**Files:**
- Modify: `backend/app/Services/BadgeService.php:323` (`evaluateTournamentFinish`)
- Modify: `backend/app/Services/TournamentCompletionService.php:102` (call site)
- Modify: `backend/tests/Feature/TournamentBeastBadgeTest.php` (5 call sites), `backend/tests/Feature/BadgeSprintV186Test.php:158`, `backend/tests/Feature/TournamentCompletionTest.php:102-106` (2-player podium assertions flip)

**Interfaces:**
- Produces: `evaluateTournamentFinish(User $user, League $league, int $placement, int $totalPlayers): array` — podium/beast branch now guarded by `$totalPlayers >= 3`; winner + tournament_regular branches unchanged.

- [x] **Step 1: Change signature and guard.** New signature `evaluateTournamentFinish(User $user, League $league, int $placement, int $totalPlayers)`; change `if ($placement <= 3)` to `if ($placement <= 3 && $totalPlayers >= 3)` and extend the docblock: podium requires a real field of at least 3 players.

- [x] **Step 2: Update the call site** in `TournamentCompletionService` to pass `$totalPlayers`.

- [x] **Step 3: Update tests.** TournamentBeastBadgeTest: pass `3` as 4th arg at its 5 `evaluateTournamentFinish(...)` calls. BadgeSprintV186Test:158: pass `6`. TournamentCompletionTest `test_completed_tournament_marks_status_and_awards_winner` (2 players): assert `podium_finish` NOT awarded to either player; keep winner-badge assertions; update comment.

- [x] **Step 4: Run** `php artisan test --filter="TournamentCompletionTest|TournamentBeastBadgeTest|BadgeSprintV186Test"` → PASS.

- [x] **Step 5: Commit** `feat: top-3 podium badge requires at least 3 players`

---

### Task 3: Sharpshooter + Most Consistent awarding on completion (TDD)

**Files:**
- Create: `backend/tests/Feature/TournamentTrophyTest.php`
- Modify: `backend/app/Services/TournamentCompletionService.php` (add `awardSkillTrophies`, `sharpshooterUserId`, `mostConsistentUserId`; call after the standings loop)

**Interfaces:**
- Consumes: `BadgeService::award(User, string $code, array $context): ?Badge`; `calculateStandings()` rows `{user_id,total_score,rounds_played,placement}`.
- Produces: on fresh completion with rewards enabled, at most one `sharpshooter` and one `most_consistent` award per league, appended to that user's `new_badges` in the returned `per_user` map.

**Award rules (exact):**
- Sharpshooter: over all guesses in the league's rounds — if EVERY guess has non-null `distance`, lowest distance wins; otherwise fall back to highest single `score`. Ties: earliest `submitted_at`, then lowest `user_id`. No guesses → skip.
- Most Consistent: computed from standings. Skip when total rounds < 2. Eligible = `rounds_played >= max(2, ceil(totalRounds/2))`. Skip when fewer than 2 eligible players. Winner = highest `total_score / rounds_played`; tie → better placement (already deterministic).
- Both run inside the existing `$rewardsEnabled` gate and completion transaction (idempotent by construction).

- [x] **Step 1: Write the failing tests** — new file mirroring `TournamentCompletionTest` helpers but with per-round distance/score control:

```php
<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Services\TournamentCompletionService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentTrophyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function service(): TournamentCompletionService
    {
        return app(TournamentCompletionService::class);
    }

    private function activeLeague(int $rounds = 2, string $status = 'active'): array
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'x.jpg',
        ]);
        $owner = User::factory()->create();
        $league = League::create([
            'name' => 'T', 'join_code' => strtoupper(Str::random(6)), 'owner_user_id' => $owner->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => $rounds, 'status' => $status,
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $owner->id, 'joined_at' => now()]);
        for ($i = 1; $i <= $rounds; $i++) {
            LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => $i, 'status' => 'open']);
        }
        return [$league, $owner];
    }

    /** Add a member and play every round; $perRound = [['score' => int, 'distance' => float|null], ...]. */
    private function memberPlays(League $league, array $perRound, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        if (!$league->members()->where('user_id', $user->id)->exists()) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        }
        foreach ($league->rounds()->orderBy('round_number')->get()->values() as $i => $round) {
            $spec = $perRound[$i] ?? $perRound[array_key_last($perRound)];
            Guess::create([
                'league_round_id' => $round->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
                'distance' => array_key_exists('distance', $spec) ? $spec['distance'] : 0.2,
                'score' => $spec['score'], 'submitted_at' => now(),
            ]);
        }
        return $user;
    }

    private function hasBadge(User $user, string $code): bool
    {
        return $user->fresh()->badges()->where('code', $code)->exists();
    }

    public function test_three_player_completion_awards_winner_and_top3(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.05]], $owner);
        $second = $this->memberPlays($league, [['score' => 60, 'distance' => 0.2]]);
        $third  = $this->memberPlays($league, [['score' => 30, 'distance' => 0.4]]);

        $this->assertNotNull($this->service()->completeIfFinished($league->fresh()));

        $this->assertTrue($this->hasBadge($owner, 'tournament_winner'));
        $this->assertTrue($this->hasBadge($owner, 'podium_finish'));
        $this->assertTrue($this->hasBadge($second, 'podium_finish'));
        $this->assertTrue($this->hasBadge($third, 'podium_finish'));
        $this->assertFalse($this->hasBadge($second, 'tournament_winner'));
    }

    public function test_sharpshooter_goes_to_closest_distance(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        // Owner wins on total score, but $sniper has the single closest guess.
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.10], ['score' => 90, 'distance' => 0.10]], $owner);
        $sniper = $this->memberPlays($league, [['score' => 99, 'distance' => 0.01], ['score' => 10, 'distance' => 0.90]]);

        $result = $this->service()->completeIfFinished($league->fresh());

        $this->assertTrue($this->hasBadge($sniper, 'sharpshooter'));
        $this->assertFalse($this->hasBadge($owner, 'sharpshooter'));
        $codes = collect($result['per_user'][$sniper->id]['new_badges'])->pluck('code');
        $this->assertTrue($codes->contains('sharpshooter'));
    }

    public function test_sharpshooter_falls_back_to_highest_score_when_distance_missing(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlays($league, [['score' => 70, 'distance' => null]], $owner);
        $best = $this->memberPlays($league, [['score' => 95, 'distance' => 0.5]]);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertTrue($this->hasBadge($best, 'sharpshooter'));
        $this->assertFalse($this->hasBadge($owner, 'sharpshooter'));
    }

    public function test_most_consistent_goes_to_best_average_excluding_partial_players(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        $this->memberPlays($league, [['score' => 80, 'distance' => 0.2], ['score' => 80, 'distance' => 0.2]], $owner); // avg 80
        $steady = $this->memberPlays($league, [['score' => 85, 'distance' => 0.2], ['score' => 85, 'distance' => 0.2]]); // avg 85

        // A departed player guessed only round 1 with a perfect score (avg 100)
        // but has too few guesses to be eligible.
        $partial = User::factory()->create();
        Guess::create([
            'league_round_id' => $league->rounds()->orderBy('round_number')->first()->id,
            'user_id' => $partial->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.15, 'score' => 100, 'submitted_at' => now(),
        ]);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertTrue($this->hasBadge($steady, 'most_consistent'));
        $this->assertFalse($this->hasBadge($partial, 'most_consistent'));
        $this->assertFalse($this->hasBadge($owner, 'most_consistent'));
    }

    public function test_most_consistent_skipped_for_single_round_tournament(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.1]], $owner);
        $this->memberPlays($league, [['score' => 40, 'distance' => 0.3]]);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertSame(0, \DB::table('user_badges')
            ->join('badges', 'badges.id', '=', 'user_badges.badge_id')
            ->where('badges.code', 'most_consistent')->count());
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => 'completed']);
    }

    public function test_solo_tournament_awards_no_skill_trophies(): void
    {
        [$league, $owner] = $this->activeLeague(1);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.05]], $owner);

        $this->service()->completeIfFinished($league->fresh());

        $this->assertFalse($this->hasBadge($owner, 'sharpshooter'));
        $this->assertFalse($this->hasBadge($owner, 'most_consistent'));
        $this->assertFalse($this->hasBadge($owner, 'tournament_winner'));
    }

    public function test_double_close_does_not_duplicate_trophies(): void
    {
        [$league, $owner] = $this->activeLeague(2);
        $this->memberPlays($league, [['score' => 90, 'distance' => 0.05], ['score' => 90, 'distance' => 0.05]], $owner);
        $this->memberPlays($league, [['score' => 40, 'distance' => 0.3], ['score' => 40, 'distance' => 0.3]]);

        $first  = $this->service()->completeIfFinished($league->fresh());
        $second = $this->service()->completeIfFinished($league->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second);
        foreach (['sharpshooter', 'most_consistent', 'tournament_winner'] as $code) {
            $this->assertLessThanOrEqual(1, \DB::table('user_badges')
                ->join('badges', 'badges.id', '=', 'user_badges.badge_id')
                ->where('badges.code', $code)->where('user_badges.user_id', $owner->id)->count(), $code);
        }
    }

    public function test_lobby_and_cancelled_tournaments_award_nothing(): void
    {
        foreach (['lobby', 'cancelled'] as $status) {
            [$league, $owner] = $this->activeLeague(1, $status);
            $this->memberPlays($league, [['score' => 90, 'distance' => 0.05]], $owner);
            $this->memberPlays($league, [['score' => 40, 'distance' => 0.3]]);

            $this->assertNull($this->service()->completeIfFinished($league->fresh()), $status);
            $this->assertFalse($this->hasBadge($owner, 'sharpshooter'), $status);
            $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => $status]);
        }
    }

    public function test_league_with_no_guesses_does_not_crash_standings(): void
    {
        // Old/edge league: active with rounds but zero guesses. Completion never
        // fires (isComplete false) and standings math must not crash.
        [$league] = $this->activeLeague(1);
        $this->assertNull($this->service()->completeIfFinished($league->fresh()));
        $this->assertSame([], $this->service()->calculateStandings($league));
    }
}
```

- [x] **Step 2: Run** `php artisan test --filter=TournamentTrophyTest` → FAIL (sharpshooter/most_consistent never awarded).

- [x] **Step 3: Implement in `TournamentCompletionService`.** Inside `completeIfFinished`, after the `foreach ($standings …)` loop and before `return`:

```php
            if ($rewardsEnabled) {
                $this->awardSkillTrophies($league, $standings, $perUser);
            }
```

And add the three private methods (rules exactly as in the Interfaces block above):

```php
    /**
     * Tournament-wide skill trophies: Sharpshooter (closest single guess by
     * distance; highest single score when any distance is missing) and Most
     * Consistent (best average over enough rounds). Awards are skipped, never
     * guessed, when the data cannot support a fair call. Idempotent via
     * BadgeService::award() + the completion claim.
     */
    private function awardSkillTrophies(League $league, array $standings, array &$perUser): void
    {
        $totalRounds = $league->rounds()->count();

        $winners = [
            'sharpshooter'    => $this->sharpshooterUserId($league),
            'most_consistent' => $this->mostConsistentUserId($standings, $totalRounds),
        ];

        foreach ($winners as $code => $userId) {
            if ($userId === null) {
                continue;
            }
            $user = User::find($userId);
            if (!$user) {
                continue;
            }
            $badge = $this->badgeService->award($user, $code, ['league_id' => $league->id]);
            if ($badge && isset($perUser[$userId])) {
                $perUser[$userId]['new_badges'][] = $badge;
            }
        }
    }

    /** Closest single guess. Distance only counts when every guess has one. */
    private function sharpshooterUserId(League $league): ?int
    {
        $guesses = Guess::whereIn('league_round_id', $league->rounds()->pluck('id'))
            ->get(['user_id', 'distance', 'score', 'submitted_at']);
        if ($guesses->isEmpty()) {
            return null;
        }

        $useDistance = $guesses->every(fn ($g) => $g->distance !== null);

        $best = $guesses->sort(function ($a, $b) use ($useDistance) {
            if ($useDistance && (float) $a->distance !== (float) $b->distance) {
                return (float) $a->distance <=> (float) $b->distance; // closer first
            }
            if (!$useDistance && (int) $a->score !== (int) $b->score) {
                return (int) $b->score <=> (int) $a->score; // higher first
            }
            if ((string) $a->submitted_at !== (string) $b->submitted_at) {
                return strcmp((string) $a->submitted_at, (string) $b->submitted_at);
            }
            return $a->user_id <=> $b->user_id;
        })->first();

        return $best?->user_id;
    }

    /** Best average over enough rounds; null when fairness can't be established. */
    private function mostConsistentUserId(array $standings, int $totalRounds): ?int
    {
        if ($totalRounds < 2) {
            return null; // a single round is not an average
        }
        $minRounds = max(2, (int) ceil($totalRounds / 2));
        $eligible = array_values(array_filter(
            $standings,
            fn ($row) => $row['rounds_played'] >= $minRounds,
        ));
        if (count($eligible) < 2) {
            return null; // no field to be more consistent than
        }
        usort($eligible, function ($a, $b) {
            $avgA = $a['total_score'] / $a['rounds_played'];
            $avgB = $b['total_score'] / $b['rounds_played'];
            if ($avgA !== $avgB) {
                return $avgB <=> $avgA; // higher average first
            }
            return $a['placement'] <=> $b['placement']; // standings tiebreak
        });

        return $eligible[0]['user_id'];
    }
```

- [x] **Step 4: Run** `php artisan test --filter="TournamentTrophyTest|TournamentCompletionTest|TournamentBeastBadgeTest"` → PASS.

- [x] **Step 5: Commit** `feat: sharpshooter + most consistent tournament trophies on completion`

---

### Task 4: Rivalry text util (mobile, TDD)

**Files:**
- Create: `mobile/src/utils/rivalry.ts`
- Test: `mobile/src/utils/__tests__/rivalry.test.ts`

**Interfaces:**
- Consumes: `LeaderboardEntry` from `../types/guess` (`{rank, user_id, username, name, total_score, guesses_count, avg_score, is_current_user}`).
- Produces:
  - `rivalryLine(entries: LeaderboardEntry[] | null | undefined): string | null`
  - `daysLeftLabel(endsAt: string | null | undefined, now?: Date): string | null`

**Behavior (exact):** `rivalryLine` returns null (hide) when entries are missing or fewer than 2 or scores aren't numbers. Sorts a copy by `total_score` desc. If the current user is the leader: "You are leading by X points" (margin over #2), or "It's currently tied." when margin is 0. If the current user is present but behind: "You are X points behind {name}", or tied when deficit 0. If the current user is absent (hasn't guessed): "{name} leads by X points" / tied. Singular "point" when X is 1. Name = entry `name` falling back to `username`. `daysLeftLabel` returns null for null/invalid `ends_at`; otherwise `ceil` of remaining ms clamped to ≥0 → "X days left" ("1 day left").

- [ ] **Step 1: Write the failing tests:**

```ts
import { rivalryLine, daysLeftLabel } from '../rivalry';
import { LeaderboardEntry } from '../../types/guess';

function entry(over: Partial<LeaderboardEntry>): LeaderboardEntry {
  return {
    rank: 1, user_id: 1, username: 'u', name: 'U',
    total_score: 0, guesses_count: 1, avg_score: 0, is_current_user: false,
    ...over,
  };
}

describe('rivalryLine', () => {
  it('says you are leading with the margin over second place', () => {
    const line = rivalryLine([
      entry({ user_id: 1, total_score: 120, is_current_user: true }),
      entry({ user_id: 2, total_score: 95, name: 'Sam' }),
    ]);
    expect(line).toBe('You are leading by 25 points');
  });

  it('uses singular point for a 1-point lead', () => {
    const line = rivalryLine([
      entry({ user_id: 1, total_score: 96, is_current_user: true }),
      entry({ user_id: 2, total_score: 95 }),
    ]);
    expect(line).toBe('You are leading by 1 point');
  });

  it('says you are behind the leader by your own deficit', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 150, name: 'Sam' }),
      entry({ user_id: 3, total_score: 140, name: 'Kim' }),
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
    ]);
    expect(line).toBe('You are 50 points behind Sam');
  });

  it('reports the leader when the current user has no entry', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 150, name: 'Sam' }),
      entry({ user_id: 3, total_score: 120, name: 'Kim' }),
    ]);
    expect(line).toBe('Sam leads by 30 points');
  });

  it('says tied when the top scores are equal', () => {
    const line = rivalryLine([
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
      entry({ user_id: 2, total_score: 100 }),
    ]);
    expect(line).toBe("It's currently tied.");
  });

  it('says tied when the current user matches the leader from below', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 100, name: 'Sam' }),
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
    ]);
    expect(line).toBe("It's currently tied.");
  });

  it('falls back to username when name is empty', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 150, name: '', username: 'sam99' }),
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
    ]);
    expect(line).toBe('You are 50 points behind sam99');
  });

  it('hides for empty, single-entry, or malformed standings', () => {
    expect(rivalryLine([])).toBeNull();
    expect(rivalryLine(null)).toBeNull();
    expect(rivalryLine(undefined)).toBeNull();
    expect(rivalryLine([entry({ user_id: 1, total_score: 50, is_current_user: true })])).toBeNull();
    expect(rivalryLine([
      entry({ user_id: 1, total_score: undefined as unknown as number }),
      entry({ user_id: 2, total_score: 10 }),
    ])).toBeNull();
  });
});

describe('daysLeftLabel', () => {
  const now = new Date('2026-08-31T12:00:00Z');

  it('shows whole days remaining, rounding up', () => {
    expect(daysLeftLabel('2026-09-05T12:00:00Z', now)).toBe('5 days left');
    expect(daysLeftLabel('2026-09-01T00:00:00Z', now)).toBe('1 day left');
  });

  it('clamps expired tournaments to 0 days', () => {
    expect(daysLeftLabel('2026-08-30T12:00:00Z', now)).toBe('0 days left');
  });

  it('hides when ends_at is missing or invalid', () => {
    expect(daysLeftLabel(null, now)).toBeNull();
    expect(daysLeftLabel(undefined, now)).toBeNull();
    expect(daysLeftLabel('not-a-date', now)).toBeNull();
  });
});
```

- [ ] **Step 2: Run** `cd mobile && npx jest src/utils/__tests__/rivalry.test.ts` → FAIL (module not found).

- [ ] **Step 3: Implement `mobile/src/utils/rivalry.ts`:**

```ts
import { LeaderboardEntry } from '../types/guess';

/**
 * Pure rivalry-status helpers for the tournament detail screen. Both return
 * null when the data cannot support a safe statement — callers hide the text
 * instead of crashing (old tournaments may have partial data).
 */

const DAY_MS = 24 * 60 * 60 * 1000;

function points(n: number): string {
  return `${n} point${n === 1 ? '' : 's'}`;
}

function displayName(e: LeaderboardEntry): string {
  return e.name || e.username || 'A player';
}

export function rivalryLine(entries: LeaderboardEntry[] | null | undefined): string | null {
  if (!Array.isArray(entries) || entries.length < 2) return null;

  const sorted = [...entries].sort((a, b) => (b.total_score ?? 0) - (a.total_score ?? 0));
  const leader = sorted[0];
  const second = sorted[1];
  if (typeof leader?.total_score !== 'number' || typeof second?.total_score !== 'number') return null;

  const me = sorted.find(e => e.is_current_user);

  if (me && me.user_id === leader.user_id) {
    const margin = leader.total_score - second.total_score;
    return margin === 0 ? "It's currently tied." : `You are leading by ${points(margin)}`;
  }
  if (me) {
    if (typeof me.total_score !== 'number') return null;
    const deficit = leader.total_score - me.total_score;
    return deficit === 0 ? "It's currently tied." : `You are ${points(deficit)} behind ${displayName(leader)}`;
  }
  const margin = leader.total_score - second.total_score;
  return margin === 0 ? "It's currently tied." : `${displayName(leader)} leads by ${points(margin)}`;
}

export function daysLeftLabel(endsAt: string | null | undefined, now: Date = new Date()): string | null {
  if (!endsAt) return null;
  const end = new Date(endsAt);
  if (Number.isNaN(end.getTime())) return null;
  const days = Math.max(0, Math.ceil((end.getTime() - now.getTime()) / DAY_MS));
  return `${days} day${days === 1 ? '' : 's'} left`;
}
```

- [ ] **Step 4: Run** `npx jest src/utils/__tests__/rivalry.test.ts` → PASS. Also `npx jest` (whole suite) → PASS.

- [ ] **Step 5: Commit** `feat(mobile): rivalry line + days-left helpers with tests`

---

### Task 5: Show rivalry status on the tournament detail screen

**Files:**
- Modify: `mobile/src/screens/LeagueDetailScreen.tsx` (active section, ~line 198; styles block)

**Interfaces:**
- Consumes: `rivalryLine`, `daysLeftLabel` from `../utils/rivalry`; existing `league` and `leaderboard` state.

- [ ] **Step 1: Import** `import { rivalryLine, daysLeftLabel } from '../utils/rivalry';` and compute inside the component body (before `return`):

```tsx
  const rivalry = league?.status === 'active' ? rivalryLine(leaderboard) : null;
  const daysLeft = league?.status === 'active' ? daysLeftLabel(league?.ends_at) : null;
```

- [ ] **Step 2: Render a compact box** at the TOP of the `league?.status === 'active'` section (before the `dailyLimitReached` ternary):

```tsx
          {(rivalry || daysLeft) && (
            <View style={styles.rivalryBox}>
              {rivalry && <Text style={styles.rivalryText}>{rivalry}</Text>}
              {daysLeft && <Text style={styles.rivalryDays}>{daysLeft}</Text>}
            </View>
          )}
```

- [ ] **Step 3: Add styles** (this screen still uses the static `colors` module — match it, do not migrate to `useTheme`):

```ts
  rivalryBox: {
    backgroundColor: colors.surfaceElevated,
    borderRadius: 12,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
  },
  rivalryText: { fontSize: 14, fontWeight: '700', color: colors.text, textAlign: 'center' },
  rivalryDays: { fontSize: 12, color: colors.textSecondary, marginTop: 2 },
```

- [ ] **Step 4: Run** `cd mobile && npx tsc --noEmit` → clean.

- [ ] **Step 5: Commit** `feat(mobile): rivalry status + days left on tournament screen`

---

### Task 6: Full validation + docs touch-up

**Files:**
- Modify: `docs/prizes-and-trophy-room.md` (add the two new badges + podium ≥3 rule to the tournament section)

- [ ] **Step 1:** `cd backend && php artisan test` → ALL PASS.
- [ ] **Step 2:** `cd mobile && npx tsc --noEmit` → clean; `npx expo export --platform web` → succeeds.
- [ ] **Step 3:** Update `docs/prizes-and-trophy-room.md`: note `sharpshooter` / `most_consistent` award rules and the new ≥3-players podium guard (short bullets in the existing tournament completion section).
- [ ] **Step 4: Commit** `docs: tournament skill trophies + podium guard`

---

## Accepted limitations (by design, documented)

- Completion is still guess-triggered only; an expired-but-unfinished tournament shows "0 days left" while `active` (pre-existing known limitation, unchanged).
- Rivalry text derives from the leaderboard (no tie-break) so a tie can display "tied" while final standings later break it by earliest completion — acceptable for a status line.
- With every member completing every round, Most Consistent usually matches the winner (average ordering equals total ordering over equal denominators); it diverges when partial/departed players exist. Spec-mandated metric.
- Badge is once-per-user (existing system); repeat wins show in `tournament_finishes` history, not duplicate badges.
