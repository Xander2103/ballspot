# BallPicker v1.8.8 — Gameplay/Social Polish Sprint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Friend profiles show earned trophies, rank cards glow by rank level, 4 new trophies (37 total), tournaments are locked to 1 photo/day, users limited to 2 active tournaments (host limit lowered to 1), docs updated.

**Architecture:** Laravel API (backend/) + Expo RN app (mobile/). No DB migrations needed: trophies are seeded rows, limits are config + service checks, profile change is a controller allow-list addition, glow is a pure client helper. All rule changes are TDD'd in backend Feature tests.

**Tech Stack:** Laravel 11 + Sanctum + PHPUnit (sqlite :memory:), Expo SDK 56 / RN 0.85 / TypeScript.

**Spec:** The user's sprint request (7 phases) — reproduced in commit messages; this plan is the authoritative decomposition. Sprint version: v1.8.8.

## Global Constraints

- Production is deployed: NO `migrate:fresh`, no destructive data changes, old tournaments must stay playable.
- Every rule change gets a test.
- Backend tests: `cd backend && php artisan test`. Mobile: `cd mobile && npx tsc --noEmit` and `npx expo export --platform web`.
- Multi-user backend tests MUST use `$this->actingWithToken($token)` (Sanctum guard caching — see `backend/tests/TestCase.php:35`).
- Exact error copy: `"You can only be in two active tournaments at the same time."` and `"You can only host one active tournament at a time."`
- Mobile styling: theme-aware factory pattern (`createStyles(theme: ThemeTokens)`), tokens from `mobile/src/theme/themes.ts`. No new dependencies, no animation libs. Static glow only.
- Badge icons are emoji strings. Badge identifier column is `code` (not `slug`).
- Rank levels: 1 Rookie, 2 Amateur, 3 Pro, 4 Elite, 5 Legend, 6 Ball Master (config-driven, `backend/config/ballspot.php:70-77`). No slugs exist — key everything off `level`.
- Commit after every task. Windows PowerShell environment; run PHP commands from `backend/`, node from `mobile/`.

## Locked design decisions

1. **Friend-profile trophies show EARNED ONLY** (spec option A — cleaner, smaller payload; own Trophy Room keeps showing locked ones).
2. **`rounds_per_day` is forced to 1 server-side and the field is ignored if sent** (not rejected — old app builds still send it; rejecting would break them). Existing leagues keep their stored `rounds_per_day` and the existing daily-quota enforcement keeps honoring it, so old 3/day tournaments stay playable.
3. **Host limit default drops 3 → 1** via `BALLSPOT_MAX_CREATED_TOURNAMENTS` config default; env override still possible. New message per spec.
4. **Membership limit = 2** counts leagues with status `lobby` or `active` where the user has a `league_members` row (owner auto-joins on create, so hosting counts as one of the 2). `hidden_at` is irrelevant (only completed leagues can be hidden). Checked on create AND join-by-code. There is NO invite system (verified) — no third path.
5. **Check order in create:** host limit first, then membership limit.
6. **New badges (4):** `rising_star` (reach Pro, level 3, rare), `golden_touch` (reach Legend, level 5, epic), `legend_status` (reach Ball Master, level 6, legendary), `tournament_beast` (3 podium finishes, epic). Total 37. All other spec suggestions already exist (social_starter, friendly_five, host_starter, tournament_regular, daily_loyalist, streak_7, sharp_scorer, almost_perfect, perfect_picker/perfect_guess, pack_explorer, first_pack_completed).
7. **Rank badges awarded from `BadgeService::evaluateRankBadges()`** called at the end of `evaluateDailyGuess`, `evaluateTournamentGuess`, `evaluatePackCompletion` (the XP-earning paths) + backfill command. Cheap: one ledger SUM per call via PlayerRankService.
8. **Glow uses RN 0.76+ `boxShadow` style string** (works Android/iOS/web on RN 0.85/Expo 56). If `boxShadow` fails typecheck or renders wrong on web export, fall back to `shadowColor/shadowOffset:{0,0}/shadowOpacity/shadowRadius` + `elevation` (the existing idiom in `ImageGuessPicker.tsx:182-186`).
9. **Config bug fix folded in:** `config/ballspot.php` declares `'tournaments'` twice (≈line 168 and ≈line 280); PHP keeps the last, so `min_players_for_rewards` is currently dead (fallback 2 applies by luck). Merge into ONE block. This re-activates `BALLSPOT_TOURNAMENT_MIN_PLAYERS_FOR_REWARDS` — default stays 2, so behavior is unchanged.
10. **No changes to leaderboard rows / tournament player rows for glow** — those rows have no player-rank data in their API payloads (verified) and use the legacy `colors` module. Out of scope; noted as limitation.

---

### Task 1: Tournament config — merge duplicate key, new limits

**Files:**
- Modify: `backend/config/ballspot.php` (blocks at ≈line 168 and ≈line 280)
- Test: `backend/tests/Feature/TournamentConfigTest.php` (create)

**Interfaces:**
- Produces: `config('ballspot.tournaments.max_created_per_user')` → default **1**; `config('ballspot.tournaments.max_active_memberships_per_user')` → default **2**; `config('ballspot.tournaments.min_players_for_rewards')` → default **2** (now actually resolvable).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class TournamentConfigTest extends TestCase
{
    public function test_tournament_config_resolves_all_keys(): void
    {
        $cfg = config('ballspot.tournaments');

        // min_players_for_rewards was dead due to a duplicate 'tournaments'
        // key in config/ballspot.php — this asserts the blocks are merged.
        $this->assertSame(2, $cfg['min_players_for_rewards']);
        $this->assertSame(1, $cfg['max_created_per_user']);
        $this->assertSame(2, $cfg['max_active_memberships_per_user']);
        $this->assertSame(8, $cfg['max_players_per_tournament']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend; php artisan test --filter=TournamentConfigTest`
Expected: FAIL (`min_players_for_rewards` key missing, `max_created_per_user` is 3, `max_active_memberships_per_user` missing).

- [ ] **Step 3: Merge the config blocks**

In `backend/config/ballspot.php`: DELETE the first `'tournaments' => [...]` block (the one near line 168 holding `min_players_for_rewards`) and make the second block (near line 280) the single source. Preserve the exact existing env() names for keys that already exist — only the default of `max_created_per_user` changes:

```php
'tournaments' => [
    // Rewards gate (was in a duplicate block that PHP silently discarded).
    'min_players_for_rewards' => (int) env('BALLSPOT_TOURNAMENT_MIN_PLAYERS_FOR_REWARDS', 2),

    // v1.8.8: one hosted tournament at a time.
    'max_created_per_user' => (int) env('BALLSPOT_MAX_CREATED_TOURNAMENTS', 1),

    // v1.8.8: a user can be in at most 2 lobby/active tournaments (hosting counts).
    'max_active_memberships_per_user' => (int) env('BALLSPOT_MAX_ACTIVE_TOURNAMENT_MEMBERSHIPS', 2),

    'max_players_per_tournament' => (int) env('BALLSPOT_MAX_PLAYERS_PER_TOURNAMENT', 8),
    // keep the two premium_* lines exactly as they are today
    'premium_max_created_per_user' => /* keep existing env() call */ 20,
    'premium_max_players_per_tournament' => /* keep existing env() call */ 32,
],
```

(Copy the two `premium_*` lines verbatim from the current file — do not retype their env names. If the first block contains any OTHER keys besides `min_players_for_rewards`, carry them over too.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend; php artisan test --filter=TournamentConfigTest`
Expected: PASS. Note: `TournamentLimitsTest` will now FAIL (it assumes default 3) — that is expected and fixed in Task 3. Run the full suite to record which tests broke: `php artisan test` — only tournament-limit tests may fail.

- [ ] **Step 5: Commit**

```
git add backend/config/ballspot.php backend/tests/Feature/TournamentConfigTest.php
git commit -m "fix(config): merge duplicate tournaments key; host limit 1, membership limit 2"
```

---

### Task 2: Backend — force 1 round (photo) per day

**Files:**
- Modify: `backend/app/Http/Requests/CreateLeagueRequest.php` (rules ≈line 13-14)
- Modify: `backend/app/Services/LeagueService.php::create` (≈line 16-49)
- Test: `backend/tests/Feature/OnePhotoPerDayTest.php` (create)

**Interfaces:**
- Consumes: nothing new.
- Produces: every newly created league has `rounds_per_day === 1`; `LeagueService::start` therefore generates exactly `duration_days` rounds. Existing leagues with `rounds_per_day = 3` are untouched (daily-quota enforcement in `LeagueController::currentRound` and `RoundController::submitGuess` reads the stored per-league value — no change there).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnePhotoPerDayTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserToken(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeSportWithChallenges(int $count = 10): Sport
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => 'active']);
        for ($i = 0; $i < $count; $i++) {
            Challenge::factory()->create(['status' => 'active', 'sport_id' => $sport->id]);
        }
        return $sport;
    }
    // NOTE: if Challenge has no factory, copy the inline creation helper used in
    // tests/Feature/DailyLimitTest.php (it builds active challenges for a sport).

    public function test_malicious_rounds_per_day_is_forced_to_one(): void
    {
        [$user, $token] = $this->makeUserToken();
        $sport = $this->makeSportWithChallenges();

        $res = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Cheaty', 'duration_days' => 3, 'rounds_per_day' => 3,
            'sport_id' => $sport->id,
        ]);

        $res->assertStatus(201);
        $this->assertSame(1, League::latest('id')->first()->rounds_per_day);
    }

    public function test_missing_rounds_per_day_is_accepted_and_defaults_to_one(): void
    {
        [$user, $token] = $this->makeUserToken();
        $sport = $this->makeSportWithChallenges();

        $res = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Clean', 'duration_days' => 7, 'sport_id' => $sport->id,
        ]);

        $res->assertStatus(201);
        $this->assertSame(1, League::latest('id')->first()->rounds_per_day);
    }

    public function test_duration_3_generates_exactly_3_rounds_on_start(): void
    {
        [$user, $token] = $this->makeUserToken();
        $sport = $this->makeSportWithChallenges();

        $leagueId = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'ThreeDays', 'duration_days' => 3, 'rounds_per_day' => 3,
            'sport_id' => $sport->id,
        ])->json('data.id');

        $this->withToken($token)->postJson("/api/leagues/{$leagueId}/start")->assertStatus(200);

        $this->assertSame(3, League::find($leagueId)->rounds()->count());
    }

    public function test_duration_1_generates_exactly_1_round(): void
    {
        [$user, $token] = $this->makeUserToken();
        $sport = $this->makeSportWithChallenges();

        $leagueId = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'OneDay', 'duration_days' => 1, 'sport_id' => $sport->id,
        ])->json('data.id');

        $this->withToken($token)->postJson("/api/leagues/{$leagueId}/start")->assertStatus(200);

        $this->assertSame(1, League::find($leagueId)->rounds()->count());
    }

    public function test_existing_league_with_3_rounds_per_day_still_serves_rounds(): void
    {
        // Legacy tournaments created before this rule keep rounds_per_day = 3
        // and must keep working. Simulate by direct model write.
        [$user, $token] = $this->makeUserToken();
        $sport = $this->makeSportWithChallenges();

        $league = League::create([
            'name' => 'Legacy', 'join_code' => 'LEGACY',
            'owner_user_id' => $user->id, 'sport_id' => $sport->id,
            'duration_days' => 3, 'rounds_per_day' => 3, 'status' => 'lobby',
        ]);
        $league->members()->attach($user->id, ['joined_at' => now()]);

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/start")->assertStatus(200);
        $this->assertSame(9, $league->rounds()->count()); // legacy math preserved

        $this->withToken($token)->getJson("/api/leagues/{$league->id}/current-round")
            ->assertStatus(200)
            ->assertJsonPath('rounds_per_day', 3);
    }
}
```

(Adjust the create-response path `data.id` if `LeagueResource` nests differently — check `LeagueTest.php:21` for the working pattern. If `League::create` lacks a fillable column, use the same construction as `TournamentLimitsTest`'s league helper.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend; php artisan test --filter=OnePhotoPerDayTest`
Expected: FAIL — `rounds_per_day` stored as 3 in test 1; possibly 422 in test 2 (field currently `required`).

- [ ] **Step 3: Implement**

`CreateLeagueRequest.php` — replace the `rounds_per_day` rule:

```php
// v1.8.8: players get exactly one photo per day. The field is accepted
// for old app builds but ignored server-side (see LeagueService::create).
'rounds_per_day' => ['sometimes', 'integer'],
```

`LeagueService.php::create` — where the league row is built, hardcode:

```php
'rounds_per_day' => 1, // v1.8.8: one photo per day, regardless of input
```

(Replace the current `$data['rounds_per_day']` usage.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend; php artisan test --filter=OnePhotoPerDayTest`
Expected: PASS. Also run `php artisan test --filter=DailyLimitTest` — must still PASS (legacy 3/day enforcement untouched; DailyLimitTest builds its leagues via models, not the API — if any of its leagues are created through `POST /api/leagues`, update that test to build via model with `rounds_per_day => 3`).

- [ ] **Step 5: Commit**

```
git add backend/app/Http/Requests/CreateLeagueRequest.php backend/app/Services/LeagueService.php backend/tests/Feature/OnePhotoPerDayTest.php
git commit -m "feat(tournaments): force one photo/round per day on new tournaments"
```

---

### Task 3: Backend — host limit 1 with new message

**Files:**
- Modify: `backend/app/Services/LeagueService.php::create` (abort message, ≈line 20-28)
- Modify: `backend/tests/Feature/TournamentLimitsTest.php`

**Interfaces:**
- Consumes: Task 1 config (`max_created_per_user` default 1).
- Produces: creating a league while already owning a `lobby`/`active` league → 422 `"You can only host one active tournament at a time."`

- [ ] **Step 1: Update tests (write failing state)**

In `TournamentLimitsTest.php`:
- `test_user_cannot_exceed_free_created_tournament_limit` (≈line 41): remove the `config([... => 3])` override, create **one** lobby league owned by the user, assert the **second** create returns 422 with `"You can only host one active tournament at a time."`. Rename to `test_user_cannot_host_second_active_tournament`.
- `test_archived_and_cancelled_tournaments_do_not_count` (≈line 61): keep, but with ONE owned league per non-counting status (`cancelled`, `completed`, `finished`) and assert a new create returns 201. Remove the config override.
- Add:

```php
public function test_can_host_again_after_cancelling(): void
{
    [$user, $token] = $this->makeUserToken(); // reuse the file's existing helper

    $first = $this->withToken($token)->postJson('/api/leagues', [
        'name' => 'First', 'duration_days' => 3,
    ])->assertStatus(201)->json('data.id');

    $this->withToken($token)->deleteJson("/api/leagues/{$first}")->assertStatus(200);

    $this->withToken($token)->postJson('/api/leagues', [
        'name' => 'Second', 'duration_days' => 3,
    ])->assertStatus(201);
}
```

(Match the file's existing helper names and league-creation style exactly — read the file first; it may create leagues via models rather than the API, and destroy/cancel may return 204: assert whatever `LeagueTournamentLifecycleTest`'s cancel tests assert.)

- [ ] **Step 2: Run to verify current failure**

Run: `cd backend; php artisan test --filter=TournamentLimitsTest`
Expected: FAIL on the message assertion (old copy: "You have reached the free tournament limit...").

- [ ] **Step 3: Change the message**

`LeagueService.php` create-limit abort:

```php
abort_if(
    $activeOwned >= $this->maxCreatedPerUser($userId),
    422,
    'You can only host one active tournament at a time.'
);
```

- [ ] **Step 4: Run tests**

Run: `cd backend; php artisan test --filter=TournamentLimitsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```
git add backend/app/Services/LeagueService.php backend/tests/Feature/TournamentLimitsTest.php
git commit -m "feat(tournaments): host limit is one active tournament, new error copy"
```

---

### Task 4: Backend — max 2 active/lobby memberships

**Files:**
- Modify: `backend/app/Services/LeagueService.php` (`create` ≈line 16-49, `join` ≈line 109-136, new private helpers)
- Test: `backend/tests/Feature/ActiveMembershipLimitTest.php` (create)

**Interfaces:**
- Consumes: Task 1 config key `max_active_memberships_per_user` (2).
- Produces: `create` and `join` abort 422 `"You can only be in two active tournaments at the same time."` when the user already has ≥2 memberships in `lobby`/`active` leagues. Re-joining a league you're already in stays allowed (idempotent path short-circuits BEFORE the check).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveMembershipLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT_MSG = 'You can only be in two active tournaments at the same time.';

    private function user(): array
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        return [$u, $u->createToken('t')->plainTextToken];
    }

    /** Create a league owned by $owner with given status; optionally add $member. */
    private function league(User $owner, string $status, ?User $member = null, ?string $code = null): League
    {
        $league = League::create([
            'name' => 'L-' . uniqid(), 'join_code' => $code ?? strtoupper(substr(uniqid(), -6)),
            'owner_user_id' => $owner->id, 'duration_days' => 3,
            'rounds_per_day' => 1, 'status' => $status,
        ]);
        $league->members()->attach($owner->id, ['joined_at' => now()]);
        if ($member) {
            $league->members()->attach($member->id, ['joined_at' => now()]);
        }
        return $league;
    }
    // If leagues require sport_id, add the Sport::firstOrCreate fixture used elsewhere.

    public function test_cannot_join_third_active_tournament(): void
    {
        [$owner] = $this->user();
        [$me, $myToken] = $this->user();

        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'active', $me);
        $third = $this->league($owner, 'lobby', null, 'JOINME');

        $this->actingWithToken($myToken)
            ->postJson('/api/leagues/join', ['join_code' => 'JOINME'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::LIMIT_MSG);
    }

    public function test_cannot_create_when_already_in_two_tournaments(): void
    {
        [$owner] = $this->user();
        [$me, $myToken] = $this->user();

        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'active', $me);

        $this->actingWithToken($myToken)
            ->postJson('/api/leagues', ['name' => 'Mine', 'duration_days' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', self::LIMIT_MSG);
    }

    public function test_completed_and_cancelled_do_not_count(): void
    {
        [$owner] = $this->user();
        [$me, $myToken] = $this->user();

        $this->league($owner, 'completed', $me);
        $this->league($owner, 'cancelled', $me);
        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'lobby', null, 'ROOMOK');

        $this->actingWithToken($myToken)
            ->postJson('/api/leagues/join', ['join_code' => 'ROOMOK'])
            ->assertStatus(200);
    }

    public function test_hidden_completed_tournament_does_not_count(): void
    {
        [$owner] = $this->user();
        [$me, $myToken] = $this->user();

        $done = $this->league($owner, 'completed', $me);
        $done->members()->updateExistingPivot($me->id, ['hidden_at' => now()]);
        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'lobby', null, 'ROOMOK');

        $this->actingWithToken($myToken)
            ->postJson('/api/leagues/join', ['join_code' => 'ROOMOK'])
            ->assertStatus(200);
    }

    public function test_hosted_tournament_counts_toward_membership_limit(): void
    {
        [$me, $myToken] = $this->user();
        [$owner] = $this->user();

        // I host one (auto-membership) + I'm a member of another = 2.
        $this->actingWithToken($myToken)
            ->postJson('/api/leagues', ['name' => 'Hosted', 'duration_days' => 3])
            ->assertStatus(201);
        $this->league($owner, 'active', $me);
        $this->league($owner, 'lobby', null, 'THIRDX');

        $this->actingWithToken($myToken)
            ->postJson('/api/leagues/join', ['join_code' => 'THIRDX'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::LIMIT_MSG);
    }

    public function test_rejoining_own_lobby_is_not_blocked(): void
    {
        [$owner, $ownerToken] = $this->user();
        [$other] = $this->user();

        $mine = $this->league($owner, 'lobby', null, 'MINE01');
        $this->league($other, 'active', $owner); // owner is at 2 memberships

        // Idempotent re-join of a league I'm already in must not 422.
        $this->actingWithToken($ownerToken)
            ->postJson('/api/leagues/join', ['join_code' => 'MINE01'])
            ->assertStatus(200);
    }
}
```

(Join success status: check what `LeagueService::join` returns today via `LeagueTournamentLifecycleTest::test_users_can_join_lobby_tournament` — use that status code, it may be 200 or 201. If `leagues.sport_id` is NOT NULL, add the sport fixture to `league()`.)

- [ ] **Step 2: Run to verify failure**

Run: `cd backend; php artisan test --filter=ActiveMembershipLimitTest`
Expected: FAIL — joins/creates that should 422 return success (no limit exists yet). `test_cannot_create_when_already_in_two_tournaments` may currently pass validation but return 201.

- [ ] **Step 3: Implement in LeagueService**

Add private helpers:

```php
private function activeMembershipCount(int $userId): int
{
    return League::whereIn('status', self::ACTIVE_STATUSES)
        ->whereHas('members', fn ($q) => $q->where('users.id', $userId))
        ->count();
}

private function assertMembershipCapacity(int $userId): void
{
    $max = (int) config('ballspot.tournaments.max_active_memberships_per_user', 2);

    abort_if(
        $this->activeMembershipCount($userId) >= $max,
        422,
        'You can only be in two active tournaments at the same time.'
    );
}
```

In `create()`: call `$this->assertMembershipCapacity($userId);` immediately AFTER the existing host-limit abort (host message wins when both apply).

In `join()`: call `$this->assertMembershipCapacity($user->id);` AFTER the existing-member idempotent early-return (≈line 119-133) and BEFORE the "tournament is full" capacity check. (Use whatever variable holds the joining user's id in that method.)

- [ ] **Step 4: Run tests**

Run: `cd backend; php artisan test --filter="ActiveMembershipLimitTest|TournamentLimitsTest|LeagueTournamentLifecycleTest|LeagueTest"`
Expected: ALL PASS. If lifecycle tests fail because their fixtures put a user in 3+ leagues, adjust those fixtures (reduce league count or complete extras) — do not weaken the rule.

- [ ] **Step 5: Commit**

```
git add backend/app/Services/LeagueService.php backend/tests/Feature/ActiveMembershipLimitTest.php
git commit -m "feat(tournaments): max 2 active/lobby tournaments per user (create + join)"
```

---

### Task 5: Backend — public profile earned trophies

**Files:**
- Modify: `backend/app/Http/Controllers/Api/PublicProfileController.php::show` (badges block, ≈line 64-67)
- Modify: `backend/tests/Feature/PublicProfileTest.php`

**Interfaces:**
- Consumes: `User::badges()` belongsToMany with pivot `earned_at`.
- Produces: `data.badges` becomes `{ earned_count: int, total_count: int, earned: [{ code, name, description, icon, category, rarity, earned_at }] }` — earned only, ordered by `sort_order`. Mobile Task 10 consumes exactly this shape.

- [ ] **Step 1: Write the failing tests** (append to `PublicProfileTest.php`, matching its existing fixture style — it seeds users and calls `GET /api/users/{id}/public-profile`; badge fixtures need `$this->seed(BadgeSeeder::class)` like `BadgeTest.php:21`)

```php
public function test_public_profile_lists_earned_trophies_with_safe_fields_only(): void
{
    $this->seed(\Database\Seeders\BadgeSeeder::class);

    $viewer = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $target = \App\Models\User::factory()->create(['email_verified_at' => now()]);

    $badge = \App\Models\Badge::where('code', 'first_guess')->firstOrFail();
    $target->badges()->attach($badge->id, ['earned_at' => now()]);

    $res = $this->withToken($viewer->createToken('t')->plainTextToken)
        ->getJson("/api/users/{$target->id}/public-profile")
        ->assertStatus(200)
        ->assertJsonPath('data.badges.earned_count', 1)
        ->assertJsonPath('data.badges.earned.0.code', 'first_guess');

    $entry = $res->json('data.badges.earned.0');
    $this->assertSame(
        ['code', 'name', 'description', 'icon', 'category', 'rarity', 'earned_at'],
        array_keys($entry)
    );
}

public function test_public_profile_does_not_list_unearned_trophies(): void
{
    $this->seed(\Database\Seeders\BadgeSeeder::class);

    $viewer = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $target = \App\Models\User::factory()->create(['email_verified_at' => now()]);

    $this->withToken($viewer->createToken('t')->plainTextToken)
        ->getJson("/api/users/{$target->id}/public-profile")
        ->assertStatus(200)
        ->assertJsonPath('data.badges.earned_count', 0)
        ->assertJsonCount(0, 'data.badges.earned');
}
```

Also extend the existing leak-guard test (≈line 54) to assert the raw response content does not contain `push_token` or `expo` (alongside its existing email/password/is_admin/friend_code assertions).

- [ ] **Step 2: Run to verify failure**

Run: `cd backend; php artisan test --filter=PublicProfileTest`
Expected: new tests FAIL (`data.badges.earned` missing).

- [ ] **Step 3: Implement**

In `PublicProfileController::show`, replace the `'badges' => [...]` block:

```php
$earnedBadges = $user->badges()
    ->orderBy('sort_order')
    ->get()
    ->map(fn ($badge) => [
        'code'        => $badge->code,
        'name'        => $badge->name,
        'description' => $badge->description,
        'icon'        => $badge->icon,
        'category'    => $badge->category,
        'rarity'      => $badge->rarity,
        'earned_at'   => $badge->pivot->earned_at,
    ])
    ->values();
```

and in the response array:

```php
'badges' => [
    'earned_count' => $earnedBadges->count(),
    'total_count'  => Badge::count(),
    'earned'       => $earnedBadges,
],
```

(Keeps the documented allow-list style — no Resource class, no internal ids, no pivot spillover.)

- [ ] **Step 4: Run tests**

Run: `cd backend; php artisan test --filter=PublicProfileTest`
Expected: ALL PASS (including pre-existing structure/leak/anonymized-404/friendship tests — the anonymized 404 and non-friend access rules are untouched and re-verified by the existing tests).

- [ ] **Step 5: Commit**

```
git add backend/app/Http/Controllers/Api/PublicProfileController.php backend/tests/Feature/PublicProfileTest.php
git commit -m "feat(profile): public profile lists earned trophies (safe allow-list)"
```

---

### Task 6: Backend — seed 4 new badges (37 total)

**Files:**
- Modify: `backend/database/seeders/BadgeSeeder.php` (append after the v1.8.6 block, ≈line 61)
- Modify: `backend/tests/Feature/BadgeTest.php` (33 → 37 at ≈line 50)
- Modify: `backend/tests/Feature/BadgeSprintV186Test.php` (rename/retarget its 33-count assertion, ≈line 78)

**Interfaces:**
- Produces: badge codes `rising_star`, `golden_touch`, `legend_status`, `tournament_beast` exist after seeding. Tasks 7–9 award them.

- [ ] **Step 1: Update count assertions to 37 and add a codes test** (failing first). In `BadgeTest.php` change `assertSame(33, Badge::count())` → `37`. In `BadgeSprintV186Test.php` update its seeder-count test similarly. Add to `BadgeTest.php`:

```php
public function test_v188_badges_are_seeded(): void
{
    foreach (['rising_star', 'golden_touch', 'legend_status', 'tournament_beast'] as $code) {
        $this->assertNotNull(\App\Models\Badge::where('code', $code)->first(), $code);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend; php artisan test --filter="BadgeTest|BadgeSprintV186Test"`
Expected: FAIL (count 33, codes missing).

- [ ] **Step 3: Append to BadgeSeeder** (match the file's exact array-item shape — copy a v1.8.6 line and edit):

```php
// v1.8.8 — rank milestones + podium collector
['code' => 'rising_star',      'name' => 'Rising Star',      'description' => 'Reach the Pro rank.',                    'icon' => '📈', 'category' => 'rank',       'rarity' => 'rare'],
['code' => 'golden_touch',     'name' => 'Golden Touch',     'description' => 'Reach the Legend rank.',                 'icon' => '✨', 'category' => 'rank',       'rarity' => 'epic'],
['code' => 'legend_status',    'name' => 'Legend Status',    'description' => 'Reach the Ball Master rank.',            'icon' => '🐐', 'category' => 'rank',       'rarity' => 'legendary'],
['code' => 'tournament_beast', 'name' => 'Tournament Beast', 'description' => 'Finish on the podium in 3 tournaments.', 'icon' => '🦁', 'category' => 'tournament', 'rarity' => 'epic'],
```

- [ ] **Step 4: Run tests**

Run: `cd backend; php artisan test --filter="BadgeTest|BadgeSprintV186Test"`
Expected: PASS. Seeder is idempotent (`updateOrCreate` on `code`) — safe for production deploy via `php artisan db:seed --class=BadgeSeeder`.

- [ ] **Step 5: Commit**

```
git add backend/database/seeders/BadgeSeeder.php backend/tests/Feature/BadgeTest.php backend/tests/Feature/BadgeSprintV186Test.php
git commit -m "feat(badges): seed rising_star, golden_touch, legend_status, tournament_beast (37 total)"
```

---

### Task 7: Backend — rank-milestone badge triggers

**Files:**
- Modify: `backend/app/Services/BadgeService.php` (new method + 3 call sites + constructor)
- Test: `backend/tests/Feature/RankBadgeTest.php` (create)

**Interfaces:**
- Consumes: `PlayerRankService::forUser(User): array` (returns `level`), badge codes from Task 6, `BadgeService::award()`.
- Produces: `evaluateRankBadges(User $user): array` (array of newly awarded `Badge` models), called from `evaluateDailyGuess`, `evaluateTournamentGuess`, `evaluatePackCompletion` so unlocks ride the existing `new_badges` toast channel.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\XpEvent;
use App\Services\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function giveXp(User $user, int $amount): void
    {
        XpEvent::create([
            'user_id' => $user->id, 'source_type' => 'test_grant',
            'source_id' => $user->id, 'amount' => $amount, 'reason' => 'test',
        ]);
    }

    public function test_pro_rank_awards_rising_star_only(): void
    {
        $user = User::factory()->create();
        $this->giveXp($user, 10000); // Pro (level 3)

        $awarded = app(BadgeService::class)->evaluateRankBadges($user);

        $this->assertSame(['rising_star'], array_map(fn ($b) => $b->code, $awarded));
        $this->assertTrue($user->badges()->where('code', 'rising_star')->exists());
        $this->assertFalse($user->badges()->where('code', 'golden_touch')->exists());
    }

    public function test_ball_master_awards_all_three_rank_badges(): void
    {
        $user = User::factory()->create();
        $this->giveXp($user, 100000); // Ball Master (level 6)

        app(BadgeService::class)->evaluateRankBadges($user);

        foreach (['rising_star', 'golden_touch', 'legend_status'] as $code) {
            $this->assertTrue($user->badges()->where('code', $code)->exists(), $code);
        }
    }

    public function test_rank_badges_are_not_duplicated(): void
    {
        $user = User::factory()->create();
        $this->giveXp($user, 10000);

        $svc = app(BadgeService::class);
        $svc->evaluateRankBadges($user);
        $second = $svc->evaluateRankBadges($user);

        $this->assertSame([], $second);
        $this->assertSame(1, $user->badges()->where('code', 'rising_star')->count());
    }

    public function test_rookie_gets_no_rank_badges(): void
    {
        $user = User::factory()->create();
        $this->assertSame([], app(BadgeService::class)->evaluateRankBadges($user));
    }
}
```

(If `XpEvent::create` needs different columns, mirror how `PlayerRankTest.php` grants XP.)

- [ ] **Step 2: Run to verify failure**

Run: `cd backend; php artisan test --filter=RankBadgeTest`
Expected: FAIL — `evaluateRankBadges` undefined.

- [ ] **Step 3: Implement in BadgeService**

Constructor: add `private PlayerRankService $rankService` (import `App\Services\PlayerRankService`; no circular dep — PlayerRankService depends only on XpService).

```php
/** Rank level => badge code. Levels: 3 Pro, 5 Legend, 6 Ball Master. */
private const RANK_BADGES = [
    3 => 'rising_star',
    5 => 'golden_touch',
    6 => 'legend_status',
];

/**
 * Award any rank-milestone badges the user's current level qualifies for.
 * Idempotent; one XP-ledger read per call.
 */
public function evaluateRankBadges(User $user): array
{
    $level = (int) ($this->rankService->forUser($user)['level'] ?? 1);

    $awarded = [];
    foreach (self::RANK_BADGES as $minLevel => $code) {
        if ($level >= $minLevel && ($badge = $this->award($user, $code, ['level' => $level]))) {
            $awarded[] = $badge;
        }
    }

    return $awarded;
}
```

Call sites — at the END of each method, merging into its returned array (match each method's actual accumulator variable):
- `evaluateDailyGuess` (≈line 85-128)
- `evaluateTournamentGuess` (≈line 130-149)
- `evaluatePackCompletion` (≈line 336-366)

```php
$awarded = array_merge($awarded, $this->evaluateRankBadges($user));
```

IMPORTANT ordering check: in `DailyChallengeController` and `RoundController`, confirm the guess XP is written to `xp_events` BEFORE `evaluate*` is invoked (XP award call vs the `evaluate*` call at `DailyChallengeController.php:198` / `RoundController.php:98`). If evaluate runs first, the badge simply lands on the NEXT action — acceptable, but add a code comment noting it; do NOT reorder controller logic in this task.

- [ ] **Step 4: Run tests**

Run: `cd backend; php artisan test --filter="RankBadgeTest|BadgeTest|BadgeSprintV186Test|GuessTest"`
Expected: PASS. Note: badge-unlock XP (rare 250/epic 500/legendary 1000) can itself cross a threshold; we deliberately don't loop — the next XP-earning action catches up.

- [ ] **Step 5: Commit**

```
git add backend/app/Services/BadgeService.php backend/tests/Feature/RankBadgeTest.php
git commit -m "feat(badges): award rank-milestone trophies from XP-earning paths"
```

---

### Task 8: Backend — tournament_beast trigger

**Files:**
- Modify: `backend/app/Services/BadgeService.php::evaluateTournamentFinish` (≈line 275-303)
- Modify: `backend/tests/Feature/TournamentCompletionTest.php` (add one test) or extend `RankBadgeTest` — prefer a new focused test in `tests/Feature/TournamentBeastBadgeTest.php`

**Interfaces:**
- Consumes: `tournament_finishes` rows (model `App\Models\TournamentFinish` — verify exact class name via `Glob backend/app/Models/*Finish*`), written by `TournamentCompletionService` BEFORE `evaluateTournamentFinish` is called (verify order at `TournamentCompletionService.php:102-112`; if the finish row is written after, count `+1` for the current finish instead).
- Produces: `tournament_beast` awarded when the user has ≥3 finishes with `placement <= 3`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Services\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentBeastBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function finish(User $user, int $placement): void
    {
        $league = League::create([
            'name' => 'L-' . uniqid(), 'join_code' => strtoupper(substr(uniqid(), -6)),
            'owner_user_id' => $user->id, 'duration_days' => 1,
            'rounds_per_day' => 1, 'status' => 'completed',
        ]);
        TournamentFinish::create([
            'league_id' => $league->id, 'user_id' => $user->id,
            'placement' => $placement, 'total_score' => 100,
        ]);
    }

    public function test_third_podium_awards_tournament_beast(): void
    {
        $user = User::factory()->create();
        $this->finish($user, 1);
        $this->finish($user, 3);
        $this->finish($user, 2); // third podium already in DB

        $league = League::latest('id')->first();
        app(BadgeService::class)->evaluateTournamentFinish($user, $league, 2);

        $this->assertTrue($user->badges()->where('code', 'tournament_beast')->exists());
    }

    public function test_two_podiums_do_not_award(): void
    {
        $user = User::factory()->create();
        $this->finish($user, 1);
        $this->finish($user, 2);

        $league = League::latest('id')->first();
        app(BadgeService::class)->evaluateTournamentFinish($user, $league, 2);

        $this->assertFalse($user->badges()->where('code', 'tournament_beast')->exists());
    }

    public function test_fourth_place_finishes_do_not_count(): void
    {
        $user = User::factory()->create();
        $this->finish($user, 4);
        $this->finish($user, 5);
        $this->finish($user, 1);

        $league = League::latest('id')->first();
        app(BadgeService::class)->evaluateTournamentFinish($user, $league, 1);

        $this->assertFalse($user->badges()->where('code', 'tournament_beast')->exists());
    }
}
```

(Adjust `League::create`/`TournamentFinish::create` columns to fillable reality — mirror the fixtures in `TournamentCompletionTest.php`. If leagues need `sport_id`, add the sport fixture.)

- [ ] **Step 2: Run to verify failure**

Run: `cd backend; php artisan test --filter=TournamentBeastBadgeTest`
Expected: FAIL — badge never awarded.

- [ ] **Step 3: Implement** — inside `evaluateTournamentFinish`, in/next to the existing `placement <= 3` podium_finish branch:

```php
if ($placement <= 3) {
    $podiums = TournamentFinish::where('user_id', $user->id)
        ->where('placement', '<=', 3)
        ->count();

    if ($podiums >= 3 && ($beast = $this->award($user, 'tournament_beast', ['podiums' => $podiums]))) {
        $awarded[] = $beast;
    }
}
```

(Match the method's accumulator variable name; import the finish model. If the current finish row is not yet persisted when this runs, use `$podiums + 1 >= 3` — decide from `TournamentCompletionService` call order, and cover it with the test either way.)

- [ ] **Step 4: Run tests**

Run: `cd backend; php artisan test --filter="TournamentBeastBadgeTest|TournamentCompletionTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```
git add backend/app/Services/BadgeService.php backend/tests/Feature/TournamentBeastBadgeTest.php
git commit -m "feat(badges): tournament_beast for three podium finishes"
```

---

### Task 9: Backend — backfill new badges for existing users

**Files:**
- Modify: `backend/app/Services/BadgeService.php::backfillCountBadges` (≈line 223-260)
- Modify: `backend/tests/Feature/BadgeSprintV186Test.php` (it tests the backfill command) — add assertions

**Interfaces:**
- Consumes: `evaluateRankBadges` (Task 7), podium-count logic (Task 8).
- Produces: `php artisan ballspot:backfill-sprint-badges` also grants rank badges and `tournament_beast` from historical data. This is the production catch-up path after deploy.

- [ ] **Step 1: Write the failing test** (append to `BadgeSprintV186Test.php`, reusing its command-invocation pattern):

```php
public function test_backfill_awards_v188_badges_from_history(): void
{
    $user = \App\Models\User::factory()->create();

    \App\Models\XpEvent::create([
        'user_id' => $user->id, 'source_type' => 'test_grant',
        'source_id' => $user->id, 'amount' => 100000, 'reason' => 'test',
    ]);
    for ($i = 0; $i < 3; $i++) {
        $league = \App\Models\League::create([
            'name' => "B{$i}", 'join_code' => "BEAST{$i}",
            'owner_user_id' => $user->id, 'duration_days' => 1,
            'rounds_per_day' => 1, 'status' => 'completed',
        ]);
        \App\Models\TournamentFinish::create([
            'league_id' => $league->id, 'user_id' => $user->id,
            'placement' => 1, 'total_score' => 100,
        ]);
    }

    $this->artisan('ballspot:backfill-sprint-badges')->assertExitCode(0);

    foreach (['rising_star', 'golden_touch', 'legend_status', 'tournament_beast'] as $code) {
        $this->assertTrue($user->badges()->where('code', $code)->exists(), $code);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=BadgeSprintV186Test`. Expected: new test FAILS.

- [ ] **Step 3: Implement** — at the end of `backfillCountBadges(User $user)`:

```php
// v1.8.8 backfill: rank milestones + podium collector.
$awarded = array_merge($awarded, $this->evaluateRankBadges($user));

$podiums = TournamentFinish::where('user_id', $user->id)
    ->where('placement', '<=', 3)
    ->count();
if ($podiums >= 3 && ($beast = $this->award($user, 'tournament_beast', ['podiums' => $podiums]))) {
    $awarded[] = $beast;
}
```

(Match accumulator/return variable of the method.)

- [ ] **Step 4: Run tests** — `php artisan test --filter=BadgeSprintV186Test`. Expected: PASS.

- [ ] **Step 5: Commit**

```
git add backend/app/Services/BadgeService.php backend/tests/Feature/BadgeSprintV186Test.php
git commit -m "feat(badges): backfill v1.8.8 badges via ballspot:backfill-sprint-badges"
```

---

### Task 10: Mobile — friend profile trophies section

**Files:**
- Create: `mobile/src/theme/rarity.ts`
- Modify: `mobile/src/types/friend.ts` (PublicProfile at lines 42-53)
- Modify: `mobile/src/screens/FriendProfileScreen.tsx`
- Modify: `mobile/src/components/TrophyRoom.tsx` (swap local `rarityColor` for the shared one, lines 26-34)

**Interfaces:**
- Consumes: Task 5 response shape `data.badges.earned[]`.
- Produces: `rarityColor(theme: ThemeTokens, rarity: string): string` shared helper used by both TrophyRoom and FriendProfileScreen.

- [ ] **Step 1: Create `mobile/src/theme/rarity.ts`**

```ts
import type { ThemeTokens } from './themes';

/** Badge rarity accent. Epic is hardcoded — no purple token exists in ThemeTokens. */
export function rarityColor(theme: ThemeTokens, rarity: string): string {
  switch (rarity) {
    case 'rare':
      return theme.accent;
    case 'epic':
      return '#b76bff';
    case 'legendary':
      return theme.gold;
    default:
      return theme.textSecondary;
  }
}
```

- [ ] **Step 2: Update `TrophyRoom.tsx`** — delete its local `rarityColor` (lines 26-34) and `import { rarityColor } from '../theme/rarity';`. (Check the exact ThemeTokens import path used in TrophyRoom for consistency.)

- [ ] **Step 3: Update `mobile/src/types/friend.ts`**

```ts
export interface PublicProfileBadge {
  code: string;
  name: string;
  description: string;
  icon: string;
  category: string;
  rarity: string;
  earned_at: string | null;
}
```

and change `PublicProfile.badges` to:

```ts
badges: {
  earned_count: number;
  total_count: number;
  earned: PublicProfileBadge[];
};
```

- [ ] **Step 4: Add the Trophies section to `FriendProfileScreen.tsx`** — between the rank card (line 83) and the Stats section (line 85). Follow the screen's existing `createStyles(theme)` pattern:

```tsx
<Text style={styles.sectionTitle}>Trophies</Text>
{profile.badges.earned.length === 0 ? (
  <Text style={styles.trophyEmpty}>No trophies yet.</Text>
) : (
  <View style={styles.trophyGrid}>
    {profile.badges.earned.map((b) => (
      <View
        key={b.code}
        style={[styles.trophyCell, { borderColor: rarityColor(theme, b.rarity) + '80' }]}
      >
        <Text style={styles.trophyIcon}>{b.icon}</Text>
        <Text style={styles.trophyName} numberOfLines={1}>
          {b.name}
        </Text>
        <Text style={[styles.trophyRarity, { color: rarityColor(theme, b.rarity) }]}>
          {b.rarity.toUpperCase()}
        </Text>
      </View>
    ))}
  </View>
)}
```

New styles in `createStyles` (mirror TrophyRoom's grid metrics at its lines 306-333 so the look matches the Trophy Room):

```ts
trophyGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
trophyCell: {
  minWidth: '30%', flexGrow: 1, flexBasis: '30%', maxWidth: '31.5%',
  backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1,
  alignItems: 'center', paddingVertical: spacing.sm, paddingHorizontal: spacing.xs,
},
trophyIcon: { fontSize: 26 },
trophyName: { fontSize: 11, fontWeight: '700', color: theme.text, marginTop: 4 },
trophyRarity: { fontSize: 9, fontWeight: '800', letterSpacing: 0.5, marginTop: 2 },
trophyEmpty: { color: theme.textSecondary, fontSize: 13 },
```

Reuse the screen's existing `sectionTitle` style (the one used for "Stats"). Import `rarityColor` and `spacing`. Use `profile.badges?.earned ?? []` defensively if the API might be older than the app during rollout.

- [ ] **Step 5: Typecheck**

Run: `cd mobile; npx tsc --noEmit`
Expected: clean.

- [ ] **Step 6: Commit**

```
git add mobile/src/theme/rarity.ts mobile/src/types/friend.ts mobile/src/screens/FriendProfileScreen.tsx mobile/src/components/TrophyRoom.tsx
git commit -m "feat(mobile): trophies section on friend profile, shared rarity colors"
```

---

### Task 11: Mobile — rank glow helper + application

**Files:**
- Create: `mobile/src/theme/rankVisuals.ts`
- Modify: `mobile/src/components/RankCard.tsx` (container view, lines 17-48)
- Modify: `mobile/src/screens/FriendProfileScreen.tsx` (rank card view, lines 80-83)
- Modify: `mobile/src/screens/RankOverviewScreen.tsx` (current-rank row, lines 84-127) — easy win, has `level`

**Interfaces:**
- Consumes: `PlayerRank.level` (1–6), ThemeTokens (`bronze`, `silver`, `gold`, `primary`, `border`).
- Produces: `getRankVisualStyle(level: number | null | undefined, theme: ThemeTokens): ViewStyle`.

- [ ] **Step 1: Create `mobile/src/theme/rankVisuals.ts`**

```ts
import type { ViewStyle } from 'react-native';
import type { ThemeTokens } from './themes';

/**
 * Rank-tier visual treatment. Levels: 1 Rookie, 2 Amateur, 3 Pro,
 * 4 Elite, 5 Legend, 6 Ball Master. Static glow only (no animation).
 * boxShadow is RN 0.76+ cross-platform (Android/iOS/web).
 * Missing/unknown level falls back to the plain themed border.
 */
export function getRankVisualStyle(
  level: number | null | undefined,
  theme: ThemeTokens,
): ViewStyle {
  switch (level) {
    case 1: // Rookie — muted bronze border
      return { borderWidth: 1, borderColor: theme.bronze };
    case 2: // Amateur — silver border
      return { borderWidth: 1, borderColor: theme.silver };
    case 3: // Pro — subtle primary glow
      return {
        borderWidth: 2,
        borderColor: theme.primary,
        boxShadow: `0 0 8px ${theme.primary}59`,
      };
    case 4: // Elite — gold border, medium glow
      return {
        borderWidth: 2,
        borderColor: theme.gold,
        boxShadow: `0 0 10px ${theme.gold}73`,
      };
    case 5: // Legend — strong gold glow
      return {
        borderWidth: 2,
        borderColor: theme.gold,
        boxShadow: `0 0 14px ${theme.gold}A6`,
      };
    case 6: // Ball Master — legendary layered gold glow
      return {
        borderWidth: 3,
        borderColor: theme.gold,
        boxShadow: `0 0 20px ${theme.gold}CC, 0 0 6px ${theme.gold}80`,
      };
    default:
      return { borderWidth: 1, borderColor: theme.border };
  }
}
```

FALLBACK (only if `boxShadow` fails `tsc` on this RN version or breaks `expo export --platform web`): replace each `boxShadow` with `shadowColor: <color>, shadowOffset: { width: 0, height: 0 }, shadowOpacity: <0.35|0.45|0.65|0.8>, shadowRadius: <8|10|14|20>, elevation: <4|5|6|8>` — the idiom already proven in `ImageGuessPicker.tsx:182-186`. Verify against the Expo v56 / RN 0.85 docs per `mobile/AGENTS.md`.

- [ ] **Step 2: Apply in `RankCard.tsx`** — merge onto the card container:

```tsx
<View style={[styles.card, getRankVisualStyle(rank?.level, theme)]}>
```

(The component receives `rank: PlayerRank`; use optional chaining anyway — null rank must not crash.) Remove any now-conflicting static `borderWidth`/`borderColor` from `styles.card` only if doubled; array styles override left-to-right, so leaving them is fine.

- [ ] **Step 3: Apply in `FriendProfileScreen.tsx`** rank card:

```tsx
<View style={[styles.rankCard, getRankVisualStyle(profile.rank?.level, theme)]}>
```

- [ ] **Step 4: Apply in `RankOverviewScreen.tsx`** — on the CURRENT rank row only, merge `getRankVisualStyle(row.level, theme)` into the existing `rowCurrent` style array so your own tier visibly glows in the ladder.

- [ ] **Step 5: Typecheck + web export**

Run: `cd mobile; npx tsc --noEmit`
Run: `cd mobile; npx expo export --platform web`
Expected: both clean. If boxShadow breaks either, apply the documented fallback and re-run.

- [ ] **Step 6: Commit**

```
git add mobile/src/theme/rankVisuals.ts mobile/src/components/RankCard.tsx mobile/src/screens/FriendProfileScreen.tsx mobile/src/screens/RankOverviewScreen.tsx
git commit -m "feat(mobile): rank-tier glow on rank cards (bronze->legendary gold)"
```

---

### Task 12: Mobile — Create Tournament screen: one photo per day + new limit copy

**Files:**
- Modify: `mobile/src/screens/CreateLeagueScreen.tsx`

**Interfaces:**
- Consumes: backend ignores `rounds_per_day` (Task 2); new 422 messages (Tasks 3–4) surface via existing `Alert.alert('Error', e?.message)` at line 62 — no error-handling change needed (verify JoinLeagueScreen line 26 likewise shows `e?.message`).

- [ ] **Step 1: Edit the screen**

- Delete the `roundsPerDay` state (line 42) and the `Rounds per day` `OptionRow` (line 81).
- Keep sending `rounds_per_day: 1` in the create payload (backward-compatible, explicit).
- Change the Duration label (line 80) to `"Duration in days"` and add below the OptionRow:

```tsx
<Text style={styles.helperText}>How many days should players have to complete it?</Text>
<Text style={styles.helperText}>Players get 1 photo per day.</Text>
```

- Replace the summary line (line 82): `Total rounds: {durationDays * roundsPerDay}` → `` `${durationDays} photo${durationDays === 1 ? '' : 's'} total — one per day.` ``
- Replace the free-plan line (line 84): `"Free plan: up to 3 active tournaments, 8 players each."` → `"You can host 1 tournament and be in up to 2 at the same time. Up to 8 players each."`
- `helperText` style: reuse the existing footnote style in the file (the one at line 84/85, named like `comingSoon`/`footnote`) or add `{ color: <same token used there>, fontSize: 12 }` matching the file's styling module (NOTE: this screen may use legacy `colors` — match whatever it currently imports; do not migrate it to the theme system in this sprint).

- [ ] **Step 2: Typecheck**

Run: `cd mobile; npx tsc --noEmit` — expected clean (the removed state must leave no dangling references; search the file for `roundsPerDay`).

- [ ] **Step 3: Commit**

```
git add mobile/src/screens/CreateLeagueScreen.tsx
git commit -m "feat(mobile): create-tournament is 1 photo/day; new limit copy"
```

---

### Task 13: Docs — privacy + test report + store readiness

**Files:**
- Modify: `docs/privacy-data-inventory.md`
- Modify: `docs/privacy-policy-draft.md`
- Modify: `docs/test-report.md`
- Modify: `docs/store-readiness.md`

- [ ] **Step 1: `privacy-data-inventory.md`** — add a `## v1.8.8` section:
  - Public profile now includes the **list of earned badges** (code, name, description, icon, category, rarity, earned_at) — still allow-listed, still no email/tokens/private stats; anonymized accounts still 404.
  - Fix the master-table tension: change the `Badges / trophies / finishes` row's "Who can access" from "User (own); placements visible in tournaments" to "All verified players (earned badges listed on public profile); placements visible in tournaments".
  - Tournament limits are gameplay config, not personal data: note host limit 1, membership limit 2, 1 photo/day.

- [ ] **Step 2: `privacy-policy-draft.md`** — in "What we collect / Gameplay data" and the public-profile sentence in "The short version": change "aggregate gameplay stats and badge counts" to "aggregate gameplay stats and the trophies/badges they have earned". No other legal text changes.

- [ ] **Step 3: `test-report.md`** — prepend a `## v1.8.8 — Gameplay/Social Polish (2026-08-23)` section listing: public-profile trophies (+tests), 4 new badges/37 total (+tests), rank glow (client-only), 1 photo/day rule (+tests incl. legacy-league regression), membership limit 2 (+tests), host limit 1 (+tests), config duplicate-key fix (+test), backfill extension (+test). Record final `php artisan test` and `npx tsc --noEmit` results (fill in actual numbers after Task 14).

- [ ] **Step 4: `store-readiness.md`** — add `## v1.8.8 store-relevant notes`: badge catalogue 33 → 37 (still emoji, no IP issues); deploy steps `php artisan db:seed --class=BadgeSeeder` then `php artisan ballspot:backfill-sprint-badges`; tournament limits tightened (host 1 / member 2 / 1 photo per day) — gameplay-only, no store-listing impact; public profile now shows earned trophies (no new UGC surface — badge content is developer-authored).

- [ ] **Step 5: Commit**

```
git add docs/privacy-data-inventory.md docs/privacy-policy-draft.md docs/test-report.md docs/store-readiness.md
git commit -m "docs: v1.8.8 trophies visibility, tournament limits, deploy notes"
```

---

### Task 14: Full validation

- [ ] **Step 1: Backend full suite** — `cd backend; php artisan test` → ALL PASS. If any unrelated test broke, fix root cause (likely a fixture exceeding the new limits — adjust the fixture, never the rule).
- [ ] **Step 2: Routes unchanged check** — `php artisan route:list --path=api` → confirm no route was added/removed (this sprint changes payloads and rules, not routes).
- [ ] **Step 3: Migration status** — `php artisan migrate:status` → no new migrations (expected: none added this sprint).
- [ ] **Step 4: Mobile** — `cd mobile; npx tsc --noEmit` then `npx expo export --platform web` → both clean.
- [ ] **Step 5: Update `docs/test-report.md`** with the real numbers from steps 1 and 4 (amend Task 13's section).
- [ ] **Step 6: Commit** any test-report number updates: `git commit -am "docs: record v1.8.8 validation results"`.

---

## Known limitations (carry into final report)

1. Leaderboard rows and tournament lobby player rows get NO rank glow — their API payloads carry no player-rank data (would need backend payload changes; out of scope per "maybe if already easy").
2. Rank badges can lag one action behind an XP gain when badge-unlock XP itself crosses a threshold (deliberate: no recursive evaluation).
3. Legacy tournaments with `rounds_per_day = 3` remain playable at 3/day by design; only NEW tournaments are 1/day.
4. Production users currently hosting 2–3 active tournaments keep them; they just can't create another until below the new limit. Same for users in 3+ tournaments (join blocked until they drop to <2). Nothing is deleted.
5. Deploy requires: `php artisan db:seed --class=BadgeSeeder` + `php artisan ballspot:backfill-sprint-badges` (idempotent, skips anonymized users).
6. A new EAS build (TestFlight) is required for the mobile changes; backend can deploy independently and is backward-compatible with the current app build (old builds sending `rounds_per_day: 3` are silently corrected to 1).
