# BallPicker v1.8.6 — Final Public-Beta Sprint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship backend-verified daily challenge reminders, friend suggestions, fullscreen tap-to-guess, a deleted-account metric, 7 new trophies, and Trophy Room polish — production-safe, no redesign.

**Architecture:** Laravel 11/12-style backend (`backend/`, Sanctum API + Blade admin, scheduler in `routes/console.php`, no queued jobs anywhere today) and Expo SDK 56 / RN 0.85 app (`mobile/`). All sends via the existing synchronous `ExpoPushService`; new daily-reminder push is env-flag-gated (default OFF) so backend can deploy first without behavior change.

**Tech Stack:** PHP 8 / Laravel, Pest-style PHPUnit feature tests (`php artisan test`), TypeScript strict RN, new minimal `jest + ts-jest` for one pure util.

## Global Constraints

- **NO `migrate:fresh`. NO destructive migrations. NO deleting production content.** All migrations are additive (`Schema::table` add nullable columns) with safe backfills.
- **Backend can deploy before the new app build without breaking the current production app.** Anything that would double-notify or change app-visible behavior is flag-gated (`BALLPICKER_DAILY_REMINDER_PUSH_ENABLED` default `false`).
- Do not redesign screens; keep the clean sports-app look. Trophy Room keeps its current grid.
- Mobile: Expo SDK 56 — per `mobile/AGENTS.md`, consult https://docs.expo.dev/versions/v56.0.0/ before writing Expo-API code.
- Multi-user backend tests MUST use `actingWithToken(...)` (helper in `backend/tests/TestCase.php`) — never a second `withToken` (Sanctum guard caching).
- Deletion anonymizes the `users` row; cascades never fire. Any new user-referencing table/column must be handled explicitly in `AccountController::delete()` and `DataExportController`.
- Badge count assertions: seeder goes **26 → 33** badges. Update every count assertion (grep for `26` in badge tests).
- App timezone is `UTC` (`config/app.php:68`); daily challenges are UTC-dated; `daily_challenges.challenge_date` is globally unique.
- New endpoints go inside the `auth:sanctum` + `verified` group in `backend/routes/api.php` (starts ~L37/L59). Literal friend routes must stay ABOVE the `DELETE /friends/{user}` wildcard.
- Commit after every task (message style: `feat:`/`fix:`/`docs:` as in recent history).

## Audit findings this plan is built on (state clearly in final report)

1. **There is NO backend daily-reminder sender today.** Daily reminders are client-side local Expo notifications (`mobile/src/services/reminderScheduler.ts` + `notifications.ts` `syncSchedules`), scheduled at `reminder_time`, cancelled/re-evaluated on Home focus. They never use push tokens. `notification_settings.daily_reminder_enabled/reminder_time/timezone` are stored server-side but consumed only by the app.
2. **No queued jobs exist anywhere** (zero `ShouldQueue`, no `app/Jobs`). Admin pushes are synchronous in `ExpoPushService`. Even if Supervisor runs `queue:work` in production, it has never processed a job. This sprint keeps reminder sends synchronous inside the scheduled command (proven path, no dependency on an unproven worker) — documented as an accepted decision.
3. **No `DeviceNotRegistered` handling and no retry** in `ExpoPushService` — fixed in Task 4.
4. **Status gap:** `ballspot:schedule-daily-challenges` (cron 00:05) writes `status='scheduled'`, but `DailyChallengeController` only serves `status='active'` (`DailyChallenge::active()`). Admin must flip status manually (`DailyChallengeAdminController` L78). **Do not change this behavior in this sprint** — report it; the reminder command uses the same `active()` scope so reminders and gameplay always agree.
5. **No deletion marker column exists** — only `email LIKE '%@ballspot.deleted'` / `username 'deleted-N'` / nulled `friend_code`. Task 1 adds `anonymized_at`.
6. Deleted users still appear on leaderboards as "Deleted User" (unchanged, by design) and their public profile is currently fetchable — Task 1 404s it.

---

### Task 1: `users.anonymized_at` marker + deletion sets it + public-profile 404 (Phase 4 backend)

**Files:**
- Create: `backend/database/migrations/2026_08_10_000001_add_anonymized_at_to_users.php`
- Modify: `backend/app/Http/Controllers/Api/AccountController.php` (~L69-72 non-fillable block)
- Modify: `backend/app/Http/Controllers/Api/PublicProfileController.php` (top of the show method)
- Modify: `backend/tests/Feature/AccountDeletionTest.php`, `backend/tests/Feature/PublicProfileTest.php`

**Interfaces:**
- Produces: `users.anonymized_at` (nullable timestamp) — the canonical "this account was deleted/anonymized" predicate used by Tasks 2, 5, 7, 8.

- [ ] **Step 1: Write failing tests**

In `AccountDeletionTest.php` add:

```php
public function test_deletion_sets_anonymized_at(): void
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/account', ['password' => 'password'])->assertOk();

    $this->assertNotNull($user->fresh()->anonymized_at);
}
```

(Mirror the exact delete-request shape already used in this file — it may require `password` or a confirmation field; copy from the existing `test_deletion_anonymizes_user` setup.)

In `PublicProfileTest.php` add:

```php
public function test_anonymized_user_public_profile_returns_404(): void
{
    $viewer = User::factory()->create();
    $target = User::factory()->create();
    $target->forceFill(['anonymized_at' => now()])->save();

    $token = $viewer->createToken('t')->plainTextToken;
    $this->withToken($token)->getJson("/api/users/{$target->id}/public-profile")->assertNotFound();
}
```

- [ ] **Step 2: Run to verify failure** — `cd backend && php artisan test --filter=anonymized` → FAIL (column missing).

- [ ] **Step 3: Implement**

Migration (additive + idempotent backfill of previously deleted accounts using their `updated_at` as best-available proxy):

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'anonymized_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('anonymized_at')->nullable()->after('email_verified_at');
            });
        }
        // Backfill accounts deleted before this column existed. updated_at is the
        // closest proxy for when the anonymization happened.
        DB::table('users')
            ->where('email', 'like', '%@ballspot.deleted')
            ->whereNull('anonymized_at')
            ->update(['anonymized_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
    }
};
```

`AccountController::delete()` — in the existing non-fillable block (`friend_code = null; is_admin = false; ...`) add:

```php
$user->anonymized_at = now();
```

`PublicProfileController` — first line inside the show/`__invoke` method after the `$user` parameter is bound:

```php
if ($user->anonymized_at !== null) {
    return response()->json(['message' => 'Not found.'], 404);
}
```

Also add `'anonymized_at' => 'datetime'` to `User::casts()` (see how existing casts are declared in `backend/app/Models/User.php` — method or property — and match).

- [ ] **Step 4: Run** `php artisan test --filter="AccountDeletionTest|PublicProfileTest"` → PASS (all pre-existing tests too).

- [ ] **Step 5: Commit** — `feat: add users.anonymized_at deletion marker, 404 anonymized public profiles`

---

### Task 2: Admin deleted-account metric on competition overview (Phase 4 admin)

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/CompetitionController.php` (`index()` L16-33)
- Modify: `backend/resources/views/admin/competition/index.blade.php`
- Modify: `backend/tests/Feature/` — the test covering `/admin/competition` (find via `grep -rn "admin/competition" backend/tests`; if none covers the page render, add to a new `AdminStatsTest.php`)

**Interfaces:**
- Consumes: `users.anonymized_at` from Task 1.

- [ ] **Step 1: Failing test**

```php
public function test_admin_competition_page_shows_deleted_account_metric(): void
{
    $admin = User::factory()->create(['is_admin' => true]);
    $deleted = User::factory()->create();
    $deleted->forceFill(['anonymized_at' => now()])->save();

    $this->actingAs($admin)
        ->get('/admin/competition')
        ->assertOk()
        ->assertSee('Deleted/anonymized accounts')
        ->assertSee('1');
}
```

(Admin auth is session-based — check how existing admin feature tests authenticate, e.g. `AdminNotificationTest.php:29`, and copy that pattern exactly.)

- [ ] **Step 2: Run** → FAIL (text missing).

- [ ] **Step 3: Implement** — in `CompetitionController::index()` pass to the view:

```php
'accountStats' => [
    'total'   => \App\Models\User::whereNull('anonymized_at')->count(),
    'deleted' => \App\Models\User::whereNotNull('anonymized_at')->count(),
],
```

In the Blade view add one small card (match the existing card/panel markup in that view — Bootstrap 5):

```blade
<div class="card mb-3">
  <div class="card-body py-2">
    <strong>Accounts</strong>
    <div class="text-muted small">
      Active accounts: {{ $accountStats['total'] }} ·
      Deleted/anonymized accounts: {{ $accountStats['deleted'] }}
    </div>
  </div>
</div>
```

Aggregate counts only — no per-user data, no links.

- [ ] **Step 4: Run test** → PASS. **Step 5: Commit** — `feat: admin deleted-account metric on competition overview`

---

### Task 3: Reminder infrastructure columns + config flag (Phase 1 groundwork)

**Files:**
- Create: `backend/database/migrations/2026_08_10_000002_add_last_daily_reminder_date_to_notification_settings.php`
- Modify: `backend/config/ballspot.php` (notifications block ~L197-203), `backend/.env.example`
- Modify: `backend/app/Models/NotificationSetting.php` (add `user()` relation + fillable/cast)

- [ ] **Step 1: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->date('last_daily_reminder_date')->nullable()->after('timezone');
        });
    }
    public function down(): void
    {
        Schema::table('notification_settings', fn (Blueprint $t) => $t->dropColumn('last_daily_reminder_date'));
    }
};
```

- [ ] **Step 2: Model** — in `NotificationSetting`: add `'last_daily_reminder_date'` to `$fillable`, `'last_daily_reminder_date' => 'date:Y-m-d'` to casts, and:

```php
public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(User::class);
}
```

**Do NOT expose `last_daily_reminder_date` in `NotificationSettingsController::payload()`** (it's server bookkeeping) and do NOT allow it in `UpdateNotificationSettingsRequest`.

- [ ] **Step 3: Config** — in `config/ballspot.php` `notifications` array add:

```php
'daily_reminder_push_enabled' => (bool) env('BALLPICKER_DAILY_REMINDER_PUSH_ENABLED', false),
```

In `.env.example` add (they were missing entirely):

```
BALLPICKER_PUSH_ENABLED=true
BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=false
BALLPICKER_DEFAULT_REMINDER_TIME=19:00
```

- [ ] **Step 4: Run** `php artisan test --filter=NotificationSettings` → still green (payload unchanged). **Step 5: Commit** — `feat: notification_settings reminder bookkeeping column + daily reminder push flag`

---

### Task 4: `ExpoPushService::sendMessages()` + DeviceNotRegistered pruning

**Files:**
- Modify: `backend/app/Services/ExpoPushService.php`
- Modify: `backend/tests/Feature/AdminNotificationTest.php` (add invalid-token test; existing tests must stay green)

**Interfaces:**
- Produces: `public function sendMessages(array $messages): array` returning `['sent' => int, 'failed' => int, 'invalid_tokens_removed' => int]`. Each message: `['to' => string, 'title' => string, 'body' => string, 'sound' => 'default']`. Task 5 consumes this.

- [ ] **Step 1: Failing test** (in `AdminNotificationTest.php`, mirroring its existing `Http::fake` style):

```php
public function test_device_not_registered_tokens_are_deleted_on_send(): void
{
    Http::fake([
        '*' => Http::response(['data' => [
            ['status' => 'ok'],
            ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]),
    ]);

    $admin = User::factory()->create(['is_admin' => true]);
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u1->pushTokens()->create(['token' => 'ExpoPushToken[good-1]', 'platform' => 'ios']);
    $u2->pushTokens()->create(['token' => 'ExpoPushToken[dead-2]', 'platform' => 'ios']);

    $this->actingAs($admin)->post('/admin/notifications', [
        'title' => 'T', 'body' => 'B', 'target_type' => 'all', 'send_now' => '1',
    ]);

    $this->assertDatabaseHas('push_tokens', ['token' => 'ExpoPushToken[good-1]']);
    $this->assertDatabaseMissing('push_tokens', ['token' => 'ExpoPushToken[dead-2]']);
}
```

(Adapt the POST route/fields to the exact ones in `Admin\NotificationController::store` — check `resources/views/admin/notifications/index.blade.php` form field names first.)

- [ ] **Step 2: Run** → FAIL (dead token still present).

- [ ] **Step 3: Implement** — refactor `ExpoPushService`:

```php
/**
 * Send raw Expo push messages in batches of 100. Parses per-message tickets;
 * tokens Expo reports as DeviceNotRegistered are deleted so we stop sending
 * to dead devices. Synchronous by design (no queue worker dependency).
 *
 * @param array<int, array{to:string,title:string,body:string,sound:string}> $messages
 * @return array{sent:int,failed:int,invalid_tokens_removed:int}
 */
public function sendMessages(array $messages): array
{
    $sent = 0; $failed = 0; $invalid = [];

    foreach (array_chunk($messages, self::BATCH_SIZE) as $chunk) {
        try {
            $response = Http::asJson()->post(config('ballspot.notifications.expo_push_url'), $chunk);
            $tickets = $response->json('data') ?? [];
            foreach ($chunk as $i => $message) {
                $ticket = $tickets[$i] ?? null;
                if (($ticket['status'] ?? null) === 'ok') {
                    $sent++;
                } else {
                    $failed++;
                    if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                        $invalid[] = $message['to'];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Expo push batch failed', ['error' => $e->getMessage(), 'count' => count($chunk)]);
            $failed += count($chunk);
        }
    }

    $removed = 0;
    if ($invalid !== []) {
        $removed = PushToken::whereIn('token', $invalid)->delete();
        Log::info('Pruned DeviceNotRegistered push tokens', ['count' => $removed]);
    }

    return ['sent' => $sent, 'failed' => $failed, 'invalid_tokens_removed' => $removed];
}
```

Rewrite the internals of `sendAnnouncement()` to build the message array from `recipientTokens(...)` and call `sendMessages()`, keeping its externally observable behavior IDENTICAL: same status transitions (`failed > 0 && sent === 0` → `STATUS_FAILED`), same `metadata = ['recipients' => ..., 'sent' => ..., 'failed' => ...]`, same kill-switch check first. Delete the now-redundant private `sendBatch()`.

- [ ] **Step 4: Run** `php artisan test --filter=AdminNotificationTest` → ALL PASS (6 existing + 1 new). **Step 5: Commit** — `feat: generic Expo sendMessages with DeviceNotRegistered token pruning`

---

### Task 5: `DailyReminderService` + `ballspot:send-daily-reminders` command + schedule (Phase 1 core)

**Files:**
- Create: `backend/app/Services/DailyReminderService.php`
- Create: `backend/app/Console/Commands/SendDailyReminders.php`
- Modify: `backend/routes/console.php` (add schedule entry)
- Modify: `backend/app/Models/User.php` (add `dailyChallengeGuesses()` HasMany if missing)
- Create: `backend/tests/Feature/DailyReminderTest.php`

**Interfaces:**
- Consumes: `ExpoPushService::sendMessages()` (Task 4), `notification_settings.last_daily_reminder_date` (Task 3), `users.anonymized_at` (Task 1).
- Produces: `DailyReminderService::run(bool $dryRun = false): array{daily_id: ?int, candidates: int, sent: int, failed: int, skipped_window: int, invalid_tokens_removed: int}`; artisan command `ballspot:send-daily-reminders {--dry-run}`.

**Semantics (document in code + docs):**
- Runs every 15 minutes via scheduler. Sends the reminder for **today's ACTIVE daily (UTC date)** to users where ALL hold: `daily_reminder_enabled`, has ≥1 push token, has NOT guessed today's daily, not anonymized, not already reminded for today (`last_daily_reminder_date`), and their **local** clock (their `timezone`, invalid/null → UTC) is within `[reminder_time, reminder_time + 60min)`.
- **At-most-once:** `last_daily_reminder_date` is written BEFORE the Expo call. A crashed send means a missed reminder, never a duplicate. Deliberate.
- No daily active for today (including the scheduled-not-activated gap) → no sends.
- Fully gated: master `push_enabled` AND `daily_reminder_push_enabled` must both be true.

- [ ] **Step 1: Write failing tests** — `backend/tests/Feature/DailyReminderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ballspot.notifications.push_enabled', true);
        config()->set('ballspot.notifications.daily_reminder_push_enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-08-10 19:05:00', 'UTC'));
        Http::fake(['*' => Http::response(['data' => [['status' => 'ok']]])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Create today's ACTIVE daily. Mirror challenge setup from DailyChallengeTest. */
    private function makeActiveDaily(): DailyChallenge
    {
        $challenge = Challenge::factory()->create(['status' => 'active']);
        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => '2026-08-10',
            'status'         => 'active',
        ]);
    }

    /** Opted-in user with a token whose reminder_time has just passed (UTC). */
    private function makeCandidate(string $reminderTime = '19:00', ?string $tz = null): User
    {
        $user = User::factory()->create();
        $user->pushTokens()->create(['token' => 'ExpoPushToken[u' . $user->id . ']', 'platform' => 'ios']);
        $user->notificationSettings()->update([
            'daily_reminder_enabled' => true,
            'reminder_time' => $reminderTime,
            'timezone' => $tz,
        ]);
        return $user;
    }

    public function test_sends_reminder_to_opted_in_user_with_token_and_unplayed_daily(): void
    {
        $this->makeActiveDaily();
        $user = $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame('2026-08-10', $user->fresh()->notificationSetting->last_daily_reminder_date?->toDateString());
    }

    public function test_no_reminder_after_daily_already_played(): void
    {
        $daily = $this->makeActiveDaily();
        $user = $this->makeCandidate();
        $daily->guesses()->create([
            'user_id' => $user->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0, 'score' => 100, 'submitted_at' => now(),
        ]);

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_disabled_daily_reminders_do_not_send(): void
    {
        $this->makeActiveDaily();
        $user = $this->makeCandidate();
        $user->notificationSettings()->update(['daily_reminder_enabled' => false]);

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_users_without_tokens_are_skipped(): void
    {
        $this->makeActiveDaily();
        $user = $this->makeCandidate();
        $user->pushTokens()->delete();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_anonymized_users_are_skipped(): void
    {
        $this->makeActiveDaily();
        $user = $this->makeCandidate();
        $user->forceFill(['anonymized_at' => now()])->save();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_no_duplicate_reminder_same_day(): void
    {
        $this->makeActiveDaily();
        $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_reminder_time_in_future_is_not_sent_yet(): void
    {
        $this->makeActiveDaily();
        $this->makeCandidate('21:00');

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_reminder_older_than_window_is_not_sent(): void
    {
        $this->makeActiveDaily();
        $this->makeCandidate('17:00'); // 19:05 UTC is > 60min past 17:00

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_user_timezone_is_respected(): void
    {
        $this->makeActiveDaily();
        // 19:05 UTC = 15:05 in New York (EDT, UTC-4 in August) → 15:00 reminder is in-window.
        $this->makeCandidate('15:00', 'America/New_York');
        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_invalid_timezone_falls_back_to_utc(): void
    {
        $this->makeActiveDaily();
        $this->makeCandidate('19:00', 'Not/AZone');
        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_flag_off_sends_nothing(): void
    {
        config()->set('ballspot.notifications.daily_reminder_push_enabled', false);
        $this->makeActiveDaily();
        $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_no_active_daily_sends_nothing(): void
    {
        $this->makeCandidate();
        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }
}
```

**Adapt to reality while implementing:** check `Challenge::factory()` exists and what fields it requires (see `DailyChallengeTest.php` setup); check `DailyChallenge->guesses()` relation name; check `notificationSettings()` lazy-create helper (`User.php:114-117`) — it returns the model, so `->update([...])` works.

- [ ] **Step 2: Run** `php artisan test --filter=DailyReminderTest` → FAIL (command missing).

- [ ] **Step 3: Implement service:**

```php
<?php

namespace App\Services;

use App\Models\DailyChallenge;
use App\Models\NotificationSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Sends the Daily Challenge push reminder. Scheduled every 15 minutes.
 *
 * At-most-once per user per daily: last_daily_reminder_date is written BEFORE
 * the Expo call, so a crash mid-send can only ever skip, never duplicate.
 */
class DailyReminderService
{
    /** A reminder fires within [reminder_time, reminder_time + WINDOW_MINUTES). */
    public const WINDOW_MINUTES = 60;

    public function __construct(private ExpoPushService $push)
    {
    }

    public function run(bool $dryRun = false): array
    {
        $today = Carbon::today()->toDateString(); // UTC (config/app.php timezone)
        $daily = DailyChallenge::active()->forDate($today)->first();

        $result = [
            'daily_id' => $daily?->id, 'candidates' => 0, 'sent' => 0,
            'failed' => 0, 'skipped_window' => 0, 'invalid_tokens_removed' => 0,
        ];
        if (!$daily) {
            return $result;
        }

        $now = CarbonImmutable::now('UTC');

        NotificationSetting::query()
            ->where('daily_reminder_enabled', true)
            ->where(fn ($q) => $q->whereNull('last_daily_reminder_date')
                ->orWhere('last_daily_reminder_date', '!=', $today))
            ->whereHas('user', fn ($q) => $q->whereNull('anonymized_at')->whereHas('pushTokens'))
            ->whereDoesntHave('user.dailyChallengeGuesses',
                fn ($q) => $q->where('daily_challenge_id', $daily->id))
            ->with('user.pushTokens')
            ->chunkById(200, function ($settings) use (&$result, $now, $today, $dryRun) {
                $due = [];
                foreach ($settings as $setting) {
                    $result['candidates']++;
                    if ($this->isInWindow($setting, $now)) {
                        $due[] = $setting;
                    } else {
                        $result['skipped_window']++;
                    }
                }
                if ($due === [] || $dryRun) {
                    return;
                }

                // Mark BEFORE sending (at-most-once).
                NotificationSetting::whereIn('id', collect($due)->pluck('id'))
                    ->update(['last_daily_reminder_date' => $today]);

                $messages = [];
                foreach ($due as $setting) {
                    foreach ($setting->user->pushTokens as $pushToken) {
                        $messages[] = [
                            'to'    => $pushToken->token,
                            'title' => 'Daily Challenge',
                            'body'  => "Today's BallPicker daily is still waiting for you ⚽",
                            'sound' => 'default',
                        ];
                    }
                }

                $outcome = $this->push->sendMessages($messages);
                $result['sent']   += $outcome['sent'];
                $result['failed'] += $outcome['failed'];
                $result['invalid_tokens_removed'] += $outcome['invalid_tokens_removed'];
            });

        Log::info('Daily reminder run', $result);

        return $result;
    }

    private function isInWindow(NotificationSetting $setting, CarbonImmutable $nowUtc): bool
    {
        $time = $setting->reminder_time ?: '19:00';
        [$hour, $minute] = array_map('intval', explode(':', $time));

        try {
            $tz = new \DateTimeZone($setting->timezone ?: 'UTC');
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $local  = $nowUtc->setTimezone($tz);
        $target = $local->setTime($hour, $minute, 0);

        return $local >= $target && $local < $target->addMinutes(self::WINDOW_MINUTES);
    }
}
```

Command:

```php
<?php

namespace App\Console\Commands;

use App\Services\DailyReminderService;
use Illuminate\Console\Command;

class SendDailyReminders extends Command
{
    protected $signature = 'ballspot:send-daily-reminders {--dry-run : Report who would be reminded without sending}';

    protected $description = 'Push the Daily Challenge reminder to opted-in users who have not played today';

    public function handle(DailyReminderService $service): int
    {
        if (!config('ballspot.notifications.push_enabled')
            || !config('ballspot.notifications.daily_reminder_push_enabled')) {
            $this->info('Daily reminder push is disabled (BALLPICKER_DAILY_REMINDER_PUSH_ENABLED).');
            return self::SUCCESS;
        }

        $result = $service->run((bool) $this->option('dry-run'));

        $this->info(sprintf(
            'Daily %s — candidates: %d, sent: %d, failed: %d, outside window: %d, dead tokens removed: %d',
            $result['daily_id'] ? "#{$result['daily_id']}" : '(none active)',
            $result['candidates'], $result['sent'], $result['failed'],
            $result['skipped_window'], $result['invalid_tokens_removed'],
        ));

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add beneath the existing four entries:

```php
Schedule::command('ballspot:send-daily-reminders')->everyFifteenMinutes()->withoutOverlapping();
```

(Match the exact registration style already used in that file.)

`User.php` — add if missing:

```php
public function dailyChallengeGuesses(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(DailyChallengeGuess::class);
}
```

- [ ] **Step 4: Run** `php artisan test --filter=DailyReminderTest` → 13 PASS. Then full `php artisan test` → green. Verify `php artisan schedule:list` shows the new command.

- [ ] **Step 5: Commit** — `feat: backend daily challenge push reminders (flag-gated, every 15min)`

---

### Task 6: Local-vs-push dedupe — `daily_reminder_push_active` in settings payload + mobile gate

**Files:**
- Modify: `backend/app/Http/Controllers/Api/NotificationSettingsController.php` (`payload()` L32-41)
- Modify: `backend/tests/Feature/NotificationSettingsTest.php`
- Modify: `mobile/src/api/notificationsApi.ts` (interface), `mobile/src/services/notifications.ts` (`syncSchedules`)

**Interfaces:**
- Produces: settings payload gains read-only `daily_reminder_push_active: bool` (true only when both backend push flags are on). Mobile skips scheduling the LOCAL daily reminder when true — server push owns it. Tournament local reminder unchanged.
- Rollout property: backend deploys with flag off → payload says `false` → all apps behave exactly as today. When ops flips the flag, updated apps stop local scheduling on next settings sync; only stale-build users could briefly double-notify (documented accepted risk).

- [ ] **Step 1: Failing test** (NotificationSettingsTest):

```php
public function test_payload_exposes_daily_reminder_push_active_flag(): void
{
    config()->set('ballspot.notifications.push_enabled', true);
    config()->set('ballspot.notifications.daily_reminder_push_enabled', true);
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->getJson('/api/me/notification-settings')
        ->assertOk()
        ->assertJsonPath('data.daily_reminder_push_active', true);
}
```

(Confirm whether payload is wrapped in `data` — mirror the assertions already in this file.)

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** — in `payload()` add:

```php
'daily_reminder_push_active' => (bool) (config('ballspot.notifications.push_enabled')
    && config('ballspot.notifications.daily_reminder_push_enabled')),
```

Mobile — `notificationsApi.ts`:

```ts
export interface NotificationSettings {
  // ...existing fields...
  daily_reminder_push_active?: boolean;
}
```

`notifications.ts` `syncSchedules` — change the daily branch only:

```ts
const serverOwnsDaily = state.settings.daily_reminder_push_active === true;
if (state.settings.daily_reminder_enabled && !state.dailyCompleted && !serverOwnsDaily) {
  await Notifications.scheduleNotificationAsync({ content: COPY.daily, trigger: daily });
}
```

- [ ] **Step 4: Run** backend filter test + `cd mobile && npx tsc --noEmit`. **Step 5: Commit** — `feat: server-owned daily reminder flag suppresses local daily notification`

---

### Task 7: Friend request by `user_id` (backend, needed by suggestions)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/FriendController.php` (`store()` L78-133)
- Modify: `backend/tests/Feature/FriendsTest.php`

**Interfaces:**
- Produces: `POST /api/friends/requests` accepts EITHER `{friend_code}` (unchanged) OR `{user_id}`. All existing guards (self, already-friends, pending, rejected-cooldown) apply to both paths. `user_id` path 404s for anonymized/unknown users.

- [ ] **Step 1: Failing tests:**

```php
public function test_can_send_friend_request_by_user_id(): void
{
    [$a, $tokenA] = $this->userWithToken(); // mirror existing helper style in this file
    $b = User::factory()->create();

    $this->withToken($tokenA)->postJson('/api/friends/requests', ['user_id' => $b->id])
        ->assertCreated();

    $this->assertDatabaseHas('friend_requests', [
        'requester_id' => $a->id, 'recipient_id' => $b->id, 'status' => 'pending',
    ]);
}

public function test_cannot_send_friend_request_to_anonymized_user_by_id(): void
{
    [, $tokenA] = $this->userWithToken();
    $b = User::factory()->create();
    $b->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();

    $this->withToken($tokenA)->postJson('/api/friends/requests', ['user_id' => $b->id])
        ->assertNotFound();
}
```

(If the file has no `userWithToken` helper, inline `User::factory()->create()` + `createToken` exactly like its existing tests. Assert status codes matching what `store()` actually returns today — check the existing send-by-code test's assertion, it may be 200 not 201; match it.)

- [ ] **Step 2: Run** → FAIL (validation error). **Step 3: Implement** — replace the top of `store()`:

```php
$data = $request->validate([
    'friend_code' => ['required_without:user_id', 'prohibits:user_id', 'string', 'min:4', 'max:12'],
    'user_id'     => ['required_without:friend_code', 'integer'],
]);

if (isset($data['user_id'])) {
    $target = User::whereKey($data['user_id'])->whereNull('anonymized_at')->first();
} else {
    $code = strtoupper(trim($data['friend_code']));
    $target = User::where('friend_code', $code)->whereNull('anonymized_at')->first();
}
```

…then continue with the EXISTING guard chain unchanged (not-found 404, self 422, already-friends 422, pending 422, rejected-cooldown 422, `updateOrCreate`).

- [ ] **Step 4: Run** `php artisan test --filter=FriendsTest` → all pass. **Step 5: Commit** — `feat: friend requests can target a user id (suggestions groundwork)`

---

### Task 8: Friend suggestions endpoint (Phase 2 backend)

**Files:**
- Create: `backend/app/Services/FriendSuggestionService.php`
- Modify: `backend/app/Http/Controllers/Api/FriendController.php` (add `suggestions()`)
- Modify: `backend/routes/api.php` (add `GET /friends/suggestions` at L82 area — ABOVE the `{friendRequest}`/`{user}` wildcard routes)
- Create: `backend/tests/Feature/FriendSuggestionsTest.php`

**Interfaces:**
- Produces: `GET /api/friends/suggestions` → `{"data": [{id, name, username, avatar_url, rank_name, level, total_xp, reason}]}` where `reason ∈ {"same_tournament", "active_player"}`. Max 10 rows. Mobile consumes in Task 9.
- `FriendSuggestionService::forUser(User $user, int $limit = 10): array` (array of the row arrays above).

- [ ] **Step 1: Failing tests** — `FriendSuggestionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\FriendRequest;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private function shareTournament(User $a, User $b): void
    {
        $league = League::factory()->create(); // if no factory: League::create([...]) mirroring LeagueService::create fields
        foreach ([$a, $b] as $u) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/friends/suggestions')->assertUnauthorized();
    }

    public function test_suggests_tournament_peer_with_reason(): void
    {
        $me = User::factory()->create();
        $peer = User::factory()->create();
        $this->shareTournament($me, $peer);

        $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $peer->id)
            ->assertJsonPath('data.0.reason', 'same_tournament');
    }

    public function test_excludes_self_friends_pending_and_anonymized(): void
    {
        $me = User::factory()->create();

        $friend = User::factory()->create();
        Friendship::create(['user_id' => $me->id, 'friend_id' => $friend->id]);
        Friendship::create(['user_id' => $friend->id, 'friend_id' => $me->id]);

        $pendingOut = User::factory()->create();
        FriendRequest::create(['requester_id' => $me->id, 'recipient_id' => $pendingOut->id, 'status' => 'pending']);

        $pendingIn = User::factory()->create();
        FriendRequest::create(['requester_id' => $pendingIn->id, 'recipient_id' => $me->id, 'status' => 'pending']);

        $ghost = User::factory()->create();
        $ghost->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();

        foreach ([$friend, $pendingOut, $pendingIn, $ghost] as $u) {
            $this->shareTournament($me, $u);
        }

        $ids = collect($this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')->assertOk()
            ->json('data'))->pluck('id');

        $this->assertNotContains($me->id, $ids);
        $this->assertNotContains($friend->id, $ids);
        $this->assertNotContains($pendingOut->id, $ids);
        $this->assertNotContains($pendingIn->id, $ids);
        $this->assertNotContains($ghost->id, $ids);
    }

    public function test_falls_back_to_recently_active_players(): void
    {
        $me = User::factory()->create();
        $active = User::factory()->create();
        // Give $active a recent daily guess — reuse the daily setup pattern from DailyReminderTest/DailyChallengeTest.
        $daily = $this->makeActiveDailyFor('2026-08-09');
        $daily->guesses()->create([
            'user_id' => $active->id, 'guess_x_ratio' => 0.4, 'guess_y_ratio' => 0.4,
            'distance' => 0.1, 'score' => 80, 'submitted_at' => now()->subDay(),
        ]);

        $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.reason', 'active_player');
    }

    public function test_never_exposes_email_or_friend_code(): void
    {
        $me = User::factory()->create();
        $peer = User::factory()->create();
        $this->shareTournament($me, $peer);

        $row = $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')->json('data.0');

        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('friend_code', $row);
    }

    public function test_caps_at_ten(): void
    {
        $me = User::factory()->create();
        $peers = User::factory()->count(14)->create();
        foreach ($peers as $p) {
            $this->shareTournament($me, $p);
        }

        $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')
            ->assertOk()
            ->assertJsonCount(10, 'data');
    }
}
```

(Single-authed-user tests: plain `withToken` is fine; none of these acts as two different authed users in one test. If `League::factory()` doesn't exist, create leagues via direct `League::create` with the columns from `LeagueService::create()` — name, join_code, owner_user_id, sport_id, duration_days, rounds_per_day, status `'lobby'`; check whether `sport_id` is nullable or seed a Sport. Add a private `makeActiveDailyFor(string $date)` helper mirroring Task 5's.)

- [ ] **Step 2: Run** → FAIL (404 route). **Step 3: Implement service:**

```php
<?php

namespace App\Services;

use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cheap, index-backed friend suggestions. v1 signals, in order:
 *   1. same tournament (league_members self-join, both sides indexed)
 *   2. recently active players (daily_challenge_guesses in the last 14 days)
 * Excludes: self, existing friends, users with a pending request in either
 * direction, recently-rejected pairs (30d, mirrors FriendController cooldown),
 * and anonymized accounts.
 */
class FriendSuggestionService
{
    public const MAX_SUGGESTIONS = 10;
    private const ACTIVE_DAYS = 14;

    public function __construct(private PlayerRankService $ranks)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function forUser(User $user, int $limit = self::MAX_SUGGESTIONS): array
    {
        $excludedIds = $this->excludedIds($user);

        $peerIds = DB::table('league_members as mine')
            ->join('league_members as theirs', 'mine.league_id', '=', 'theirs.league_id')
            ->where('mine.user_id', $user->id)
            ->where('theirs.user_id', '!=', $user->id)
            ->groupBy('theirs.user_id')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(50)
            ->pluck('theirs.user_id');

        $rows = [];
        foreach ($this->visibleUsers($peerIds, $excludedIds, $limit) as $peer) {
            $rows[] = $this->row($peer, 'same_tournament');
            $excludedIds[] = $peer->id;
        }

        if (count($rows) < $limit) {
            $activeIds = DailyChallengeGuess::where('submitted_at', '>=', now()->subDays(self::ACTIVE_DAYS))
                ->groupBy('user_id')
                ->orderByDesc(DB::raw('MAX(submitted_at)'))
                ->limit(50)
                ->pluck('user_id');

            foreach ($this->visibleUsers($activeIds, $excludedIds, $limit - count($rows)) as $peer) {
                $rows[] = $this->row($peer, 'active_player');
            }
        }

        return $rows;
    }

    /** @return array<int, int> ids never to suggest (self, friends, pending/recently-rejected pairs) */
    private function excludedIds(User $user): array
    {
        $friendIds = Friendship::where('user_id', $user->id)->pluck('friend_id');

        $requestPairs = FriendRequest::query()
            ->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('recipient_id', $user->id))
            ->where(fn ($q) => $q->where('status', FriendRequest::STATUS_PENDING)
                ->orWhere(fn ($qq) => $qq->where('status', FriendRequest::STATUS_REJECTED)
                    ->where('updated_at', '>=', now()->subDays(30))))
            ->get(['requester_id', 'recipient_id']);

        return $friendIds
            ->merge($requestPairs->pluck('requester_id'))
            ->merge($requestPairs->pluck('recipient_id'))
            ->push($user->id)
            ->unique()->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function visibleUsers($candidateIds, array $excludedIds, int $limit)
    {
        if ($limit <= 0 || $candidateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $candidateIds)
            ->whereNotIn('id', $excludedIds)
            ->whereNull('anonymized_at')
            ->whereNotNull('friend_code')
            ->limit($limit)
            ->get()
            // preserve the signal ordering (shared-tournament count / recency)
            ->sortBy(fn (User $u) => array_search($u->id, $candidateIds->all()))
            ->values();
    }

    private function row(User $peer, string $reason): array
    {
        $rank = $this->ranks->forUser($peer);

        return [
            'id'         => $peer->id,
            'name'       => $peer->name,
            'username'   => $peer->username,
            'avatar_url' => $peer->avatarUrl(),
            'rank_name'  => $rank['rank']['name'] ?? null,
            'level'      => $rank['level'] ?? null,
            'total_xp'   => $rank['total_xp'] ?? null,
            'reason'     => $reason,
        ];
    }
}
```

**While implementing, verify against `FriendController::summary()` (L191-204)** — copy its exact `PlayerRankService` result shape for `rank_name`/`level`/`total_xp` instead of guessing the array keys above, and reuse the same status constants actually defined on `FriendRequest` (L9-12).

Controller method (inject `FriendSuggestionService` via method DI):

```php
/** GET /api/friends/suggestions — safe public fields + a reason label only. */
public function suggestions(Request $request, FriendSuggestionService $suggestions): JsonResponse
{
    return response()->json(['data' => $suggestions->forUser($request->user())]);
}
```

Route (with the other literal friend routes, above wildcards):

```php
Route::get('/friends/suggestions', [FriendController::class, 'suggestions']);
```

- [ ] **Step 4: Run** `php artisan test --filter="FriendSuggestionsTest|FriendsTest"` → PASS; check `php artisan route:list | grep suggestions`. **Step 5: Commit** — `feat: friend suggestions endpoint (same-tournament + active-player signals)`

---

### Task 9: Friend suggestions UI (Phase 2 mobile)

**Files:**
- Modify: `mobile/src/api/friendsApi.ts`
- Modify: `mobile/src/types/friend.ts`
- Modify: `mobile/src/screens/FriendsScreen.tsx`

**Interfaces:**
- Consumes: `GET /friends/suggestions` (Task 8) and `POST /friends/requests {user_id}` (Task 7).

- [ ] **Step 1: API + types**

`friend.ts`:

```ts
export interface FriendSuggestion {
  id: number;
  name: string | null;
  username: string;
  avatar_url: string | null;
  rank_name: string | null;
  level: number | null;
  total_xp: number | null;
  reason: 'same_tournament' | 'active_player';
}
```

`friendsApi.ts`:

```ts
suggestions: () =>
  apiClient.request<{ data: FriendSuggestion[] }>('/friends/suggestions').then((r) => r.data),
sendRequestById: (userId: number) =>
  apiClient.request('/friends/requests', { method: 'POST', body: { user_id: userId } }),
```

(Match the exact `apiClient.request` call signature used by the existing `sendRequest` — body serialization may be `JSON.stringify` or an object; copy it.)

- [ ] **Step 2: Screen** — in `FriendsScreen.tsx`:

State additions:

```ts
const [suggestions, setSuggestions] = useState<FriendSuggestion[]>([]);
const [sentIds, setSentIds] = useState<Set<number>>(new Set());
const [suggestBusyId, setSuggestBusyId] = useState<number | null>(null);
```

Load suggestions inside the existing `load()`'s `Promise.allSettled` (4th promise, non-fatal on failure — empty list).

New section between "Sent requests" and "Add a friend" (reuse the exact row markup/styles of the existing friend rows — `row`, `rowText`, `rowName`, `rowSub`, `Avatar size={40}`, `actionBtn`):

```tsx
<CollapsibleSection title="Suggested friends">
  {suggestions.length === 0 ? (
    <Text style={styles.emptyText}>No suggestions right now — check back later.</Text>
  ) : (
    suggestions.map((s, i) => (
      <View key={s.id} style={[styles.row, i > 0 && styles.rowDivider]}>
        <Avatar uri={s.avatar_url} name={s.name ?? s.username} size={40} />
        <View style={styles.rowText}>
          <Text style={styles.rowName} numberOfLines={1}>{s.name ?? s.username}</Text>
          <Text style={styles.rowSub} numberOfLines={1}>
            {s.reason === 'same_tournament' ? 'Played in the same tournament' : 'Active player'}
          </Text>
        </View>
        {sentIds.has(s.id) ? (
          <Text style={styles.sentLabel}>Request sent</Text>
        ) : (
          <TouchableOpacity
            style={styles.actionBtn}
            disabled={suggestBusyId === s.id}
            onPress={() => handleSuggestAdd(s.id)}
          >
            <Text style={styles.actionBtnText}>Add</Text>
          </TouchableOpacity>
        )}
      </View>
    ))
  )}
</CollapsibleSection>
```

Handler:

```ts
const handleSuggestAdd = async (userId: number) => {
  setSuggestBusyId(userId);
  try {
    await friendsApi.sendRequestById(userId);
    setSentIds((prev) => new Set(prev).add(userId));
    // refresh outgoing so the "Sent requests" section stays truthful
    friendsApi.requests().then((r) => setOutgoing(r.outgoing)).catch(() => {});
  } catch {
    // keep the button; user can retry
  } finally {
    setSuggestBusyId(null);
  }
};
```

Match existing empty/error text styles (`emptyText` etc. — reuse whatever the other sections use; add `sentLabel` styled like `rowSub` with `color: theme.success`). Match the existing `styles.actionBtn` / accept-button text style names — read them first, don't invent new names when equivalents exist.

- [ ] **Step 3: Verify** — `cd mobile && npx tsc --noEmit` → 0 errors. **Step 4: Commit** — `feat: suggested friends section in Friends tab`

---

### Task 10: `imageLayout` util + minimal jest (Phase 3 groundwork)

**Files:**
- Create: `mobile/src/utils/imageLayout.ts`
- Create: `mobile/src/utils/__tests__/imageLayout.test.ts`
- Create: `mobile/jest.config.js`
- Modify: `mobile/package.json` (devDeps + `"test": "jest"` script)

**Interfaces:**
- Produces (Task 11 consumes):

```ts
export interface Rect { x: number; y: number; width: number; height: number }
export function containRect(containerW: number, containerH: number, aspect: number): Rect
export function pointToRatios(rect: Rect, px: number, py: number): { xRatio: number; yRatio: number } | null
export function ratiosToPoint(rect: Rect, xRatio: number, yRatio: number): { x: number; y: number }
```

- [ ] **Step 1: Install tooling** — `cd mobile && npm i -D jest@^29 ts-jest@^29 @types/jest`. `jest.config.js`:

```js
/** Minimal runner: pure-TS utils only. RN component testing is out of scope. */
module.exports = {
  preset: 'ts-jest',
  testEnvironment: 'node',
  roots: ['<rootDir>/src/utils'],
};
```

Add `"test": "jest"` to scripts. If ts-jest fights the Expo tsconfig (jsx flag), give it an inline override in the config: `transform: { '^.+\\.ts$': ['ts-jest', { tsconfig: { jsx: 'react-jsx' } }] }`.

- [ ] **Step 2: Failing tests** — `imageLayout.test.ts`:

```ts
import { containRect, pointToRatios, ratiosToPoint } from '../imageLayout';

describe('containRect', () => {
  it('letterboxes top/bottom when image is wider than container', () => {
    // 400x800 window, 2:1 landscape image → 400x200 centered vertically
    expect(containRect(400, 800, 2)).toEqual({ x: 0, y: 300, width: 400, height: 200 });
  });

  it('letterboxes left/right when image is taller than container', () => {
    // 400x400 window, 1:2 portrait image → 200x400 centered horizontally
    expect(containRect(400, 400, 0.5)).toEqual({ x: 100, y: 0, width: 200, height: 400 });
  });

  it('fills exactly when aspects match', () => {
    expect(containRect(300, 400, 0.75)).toEqual({ x: 0, y: 0, width: 300, height: 400 });
  });

  it('returns empty rect for degenerate input', () => {
    expect(containRect(0, 400, 1.5)).toEqual({ x: 0, y: 0, width: 0, height: 0 });
  });
});

describe('pointToRatios', () => {
  const rect = { x: 0, y: 300, width: 400, height: 200 };

  it('maps a tap inside the displayed image to 0..1 ratios', () => {
    expect(pointToRatios(rect, 200, 400)).toEqual({ xRatio: 0.5, yRatio: 0.5 });
  });

  it('maps corners exactly', () => {
    expect(pointToRatios(rect, 0, 300)).toEqual({ xRatio: 0, yRatio: 0 });
    expect(pointToRatios(rect, 400, 500)).toEqual({ xRatio: 1, yRatio: 1 });
  });

  it('returns null for taps in the letterbox area', () => {
    expect(pointToRatios(rect, 200, 100)).toBeNull();
    expect(pointToRatios(rect, 200, 700)).toBeNull();
  });

  it('returns null for an empty rect', () => {
    expect(pointToRatios({ x: 0, y: 0, width: 0, height: 0 }, 10, 10)).toBeNull();
  });
});

describe('ratiosToPoint', () => {
  it('is the inverse of pointToRatios', () => {
    const rect = { x: 100, y: 0, width: 200, height: 400 };
    expect(ratiosToPoint(rect, 0.25, 0.5)).toEqual({ x: 150, y: 200 });
  });
});
```

- [ ] **Step 3: Run** `npm test` → FAIL (module missing). **Step 4: Implement:**

```ts
/**
 * Geometry for images rendered with resizeMode="contain" inside a container
 * that may letterbox. All guess coordinates in the app are 0..1 ratios
 * relative to the displayed image rectangle.
 */
export interface Rect { x: number; y: number; width: number; height: number }

/** The rectangle the image actually occupies inside containerW×containerH. */
export function containRect(containerW: number, containerH: number, aspect: number): Rect {
  if (containerW <= 0 || containerH <= 0 || aspect <= 0 || !Number.isFinite(aspect)) {
    return { x: 0, y: 0, width: 0, height: 0 };
  }
  const containerAspect = containerW / containerH;
  if (containerAspect > aspect) {
    const width = containerH * aspect;
    return { x: (containerW - width) / 2, y: 0, width, height: containerH };
  }
  const height = containerW / aspect;
  return { x: 0, y: (containerH - height) / 2, width: containerW, height };
}

/** Tap (px,py) in container coords → image ratios; null if outside the image. */
export function pointToRatios(rect: Rect, px: number, py: number): { xRatio: number; yRatio: number } | null {
  if (rect.width <= 0 || rect.height <= 0) return null;
  if (px < rect.x || px > rect.x + rect.width || py < rect.y || py > rect.y + rect.height) return null;
  const clamp = (v: number) => Math.min(1, Math.max(0, v));
  return { xRatio: clamp((px - rect.x) / rect.width), yRatio: clamp((py - rect.y) / rect.height) };
}

/** Image ratios → container coords (marker placement). */
export function ratiosToPoint(rect: Rect, xRatio: number, yRatio: number): { x: number; y: number } {
  return { x: rect.x + xRatio * rect.width, y: rect.y + yRatio * rect.height };
}
```

- [ ] **Step 5: Run** `npm test` → all PASS; `npx tsc --noEmit` clean. **Step 6: Commit** — `feat: contain-mode image coordinate helpers with jest coverage`

---

### Task 11: Selectable `FullscreenImageViewer` + controlled `ImageGuessPicker` point

**Files:**
- Modify: `mobile/src/components/FullscreenImageViewer.tsx`
- Modify: `mobile/src/components/ImageGuessPicker.tsx`

**Interfaces:**
- Produces — `FullscreenImageViewer` new OPTIONAL props (all absent = exact current behavior, so result screens are untouched):

```ts
interface Props {
  visible: boolean;
  imageUri: string | null;
  onClose: () => void;
  selectable?: boolean;                                  // enable tap-to-guess
  selectedPoint?: { x: number; y: number } | null;       // 0..1 ratios
  onSelectPoint?: (xRatio: number, yRatio: number) => void;
}
```

- Produces — `ImageGuessPicker` new OPTIONAL prop `selectedPoint?: { x: number; y: number } | null`. When the prop is passed (not `undefined`), the marker renders from it (controlled); internal tap state remains for existing uncontrolled call sites.

- [ ] **Step 1: FullscreenImageViewer** — implement:

1. Probe aspect like `ImageGuessPicker` does (`Image.getSize`, fallback `4/3`), store in state.
2. Compute `rect = containRect(windowWidth, windowHeight, aspect)` each render (`useWindowDimensions` already there).
3. When `selectable`: remove `pointerEvents="none"` from the image wrapper; wrap the image area in a `Pressable` whose `onPress` reads `e.nativeEvent.pageX/pageY` (full-window modal → page coords are container coords; verify with `locationX/locationY` on the full-window pressable first, same preference order as `ImageGuessPicker.handlePress` L70-84) and maps via `pointToRatios(rect, x, y)`; `null` (letterbox tap) does nothing. On a hit call `onSelectPoint(xRatio, yRatio)` — do NOT auto-close; the user sees the marker move and can re-tap.
4. When `selectable`, the backdrop tap-to-close is DISABLED (taps place the ball); closing is via the existing ✕ button only. Non-selectable keeps tap-anywhere-to-close.
5. Marker: when `selectedPoint` is set, render the ghost-ball marker at `ratiosToPoint(rect, selectedPoint.x, selectedPoint.y)` — copy the ghost-ball visual from `ImageGuessPicker.renderMarker` (42px, ⚽ emoji, `pointerEvents="none"`).
6. When `selectable`, add a small hint bar at the bottom (absolute, above home indicator): text `"Tap the image to place your guess"`, styled like the ✕ button chrome (hard-coded dark chrome is fine — this modal is deliberately unthemed black).

Implementation sketch (adapt to the actual file):

```tsx
const [aspect, setAspect] = useState(DEFAULT_ASPECT);
useEffect(() => {
  if (!imageUri) return;
  let alive = true;
  Image.getSize(imageUri, (w, h) => { if (alive && w > 0 && h > 0) setAspect(w / h); }, () => {});
  return () => { alive = false; };
}, [imageUri]);

const rect = containRect(width, height, aspect);

const handleImagePress = (e: GestureResponderEvent) => {
  if (!selectable || !onSelectPoint) return;
  const { locationX, locationY, pageX, pageY } = e.nativeEvent;
  const px = Number.isFinite(locationX) ? locationX : pageX;
  const py = Number.isFinite(locationY) ? locationY : pageY;
  const mapped = pointToRatios(rect, px, py);
  if (mapped) onSelectPoint(mapped.xRatio, mapped.yRatio);
};
```

- [ ] **Step 2: ImageGuessPicker controlled point** — add prop, and in the render decide the marker source:

```ts
selectedPoint?: { x: number; y: number } | null;
```

```ts
const isControlled = selectedPoint !== undefined;
const shownGuess = isControlled
  ? (selectedPoint ? { xRatio: selectedPoint.x, yRatio: selectedPoint.y } : null)
  : guess;
```

Render the preview marker from `shownGuess` instead of `guess` (the internal `setGuess` in `applyGuess` stays — harmless for controlled users, keeps uncontrolled sites working).

- [ ] **Step 3: Verify** — `npx tsc --noEmit` → 0 errors. Existing call sites compile untouched (all new props optional). **Step 4: Commit** — `feat: fullscreen viewer tap-to-guess + controlled guess marker`

---

### Task 12: Wire fullscreen guessing into Daily / Tournament / Pack guess screens

**Files:**
- Modify: `mobile/src/screens/GuessScreen.tsx` (picker ~L179-183, viewer ~L208-212)
- Modify: `mobile/src/screens/DailyChallengeScreen.tsx` (picker L166, viewer L185)
- Modify: `mobile/src/screens/PackGuessScreen.tsx` (picker L116, viewer L133)

**Interfaces:**
- Consumes: Task 11 props. Each screen already holds `guessX/guessY` state + a `handleGuess(x, y)` setter — those become the single source of truth in both normal and fullscreen mode.

- [ ] **Step 1: Apply the same three-line change per screen** (shown for `DailyChallengeScreen`; identical pattern in the other two, using each screen's own state/prop names):

```tsx
<ImageGuessPicker
  imageUri={...existing...}
  onGuess={handleGuess}
  selectedPoint={guessX != null && guessY != null ? { x: guessX, y: guessY } : null}
/>
...
<FullscreenImageViewer
  visible={fullscreen}
  imageUri={...existing...}
  onClose={() => setFullscreen(false)}
  selectable
  selectedPoint={guessX != null && guessY != null ? { x: guessX, y: guessY } : null}
  onSelectPoint={handleGuess}
/>
```

Result screens (`ResultImageSection`, `PackResultScreen`) are NOT touched — they stay view-only.

- [ ] **Step 2: Verify** — `npx tsc --noEmit` → 0 errors; `npx expo export --platform web` builds. **Step 3: Commit** — `feat: tap-to-guess in fullscreen on daily, tournament and pack screens`

---

### Task 13: 7 new trophies — seeder, evaluators, triggers, tests (Phase 5)

**Files:**
- Modify: `backend/database/seeders/BadgeSeeder.php` (append after v1.8.0 block, L48-51)
- Modify: `backend/app/Services/BadgeService.php`
- Modify: `backend/app/Http/Controllers/Api/FriendController.php` (`accept()` L136-156)
- Modify: `backend/app/Http/Controllers/Api/LeagueController.php` (`store()` L55-59)
- Modify: `backend/tests/Feature/BadgeTest.php` (26 → 33 count; grep for other `26` assertions e.g. `BadgeExpansionTest`)
- Create: `backend/tests/Feature/BadgeSprintV186Test.php`

**Trophy decisions (state in final report):**
- Implemented: `social_starter`, `friendly_five`, `host_starter`, `tournament_regular`, `sharp_scorer` (the spec's "Sharp Shooter" renamed — `top_10_percent_daily` already uses the display name "Sharp Shooter"), `pack_explorer` (3 DISTINCT packs), `daily_loyalist` (14 dailies).
- Skipped as duplicates of existing badges: "Consistent Player" (= `streak_7` Week Warrior), "Almost Perfect" (`almost_perfect` exists), "Perfect Picker" (`perfect_picker` exists).
- Skipped as future (feature missing): "Invited Player" — tournament invites don't exist (join is by code).
- `sharp_scorer` counts daily + tournament guesses only (pack guesses are evaluated at pack completion, not per-guess — documented limitation).

- [ ] **Step 1: Failing tests** — `BadgeSprintV186Test.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Services\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeSprintV186Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    public function test_seeder_contains_33_badges_including_new_codes(): void
    {
        $this->assertSame(33, \App\Models\Badge::count());
        foreach (['social_starter', 'friendly_five', 'host_starter', 'tournament_regular',
                  'sharp_scorer', 'pack_explorer', 'daily_loyalist'] as $code) {
            $this->assertDatabaseHas('badges', ['code' => $code]);
        }
    }

    public function test_social_starter_awarded_to_both_parties_on_accept(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $req = FriendRequest::create(['requester_id' => $a->id, 'recipient_id' => $b->id, 'status' => 'pending']);

        $this->actingWithToken($b)
            ->postJson("/api/friends/requests/{$req->id}/accept")
            ->assertOk();

        $this->assertTrue($a->fresh()->badges()->where('code', 'social_starter')->exists());
        $this->assertTrue($b->fresh()->badges()->where('code', 'social_starter')->exists());
    }

    public function test_friendly_five_awarded_at_five_friends(): void
    {
        $user = User::factory()->create();
        foreach (User::factory()->count(5)->create() as $friend) {
            Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
            Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);
        }

        app(BadgeService::class)->evaluateFriendAccepted($user->fresh());
        $this->assertTrue($user->badges()->where('code', 'friendly_five')->exists());
    }

    public function test_host_starter_awarded_on_tournament_creation(): void
    {
        $user = User::factory()->create();

        $this->actingWithToken($user)->postJson('/api/leagues', [
            'name' => 'Badge Cup', 'duration_days' => 3, 'rounds_per_day' => 1,
        ])->assertCreated();

        $this->assertTrue($user->fresh()->badges()->where('code', 'host_starter')->exists());
    }

    public function test_tournament_regular_awarded_at_five_finishes(): void
    {
        $user = User::factory()->create();
        // 5 finish rows across 5 leagues (create leagues minimally; see FriendSuggestionsTest helper)
        for ($i = 0; $i < 5; $i++) {
            TournamentFinish::create([
                'league_id' => $this->makeLeague()->id, 'user_id' => $user->id,
                'placement' => 2, 'total_score' => 100,
            ]);
        }

        app(BadgeService::class)->evaluateTournamentFinish($user, 2);
        $this->assertTrue($user->badges()->where('code', 'tournament_regular')->exists());
    }

    public function test_sharp_scorer_awarded_after_ten_90_plus_guesses(): void
    {
        $user = User::factory()->create();
        // Seed 10 daily guesses with score >= 90 across 10 dailies (dates in the past).
        for ($i = 1; $i <= 10; $i++) {
            $daily = $this->makeActiveDailyFor(now()->subDays($i)->toDateString());
            DailyChallengeGuess::create([
                'daily_challenge_id' => $daily->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.02,
                'score' => 93, 'submitted_at' => now()->subDays($i),
            ]);
        }

        app(BadgeService::class)->evaluateScore($user, 93); // make evaluateScore public OR route through evaluateDailyGuess — see Step 3
        $this->assertTrue($user->badges()->where('code', 'sharp_scorer')->exists());
    }

    public function test_no_duplicate_awards(): void
    {
        $user = User::factory()->create();
        $svc = app(BadgeService::class);
        Friendship::create(['user_id' => $user->id, 'friend_id' => User::factory()->create()->id]);

        $svc->evaluateFriendAccepted($user);
        $svc->evaluateFriendAccepted($user);

        $this->assertSame(1, $user->badges()->where('code', 'social_starter')->count());
    }
}
```

(Add the `makeLeague()` / `makeActiveDailyFor()` private helpers mirroring earlier tasks. **Check `evaluateScore`'s current signature/visibility at `BadgeService.php:147-160` first** — if keeping it private, test `sharp_scorer` through `evaluateDailyGuess` with a real daily guess instead; prefer the route the production code actually uses. Check the `/api/leagues` create payload against `CreateLeagueRequest` rules and `assertCreated` vs `assertOk` against `LeagueController::store`'s real status.)

Also in `BadgeTest.php`: change the `26` assertion to `33`; grep `backend/tests` for other hardcoded badge totals.

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**

Seeder — append:

```php
// v1.8.6 public-beta sprint — social / host / consistency trophies
['code' => 'social_starter',     'name' => 'Social Starter',     'description' => 'Add your first friend.',            'icon' => '🤝', 'category' => 'social',     'rarity' => 'common'],
['code' => 'friendly_five',      'name' => 'Friendly Five',      'description' => 'Have five friends.',                'icon' => '👥', 'category' => 'social',     'rarity' => 'rare'],
['code' => 'host_starter',       'name' => 'Host Starter',       'description' => 'Create your first tournament.',     'icon' => '🏟️', 'category' => 'tournament', 'rarity' => 'common'],
['code' => 'tournament_regular', 'name' => 'Tournament Regular', 'description' => 'Complete five tournaments.',        'icon' => '🎽', 'category' => 'tournament', 'rarity' => 'rare'],
['code' => 'sharp_scorer',       'name' => 'Sharp Scorer',       'description' => 'Score 90+ on ten guesses.',         'icon' => '🎯', 'category' => 'skill',      'rarity' => 'rare'],
['code' => 'pack_explorer',      'name' => 'Pack Explorer',      'description' => 'Complete three different packs.',   'icon' => '🧭', 'category' => 'pack',       'rarity' => 'rare'],
['code' => 'daily_loyalist',     'name' => 'Daily Loyalist',     'description' => 'Play fourteen daily challenges.',   'icon' => '📅', 'category' => 'daily',      'rarity' => 'rare'],
```

`BadgeService` additions (follow the existing evaluator style; all return arrays of newly-awarded `Badge`s via `clean()`):

```php
/** Called for BOTH parties when a friend request is accepted. */
public function evaluateFriendAccepted(User $user): array
{
    $count = \App\Models\Friendship::where('user_id', $user->id)->count();
    $new = [];
    if ($count >= 1) {
        $new[] = $this->award($user, 'social_starter');
    }
    if ($count >= 5) {
        $new[] = $this->award($user, 'friendly_five');
    }
    return $this->clean($new);
}

/** Called when a user creates a tournament. */
public function evaluateTournamentCreated(User $user): array
{
    return $this->clean([$this->award($user, 'host_starter')]);
}
```

In `evaluateTournamentFinish` (L181-193) add before returning:

```php
if (\App\Models\TournamentFinish::where('user_id', $user->id)->count() >= 5) {
    $new[] = $this->award($user, 'tournament_regular');
}
```

In `evaluateScore` (L147-160) add:

```php
if ($score >= 90 && !$user->badges()->where('code', 'sharp_scorer')->exists()) {
    $highScoring = \App\Models\DailyChallengeGuess::where('user_id', $user->id)->where('score', '>=', 90)->count()
        + \App\Models\Guess::where('user_id', $user->id)->where('score', '>=', 90)->count();
    if ($highScoring >= 10) {
        $new[] = $this->award($user, 'sharp_scorer');
    }
}
```

(Verify the tournament guess model class name — grep `class Guess` in `app/Models`.)

In `evaluatePackCompletion` (L235-255) add:

```php
$distinctPacks = \App\Models\PackAttempt::where('user_id', $user->id)
    ->where('status', \App\Models\PackAttempt::STATUS_COMPLETED)
    ->distinct('challenge_pack_id')->count('challenge_pack_id');
if ($distinctPacks >= 3) {
    $new[] = $this->award($user, 'pack_explorer');
}
```

In `evaluateDailyGuess` (L85-118) add:

```php
if (\App\Models\DailyChallengeGuess::where('user_id', $user->id)->count() >= 14) {
    $new[] = $this->award($user, 'daily_loyalist');
}
```

Triggers:
- `FriendController::accept()` — inject `BadgeService` (constructor, alongside `PlayerRankService`); after the accept transaction commits:

```php
$this->badges->evaluateFriendAccepted($friendRequest->requester);
$this->badges->evaluateFriendAccepted($request->user());
```

(Silent award — the friends UI has no badge-toast flow; users see them in Trophy Room. Use the actual variable names in `accept()`.)

- `LeagueController::store()` — after successful create:

```php
app(\App\Services\BadgeService::class)->evaluateTournamentCreated($request->user());
```

- [ ] **Step 4: Run** `php artisan test --filter="Badge"` → all badge tests pass (old + new). Full suite green. **Step 5: Commit** — `feat: 7 new trophies (social, host, regular, sharp scorer, pack explorer, daily loyalist)`

---

### Task 14: Safe badge backfill command

**Files:**
- Create: `backend/app/Console/Commands/BackfillSprintBadges.php`
- Modify: `backend/tests/Feature/BadgeSprintV186Test.php` (add one test)

**Interfaces:**
- Produces: `ballspot:backfill-sprint-badges {--dry-run} {--user=}` — awards ONLY the 7 new count-based badges to users whose historical counts already qualify. Idempotent (award() dedupes). NOT scheduled; run once manually after deploy.

- [ ] **Step 1: Failing test:**

```php
public function test_backfill_awards_qualifying_historical_users(): void
{
    $user = User::factory()->create();
    Friendship::create(['user_id' => $user->id, 'friend_id' => User::factory()->create()->id]);

    $this->artisan('ballspot:backfill-sprint-badges')->assertSuccessful();

    $this->assertTrue($user->fresh()->badges()->where('code', 'social_starter')->exists());
}
```

- [ ] **Step 2: Implement:**

```php
<?php

namespace App\Console\Commands;

use App\Models\DailyChallengeGuess;
use App\Models\League;
use App\Models\User;
use App\Services\BadgeService;
use Illuminate\Console\Command;

/**
 * One-off, idempotent backfill for the v1.8.6 trophies. Awards only badges
 * whose qualifying counts already hold. Never removes anything.
 */
class BackfillSprintBadges extends Command
{
    protected $signature = 'ballspot:backfill-sprint-badges {--dry-run} {--user=}';
    protected $description = 'Retroactively award v1.8.6 trophies to users whose stats already qualify';

    public function handle(BadgeService $badges): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $awarded = 0;

        User::query()
            ->whereNull('anonymized_at')
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->chunkById(100, function ($users) use ($badges, $dryRun, &$awarded) {
                foreach ($users as $user) {
                    if ($dryRun) {
                        $this->line("Would evaluate user {$user->id}");
                        continue;
                    }
                    $new = array_merge(
                        $badges->evaluateFriendAccepted($user),
                        League::where('owner_user_id', $user->id)->exists()
                            ? $badges->evaluateTournamentCreated($user) : [],
                        $badges->evaluateTournamentFinish($user, PHP_INT_MAX), // placement badges won't fire; count badge will
                        DailyChallengeGuess::where('user_id', $user->id)->exists()
                            ? $badges->evaluateDailyGuess($user, /* see note */) : [],
                    );
                    $awarded += count($new);
                }
            });

        $this->info($dryRun ? 'Dry run complete.' : "Backfill complete. New badges awarded: {$awarded}.");
        return self::SUCCESS;
    }
}
```

**Note while implementing:** `evaluateTournamentFinish` and `evaluateDailyGuess` have specific signatures (check L181/L85 — daily eval needs a DailyChallenge/guess context). If calling them with sentinel args is awkward, instead add a dedicated `BadgeService::backfillCountBadges(User): array` that runs ONLY the pure count checks (friend counts, host, tournament_regular count, sharp_scorer count, pack_explorer count, daily_loyalist count) and call that from both the command and nothing else. That is the cleaner shape — prefer it if the sentinel approach reads badly.

- [ ] **Step 3: Run** filter test + full suite → green. **Step 4: Commit** — `feat: idempotent v1.8.6 badge backfill command`

---

### Task 15: Trophy Room polish — theme migration, locked readability, grid (Phase 6)

**Files:**
- Modify: `mobile/src/components/TrophyRoom.tsx`

- [ ] **Step 1: Migrate to the theme system** — replace the static `colors` import with the standard pattern used by `FriendsScreen.tsx:24,315`:

```ts
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
// inside component:
const { theme } = useTheme();
const styles = createStyles(theme);
// bottom:
function createStyles(theme: ThemeTokens) { return StyleSheet.create({ ... }); }
```

Map tokens 1:1 (`colors.surface` → `theme.surface`, etc.). `RARITY_COLOR` becomes a function of theme:

```ts
const rarityColor = (theme: ThemeTokens, rarity: string) =>
  ({ common: theme.textSecondary, rare: theme.accent, epic: '#b76bff', legendary: theme.gold } as Record<string, string>)[rarity] ?? theme.textSecondary;
```

(`theme.gold` exists in `ThemeTokens` — better than the old `colors.warning` hack. Keep `#b76bff` for epic; no purple token exists.)

- [ ] **Step 2: Locked-card readability** (small, targeted):
- `cellLocked` opacity `0.45` → `0.7`.
- Locked text color `textMuted` → `textSecondary`.
- Badge name: `numberOfLines={1}` → `numberOfLines={2}`, and give `badgeName` a `minHeight` fitting two lines (measure current fontSize/lineHeight; ~34) plus `textAlign: 'center'` so grid rows stay even.
- Grid cell: add `maxWidth: '31.5%'` (keeps `flexGrow: 1` from stretching last-row cells full-width; 3×31.5% + 2×8px gap fits all supported widths).

- [ ] **Step 3: Verify** — `npx tsc --noEmit` → 0 errors. Visual check happens on device (manual checklist). **Step 4: Commit** — `feat: trophy room theme migration + locked-card readability`

---

### Task 16: Badge detail modal on tap (Phase 6 optional-but-easy)

**Files:**
- Modify: `mobile/src/components/TrophyRoom.tsx`

- [ ] **Step 1: Implement** — make each badge cell a `Pressable` (`onPress={() => setSelected(badge)}`), add state `const [selected, setSelected] = useState<MineBadge | null>(null)` (use the actual badge row type in the file), and render a modal styled after `ConfirmModal`'s overlay/card pattern (read `mobile/src/components/ConfirmModal.tsx` and copy its Modal + overlay + card structure, themed):

```tsx
<Modal visible={selected != null} transparent animationType="fade" onRequestClose={() => setSelected(null)}>
  <Pressable style={styles.modalOverlay} onPress={() => setSelected(null)}>
    <Pressable style={styles.modalCard} onPress={() => {}}>
      {selected && (
        <>
          <Text style={styles.modalIcon}>{selected.earned ? selected.icon : '🔒'}</Text>
          <Text style={styles.modalName}>{selected.name}</Text>
          <Text style={styles.modalDesc}>{selected.description}</Text>
          <Text style={[styles.modalRarity, { color: rarityColor(theme, selected.rarity) }]}>
            {selected.rarity.toUpperCase()}
          </Text>
          <Text style={styles.modalStatus}>
            {selected.earned && selected.earned_at
              ? `Earned ${new Date(selected.earned_at).toLocaleDateString()}`
              : selected.earned ? 'Earned' : 'Locked'}
          </Text>
        </>
      )}
    </Pressable>
  </Pressable>
</Modal>
```

Full name shown untruncated here (no `numberOfLines`). Check the actual earned/`earned_at` field names on the fetched badge rows in this file before writing.

- [ ] **Step 2: Verify** `npx tsc --noEmit`. **Step 3: Commit** — `feat: badge detail modal in trophy room`

---

### Task 17: Docs + privacy updates (Phase 7)

**Files:**
- Modify: `docs/notifications-plan.md`, `docs/security-hardening.md`, `docs/privacy-data-inventory.md`, `docs/privacy-policy-draft.md`, `docs/test-report.md`, `docs/store-readiness.md`, `docs/api-contract.md`, `docs/database-schema.md`

- [ ] **Step 1: notifications-plan.md** — new top section "Daily reminders → backend push (v1.8.6)": how `ballspot:send-daily-reminders` works (15-min cadence, 60-min window, timezone fallback UTC, at-most-once via `last_daily_reminder_date`, mark-before-send), the `BALLPICKER_DAILY_REMINDER_PUSH_ENABLED` flag and the cutover rule (enable only after the v1.8.6 build is live; stale builds may briefly double-notify), `daily_reminder_push_active` payload flag suppressing local scheduling, DeviceNotRegistered pruning, and that sends are synchronous (no queue jobs exist — accepted decision). Mark the old "local reminder can fire after completion" limitation as fixed-when-flag-on.
- [ ] **Step 2: security-hardening.md** — scheduler table (L220-242): add the fifth row `ballspot:send-daily-reminders — every 15 min — withoutOverlapping`. Keep the same single cron line. Add production verification commands:

```
php artisan schedule:list
php artisan ballspot:send-daily-reminders --dry-run
```

- [ ] **Step 3: privacy-data-inventory.md** — add rows/notes: `users.anonymized_at` (deletion bookkeeping, aggregate metric only), `notification_settings.last_daily_reminder_date` (reminder dedupe, deleted with settings row on account deletion — verify Task 3's column is inside the `notification_settings` hard-delete in `AccountController`, which it is by table), friend suggestions (derived from existing tournament-membership + activity data, exposes only the same public fields as the friends list + a reason label; no email/friend_code), fullscreen guessing (same single guess coordinate as normal guessing — no new data), new badges (gameplay/profile data as before).
- [ ] **Step 4: privacy-policy-draft.md** — in "What we collect and why" ensure push tokens/reminder preferences cover server-sent reminders; in deletion section note the aggregate deleted-account count retains no personal data.
- [ ] **Step 5: test-report.md** — new top section "v1.8.6 public-beta sprint (2026-08-10)": audit verdict (daily reminders were NEVER backend-sent before this sprint; local-only), what was built, new test files/counts (record the real `php artisan test` totals from Task 18), the daily `scheduled`-vs-`active` status gap finding, and the fixed stale line at ~L1133 ("No cron wiring yet" — correct it to reference the scheduler).
- [ ] **Step 6: store-readiness.md** — append v1.8.6 notes: new EAS build REQUIRED (fullscreen guessing, suggestions UI, trophy room, local-reminder gate are all native-bundle JS), deploy order (backend first is safe; flag flip after build adoption), badge count 33.
- [ ] **Step 7: api-contract.md + database-schema.md** — add `GET /api/friends/suggestions`, `POST /api/friends/requests` `user_id` variant, `daily_reminder_push_active` field, `users.anonymized_at`, `notification_settings.last_daily_reminder_date`, 7 new badge codes.
- [ ] **Step 8: Commit** — `docs: v1.8.6 sprint — daily reminder architecture, suggestions, deletion metric, trophies`

---

### Task 18: Full validation + final report

- [ ] **Step 1: Backend** — `cd backend && php artisan test` → must be fully green (baseline was 430 passed / 1 skipped; expect ~460+). Record exact numbers.
- [ ] **Step 2: Mobile** — `cd mobile && npx tsc --noEmit` (0 errors), `npm test` (jest utils green), `npx expo export --platform web` (builds).
- [ ] **Step 3: Wiring checks** — `php artisan route:list` shows `/api/friends/suggestions`; `php artisan schedule:list` shows 5 commands; `php artisan migrate --pretend` on a copy or review migration list — 3 new migrations, all additive; `php artisan ballspot:send-daily-reminders --dry-run` runs clean locally.
- [ ] **Step 4: Update memory + write final report** per the sprint spec's FINAL REPORT section, including verbatim: daily reminders were NOT backend-wired before; manual device checklist still required; scheduled-vs-active daily status gap; stale-build double-notification window during flag cutover; EAS build required.
- [ ] **Step 5: Final commit** of any remaining changes.

## Deploy notes (for the final report)

```bash
# backend (safe first, flag stays off)
cd backend
php artisan migrate            # 3 additive migrations
php artisan db:seed --class=BadgeSeeder
php artisan ballspot:backfill-sprint-badges --dry-run   # review, then run without --dry-run
php artisan schedule:list      # verify 5 entries; cron line unchanged
# after the v1.8.6 EAS build has adoption:
# set BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=true in .env, php artisan config:cache
php artisan ballspot:send-daily-reminders --dry-run     # production verification
```

## Self-review notes

- Spec coverage: Phase 1 → Tasks 3-6; Phase 2 → 7-9; Phase 3 → 10-12; Phase 4 → 1-2; Phase 5 → 13-14; Phase 6 → 15-16; Phase 7 → 17; validation → 18. Duplicate-reminder requirement handled both server-side (`last_daily_reminder_date`, mark-before-send) and cross-channel (`daily_reminder_push_active` local gate).
- Deliberate deviations from spec, to surface in the final report: "Sharp Shooter" renamed `sharp_scorer`/"Sharp Scorer" (name collision), "Consistent Player"/"Almost Perfect"/"Perfect Picker" skipped as existing duplicates, "Invited Player" skipped (no invites feature), reminder sends synchronous (no queue jobs exist anywhere; a queued path would depend on an unverified worker).
- Type consistency: `selectedPoint {x,y}` ratios used identically in Tasks 11/12; `sendMessages` return shape used in Tasks 4/5; `anonymized_at` predicate used in Tasks 1/2/5/7/8/14.
