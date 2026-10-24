<?php

namespace Tests\Feature;

use App\Models\AppEvent;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v1.9.12 growth features: share rewards, review/rate telemetry and the
 * admin Growth page. The one rule every test here protects: sharing may be
 * rewarded (bounded, idempotent); rating / reviewing never is.
 */
class GrowthShareReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: User, 1: string} */
    private function auth(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function dailyWithGuess(User $user, int $score, ?string $date = null): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Share ' . uniqid(),
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/share.jpg',
        ]);
        $daily = DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $date ?? today()->toDateString(),
            'status'         => 'active',
        ]);
        DailyChallengeGuess::create([
            'daily_challenge_id' => $daily->id,
            'user_id'            => $user->id,
            'guess_x_ratio'      => 0.5,
            'guess_y_ratio'      => 0.5,
            'distance'           => 0.0,
            'score'              => $score,
            'submitted_at'       => now(),
        ]);

        return $daily;
    }

    private function totalXp(User $user): int
    {
        return (int) XpEvent::where('user_id', $user->id)->sum('amount');
    }

    private function shareXp(User $user): int
    {
        return (int) XpEvent::where('user_id', $user->id)
            ->whereIn('source_type', [XpEvent::SOURCE_SHARE_FIRST, XpEvent::SOURCE_SHARE_DAILY, XpEvent::SOURCE_SHARE_DAILY_HIGH_SCORE])
            ->sum('amount');
    }

    private function eventCount(User $user, string $name): int
    {
        return AppEvent::where('user_id', $user->id)->where('event_name', $name)->count();
    }

    // ------------------------------------------------------------------
    // Share events + rewards
    // ------------------------------------------------------------------

    public function test_share_app_tap_records_event_and_first_share_reward(): void
    {
        [$user, $token] = $this->auth();

        $res = $this->withToken($token)->postJson('/api/share', ['type' => 'app']);

        $res->assertOk()
            ->assertJsonPath('share_count', 1)
            ->assertJsonPath('rewards', ['first_share'])
            ->assertJsonPath('status.first_share_available', false);

        $this->assertSame(1, $this->eventCount($user, AppEvent::SHARE_APP_TAPPED));
        $this->assertSame(1, $this->eventCount($user, AppEvent::SHARE_REWARD_CLAIMED));
        $this->assertSame(200, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_FIRST)->sum('amount'));
        $this->assertTrue($user->badges()->where('code', 'first_share')->exists());
        $this->assertContains('first_share', array_column($res->json('new_badges'), 'code'));
    }

    public function test_daily_result_share_records_event_and_daily_reward(): void
    {
        [$user, $token] = $this->auth();
        $daily = $this->dailyWithGuess($user, 55);

        $res = $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id]);

        $res->assertOk();
        $this->assertEqualsCanonicalizing(['first_share', 'daily_share'], $res->json('rewards'));
        $this->assertSame(1, $this->eventCount($user, AppEvent::DAILY_RESULT_SHARE_TAPPED));
        $this->assertSame(0, $this->eventCount($user, AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED), 'a 55 is not a high score');
        $this->assertSame(10, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_DAILY)->sum('amount'));
        $this->assertFalse($user->badges()->where('code', 'daily_bragger')->exists());
    }

    public function test_high_score_share_is_recorded_and_rewarded_for_80_plus(): void
    {
        [$user, $token] = $this->auth();
        $daily = $this->dailyWithGuess($user, 80);

        $res = $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id]);

        $res->assertOk();
        $this->assertEqualsCanonicalizing(['first_share', 'daily_share', 'daily_high_score_share'], $res->json('rewards'));
        $this->assertSame(1, $this->eventCount($user, AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED));
        $this->assertSame(15, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_DAILY_HIGH_SCORE)->sum('amount'));
        $this->assertTrue($user->badges()->where('code', 'daily_bragger')->exists());
        // The stored metadata is the score and the daily id — nothing else.
        $event = AppEvent::where('user_id', $user->id)->where('event_name', AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED)->first();
        $this->assertEqualsCanonicalizing(['daily_challenge_id' => $daily->id, 'score' => 80], $event->metadata);
    }

    public function test_share_reward_claimed_is_recorded_only_when_something_was_awarded(): void
    {
        [$user, $token] = $this->auth();

        $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk();
        $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk()->assertJsonPath('rewards', []);

        $this->assertSame(2, $this->eventCount($user, AppEvent::SHARE_APP_TAPPED));
        $this->assertSame(1, $this->eventCount($user, AppEvent::SHARE_REWARD_CLAIMED));
    }

    public function test_share_rewards_cannot_be_farmed_by_repeated_requests(): void
    {
        [$user, $token] = $this->auth();
        $daily = $this->dailyWithGuess($user, 95);

        for ($i = 0; $i < 6; $i++) {
            $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])->assertOk();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk();
        }

        // 200 (first) + 10 (daily) + 15 (high score) — exactly once each.
        $this->assertSame(225, $this->shareXp($user));
        $this->assertSame(9, AppEvent::where('user_id', $user->id)->whereIn('event_name', AppEvent::SHARE_EVENTS)->count());
        // Five shares unlock Social Striker exactly once.
        $this->assertSame(1, $user->badges()->where('code', 'social_striker')->count());
        // Badge unlocks are bounded by the unique index as well.
        $this->assertSame(3, $user->badges()->whereIn('code', ['first_share', 'daily_bragger', 'social_striker'])->count());
    }

    public function test_daily_share_reward_is_claimable_once_per_calendar_day(): void
    {
        [$user, $token] = $this->auth();

        Carbon::setTestNow('2026-10-14 10:00:00');
        $daily = $this->dailyWithGuess($user, 50);
        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])->assertOk();
        $second = $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])->assertOk();
        $this->assertSame([], $second->json('rewards'));
        $this->assertSame(0, $second->json('xp_awarded'));
        $this->assertSame(10, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_DAILY)->sum('amount'));

        // Next calendar day: the daily reward is available again (first-share is not).
        Carbon::setTestNow('2026-10-15 10:00:00');
        $daily2 = $this->dailyWithGuess($user, 50);
        $third = $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily2->id])->assertOk();
        $this->assertSame(['daily_share'], $third->json('rewards'));
        $this->assertSame(20, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_DAILY)->sum('amount'));

        Carbon::setTestNow();
    }

    public function test_high_score_share_bonus_is_claimable_once_per_calendar_day(): void
    {
        [$user, $token] = $this->auth();

        Carbon::setTestNow('2026-10-14 10:00:00');
        $daily = $this->dailyWithGuess($user, 90);
        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])->assertOk();
        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])->assertOk()->assertJsonPath('rewards', []);
        $this->assertSame(15, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_DAILY_HIGH_SCORE)->sum('amount'));

        Carbon::setTestNow('2026-10-15 10:00:00');
        $daily2 = $this->dailyWithGuess($user, 85);
        $res = $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily2->id])->assertOk();
        $this->assertEqualsCanonicalizing(['daily_share', 'daily_high_score_share'], $res->json('rewards'));
        $this->assertSame(30, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_DAILY_HIGH_SCORE)->sum('amount'));

        Carbon::setTestNow();
    }

    public function test_client_supplied_xp_and_score_are_ignored(): void
    {
        [$user, $token] = $this->auth();
        $daily = $this->dailyWithGuess($user, 30);

        $res = $this->withToken($token)->postJson('/api/share', [
            'type'               => 'daily_result',
            'daily_challenge_id' => $daily->id,
            'score'              => 100,
            'xp'                 => 9999,
            'xp_awarded'         => 9999,
            'message'            => 'I scored 100!',
        ])->assertOk();

        $this->assertNotContains('daily_high_score_share', $res->json('rewards'));
        $this->assertSame(210, $this->shareXp($user)); // 200 + 10, nothing else
        $this->assertSame(0, $this->eventCount($user, AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED));
        $this->assertNull(AppEvent::where('user_id', $user->id)->whereRaw("metadata LIKE '%scored%'")->first(), 'share text must never be stored');
    }

    public function test_daily_result_share_requires_the_users_own_guess(): void
    {
        [$user, $token] = $this->auth();
        [$other] = $this->auth();
        $daily = $this->dailyWithGuess($other, 99);

        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_guess');
        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_guess');
        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result'])->assertStatus(422);
        $this->withToken($token)->postJson('/api/share', ['type' => 'instagram'])->assertStatus(422);

        $this->assertSame(0, $this->shareXp($user));
        $this->assertSame(0, AppEvent::where('user_id', $user->id)->count());
    }

    public function test_first_share_reward_is_200_xp_and_comes_from_the_single_default(): void
    {
        $this->assertSame(200, \App\Services\ShareRewardService::DEFAULT_FIRST_SHARE_XP);
        $this->assertSame(200, (int) config('ballspot.share.first_share_xp'));

        [$user, $token] = $this->auth();
        $res = $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk();

        $this->assertSame(['first_share'], $res->json('rewards'));
        $ledger = XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_FIRST)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame(200, (int) $ledger->first()->amount);
        $this->assertSame(1, (int) $ledger->first()->source_id);
        // xp_awarded = the 200 share XP + the First Share trophy's normal rarity XP.
        $this->assertGreaterThanOrEqual(200, $res->json('xp_awarded'));
    }

    public function test_account_that_already_claimed_the_old_25_xp_first_share_is_not_awarded_again(): void
    {
        [$user, $token] = $this->auth();
        // History from before the change: the old 25 XP first-share award.
        XpEvent::create([
            'user_id'     => $user->id,
            'source_type' => XpEvent::SOURCE_SHARE_FIRST,
            'source_id'   => 1,
            'amount'      => 25,
            'reason'      => 'First share',
        ]);
        $this->withToken($token)->getJson('/api/share/status')
            ->assertOk()
            ->assertJsonPath('first_share_available', false)
            ->assertJsonPath('xp.first_share', 200);

        $res = $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk();
        $this->assertNotContains('first_share', $res->json('rewards'));
        $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk()->assertJsonPath('rewards', []);

        // No top-up to 200, no second row, the historical 25 is untouched.
        $ledger = XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_FIRST)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame(25, (int) $ledger->first()->amount);
        // Only the (one-time) First Share trophy XP may have been added — never share XP.
        $this->assertSame(25, $this->shareXp($user));
        $this->assertSame(0, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_FIRST)->where('amount', 200)->count());
    }

    public function test_first_share_amount_can_still_be_overridden_per_environment(): void
    {
        config(['ballspot.share.first_share_xp' => 75]);
        [$user, $token] = $this->auth();

        $this->withToken($token)->getJson('/api/share/status')->assertOk()->assertJsonPath('xp.first_share', 75);
        $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk();
        $this->assertSame(75, (int) XpEvent::where('user_id', $user->id)->where('source_type', XpEvent::SOURCE_SHARE_FIRST)->sum('amount'));
    }

    public function test_share_status_reports_what_is_still_available(): void
    {
        [$user, $token] = $this->auth();

        $this->withToken($token)->getJson('/api/share/status')
            ->assertOk()
            ->assertJsonPath('total_shares', 0)
            ->assertJsonPath('first_share_available', true)
            ->assertJsonPath('daily_share_available', true)
            ->assertJsonPath('daily_high_score_available', true)
            ->assertJsonPath('high_score_threshold', 80)
            ->assertJsonPath('xp.first_share', 200)
            ->assertJsonPath('xp.daily_share', 10)
            ->assertJsonPath('xp.daily_high_score_share', 15);

        $daily = $this->dailyWithGuess($user, 88);
        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])->assertOk();

        $this->withToken($token)->getJson('/api/share/status')
            ->assertOk()
            ->assertJsonPath('total_shares', 1)
            ->assertJsonPath('first_share_available', false)
            ->assertJsonPath('daily_share_available', false)
            ->assertJsonPath('daily_high_score_available', false);
    }

    public function test_share_endpoints_require_authentication(): void
    {
        $this->postJson('/api/share', ['type' => 'app'])->assertStatus(401);
        $this->getJson('/api/share/status')->assertStatus(401);
        $this->postJson('/api/events', ['event' => 'rate_app_tapped'])->assertStatus(401);
        $this->assertSame(0, AppEvent::count());
    }

    public function test_share_claims_are_rate_limited(): void
    {
        [, $token] = $this->auth();

        for ($i = 0; $i < 10; $i++) {
            $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk();
        }
        $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // Review / rate telemetry — never rewarded
    // ------------------------------------------------------------------

    public function test_review_prompt_attempt_is_recorded(): void
    {
        [$user, $token] = $this->auth();

        $this->withToken($token)->postJson('/api/events', ['event' => 'review_prompt_attempted', 'metadata' => ['trigger' => 'daily_score']])
            ->assertStatus(201)
            ->assertJsonPath('recorded', true);
        $this->withToken($token)->postJson('/api/events', ['event' => 'native_review_requested'])->assertStatus(201);
        $this->withToken($token)->postJson('/api/events', ['event' => 'review_prompt_skipped_due_to_completed'])->assertStatus(201);
        $this->withToken($token)->postJson('/api/events', ['event' => 'review_prompt_skipped_due_to_cooldown'])->assertStatus(201);

        $this->assertSame(1, $this->eventCount($user, AppEvent::REVIEW_PROMPT_ATTEMPTED));
        $this->assertSame(1, $this->eventCount($user, AppEvent::NATIVE_REVIEW_REQUESTED));
        $this->assertSame(1, $this->eventCount($user, AppEvent::REVIEW_PROMPT_SKIPPED_DUE_TO_COMPLETED));
        $this->assertSame(1, $this->eventCount($user, AppEvent::REVIEW_PROMPT_SKIPPED_DUE_TO_COOLDOWN));
        $this->assertSame(['trigger' => 'daily_score'], AppEvent::where('event_name', AppEvent::REVIEW_PROMPT_ATTEMPTED)->first()->metadata);
    }

    public function test_rate_button_tap_is_recorded(): void
    {
        [$user, $token] = $this->auth();

        $this->withToken($token)->postJson('/api/events', ['event' => 'rate_app_tapped', 'metadata' => ['trigger' => 'button']])->assertStatus(201);

        $this->assertSame(1, $this->eventCount($user, AppEvent::RATE_APP_TAPPED));
    }

    public function test_review_events_never_grant_xp_or_trophies(): void
    {
        [$user, $token] = $this->auth();

        foreach (AppEvent::CLIENT_EVENTS as $event) {
            for ($i = 0; $i < 3; $i++) {
                $this->withToken($token)->postJson('/api/events', ['event' => $event, 'xp' => 500, 'reward' => 'first_share'])->assertStatus(201);
            }
        }

        $this->assertSame(0, $this->totalXp($user));
        $this->assertSame(0, $user->badges()->count());
        $this->assertSame(0, $this->eventCount($user, AppEvent::SHARE_REWARD_CLAIMED));
        $this->assertSame(0, AppEvent::where('user_id', $user->id)->whereIn('event_name', AppEvent::SHARE_EVENTS)->count());
    }

    public function test_events_endpoint_refuses_share_and_unknown_events_and_drops_extra_metadata(): void
    {
        [$user, $token] = $this->auth();

        // Share events can only be written by the share flow — a client must
        // not be able to inflate share stats or claim rewards here.
        foreach ([AppEvent::SHARE_APP_TAPPED, AppEvent::DAILY_RESULT_SHARE_TAPPED, AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED, AppEvent::SHARE_REWARD_CLAIMED, 'review_submitted', 'reviews_placed'] as $event) {
            $this->withToken($token)->postJson('/api/events', ['event' => $event])->assertStatus(422);
        }
        $this->withToken($token)->postJson('/api/events', ['event' => 'rate_app_tapped', 'metadata' => ['trigger' => 'instagram']])->assertStatus(422);

        $this->withToken($token)->postJson('/api/events', [
            'event'    => 'rate_app_tapped',
            'metadata' => ['score' => 90, 'platform' => 'instagram', 'message' => 'Great app', 'review_text' => 'five stars', 'email' => 'x@y.z'],
        ])->assertStatus(201);

        $this->assertSame(1, AppEvent::where('user_id', $user->id)->count());
        $this->assertSame(['score' => 90], AppEvent::where('user_id', $user->id)->first()->metadata);
    }

    public function test_events_endpoint_is_rate_limited(): void
    {
        [, $token] = $this->auth();

        for ($i = 0; $i < 30; $i++) {
            $this->withToken($token)->postJson('/api/events', ['event' => 'review_prompt_skipped_due_to_cooldown'])->assertStatus(201);
        }
        $this->withToken($token)->postJson('/api/events', ['event' => 'review_prompt_skipped_due_to_cooldown'])->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // Admin Growth page
    // ------------------------------------------------------------------

    public function test_admin_growth_page_counts_events_correctly(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $token] = $this->auth();
        [$user2, $token2] = $this->auth();

        $daily = $this->dailyWithGuess($user, 92);
        $this->withToken($token)->postJson('/api/share', ['type' => 'daily_result', 'daily_challenge_id' => $daily->id])->assertOk(); // daily + high + reward
        $this->withToken($token)->postJson('/api/share', ['type' => 'app'])->assertOk();                                             // app, no reward
        $this->withToken($token)->postJson('/api/events', ['event' => 'rate_app_tapped'])->assertStatus(201);
        $this->withToken($token)->postJson('/api/events', ['event' => 'review_prompt_attempted'])->assertStatus(201);
        $this->withToken($token)->postJson('/api/events', ['event' => 'review_prompt_attempted'])->assertStatus(201);
        $this->withToken($token)->postJson('/api/events', ['event' => 'native_review_requested'])->assertStatus(201);

        $this->actingWithToken($token2)->postJson('/api/share', ['type' => 'app'])->assertOk(); // second user's first share → reward

        // An old share outside the 7-day window counts in totals only.
        AppEvent::create(['user_id' => $user->id, 'event_name' => AppEvent::SHARE_APP_TAPPED, 'created_at' => now()->subDays(10)]);
        AppEvent::create(['user_id' => $user->id, 'event_name' => AppEvent::RATE_APP_TAPPED, 'created_at' => now()->subDays(10)]);

        $stats = app(\App\Services\GrowthEventService::class)->stats();
        $this->assertSame(3, $stats['totals']['app_shares']);
        $this->assertSame(1, $stats['totals']['daily_result_shares']);
        $this->assertSame(1, $stats['totals']['high_score_result_shares']);
        $this->assertSame(2, $stats['totals']['share_rewards_claimed']);
        $this->assertSame(2, $stats['totals']['rate_button_taps']);
        $this->assertSame(2, $stats['totals']['review_prompt_attempts']);
        $this->assertSame(1, $stats['totals']['native_review_requests']);
        $this->assertSame(4, $stats['totals']['shares']);
        $this->assertSame(3, $stats['last_7_days']['shares']);
        $this->assertSame(3, $stats['last_7_days']['review_attempts']); // 2 prompt attempts + 1 recent rate tap

        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($admin)->get('/admin/growth');
        $res->assertOk()
            ->assertSee('Total app shares')
            ->assertSee('Total daily result shares')
            ->assertSee('Total high-score result shares')
            ->assertSee('Total share rewards claimed')
            ->assertSee('Total rate button taps')
            ->assertSee('Total review prompt attempts')
            ->assertSee('Total native review requests')
            ->assertSee('Last 7 days shares')
            ->assertSee('Last 7 days review attempts')
            ->assertSee('<code>share_app_tapped</code>', false)
            ->assertSee('<code>review_prompt_skipped_due_to_cooldown</code>', false);

        $html = $res->getContent();
        $this->assertMatchesRegularExpression('/Total app shares<\/th><td>3\b/', $html);
        $this->assertMatchesRegularExpression('/Total daily result shares<\/th><td>1\b/', $html);
        $this->assertMatchesRegularExpression('/Total share rewards claimed<\/th><td>2\b/', $html);
        $this->assertMatchesRegularExpression('/Total rate button taps<\/th><td>2\b/', $html);
        $this->assertMatchesRegularExpression('/Total review prompt attempts<\/th><td>2\b/', $html);
        $this->assertMatchesRegularExpression('/Total native review requests<\/th><td>1\b/', $html);
        $this->assertMatchesRegularExpression('/Last 7 days shares<\/th><td><span class="badge bg-light text-dark">3</', $html);
        $this->assertMatchesRegularExpression('/Last 7 days review attempts<\/th><td><span class="badge bg-light text-dark">3</', $html);
    }

    public function test_admin_growth_labels_never_claim_confirmed_reviews(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->get('/admin/growth');
        $res->assertOk()
            ->assertSee('Review attempts are not confirmed App Store / Google Play reviews.')
            ->assertSee('Native review APIs do not reliably confirm whether a review was submitted.');

        $html = strtolower($res->getContent());
        foreach (['reviews placed', 'reviews submitted', 'confirmed reviews:', 'reviews left', 'reviews written'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Growth page must not claim '{$forbidden}'");
        }
        // Each review metric is worded as attempts / taps / requests.
        foreach (\App\Services\GrowthEventService::LABELS as $name => $label) {
            if (str_contains($name, 'review') || str_contains($name, 'rate')) {
                $this->assertMatchesRegularExpression('/attempt|tap|request|skipped/i', $label, $name);
            }
        }
    }

    public function test_admin_growth_page_is_admin_only(): void
    {
        $this->get('/admin/growth')->assertRedirect(route('admin.login'));

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/admin/growth')->assertForbidden();
    }
}
