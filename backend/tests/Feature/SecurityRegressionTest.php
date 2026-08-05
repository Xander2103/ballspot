<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\PushToken;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the pre-launch security/integrity audit.
 *
 * Each test pins a specific defect that was found and fixed; the name states
 * the attack it blocks.
 */
class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function challenge(string $title = 'Sec Challenge'): Challenge
    {
        return Challenge::create([
            'sport_id'          => $this->sport()->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    // ---------------------------------------------------------------
    // Daily challenges: only today's may be played.
    // ---------------------------------------------------------------

    public function test_a_past_daily_challenge_cannot_be_played(): void
    {
        [, $token] = $this->auth();
        $daily = DailyChallenge::create([
            'challenge_id'   => $this->challenge()->id,
            'challenge_date' => today()->subDays(10)->toDateString(),
            'status'         => 'active',
        ]);

        $res = $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseCount('daily_challenge_guesses', 0);
    }

    public function test_a_future_daily_challenge_cannot_be_played_early(): void
    {
        [, $token] = $this->auth();
        $daily = DailyChallenge::create([
            'challenge_id'   => $this->challenge()->id,
            'challenge_date' => today()->addDay()->toDateString(),
            'status'         => 'active',
        ]);

        $res = $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseCount('daily_challenge_guesses', 0);
    }

    public function test_back_playing_old_dailies_cannot_fabricate_a_streak(): void
    {
        [, $token] = $this->auth();

        // A week of stale "active" dailies, as ScheduleDailyChallenges leaves them.
        for ($i = 1; $i <= 7; $i++) {
            $daily = DailyChallenge::create([
                'challenge_id'   => $this->challenge("C{$i}")->id,
                'challenge_date' => today()->subDays($i)->toDateString(),
                'status'         => 'active',
            ]);

            $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", [
                'guess_x_ratio' => 0.5,
                'guess_y_ratio' => 0.5,
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('daily_challenge_guesses', 0);
        $this->withToken($token)->getJson('/api/daily/stats')
            ->assertOk()
            ->assertJsonPath('current_streak', 0);
    }

    public function test_todays_daily_is_still_playable(): void
    {
        [, $token] = $this->auth();
        $daily = DailyChallenge::create([
            'challenge_id'   => $this->challenge()->id,
            'challenge_date' => today()->toDateString(),
            'status'         => 'active',
        ]);

        $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ])->assertOk();
    }

    // ---------------------------------------------------------------
    // Tournaments: cancelled leagues and the per-day round budget.
    // ---------------------------------------------------------------

    private function league(User $owner, int $roundsPerDay = 3, string $status = 'active'): League
    {
        $league = League::create([
            'name'           => 'Sec League',
            'join_code'      => strtoupper(substr(md5(uniqid()), 0, 6)),
            'owner_user_id'  => $owner->id,
            'sport_id'       => $this->sport()->id,
            'duration_days'  => 7,
            'rounds_per_day' => $roundsPerDay,
            'status'         => $status,
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $owner->id, 'joined_at' => now()]);

        return $league;
    }

    private function round(League $league, int $number): LeagueRound
    {
        return LeagueRound::create([
            'league_id'    => $league->id,
            'challenge_id' => $this->challenge("R{$number}")->id,
            'round_number' => $number,
            'status'       => 'open',
        ]);
    }

    public function test_guessing_is_rejected_after_a_tournament_is_cancelled(): void
    {
        [$user, $token] = $this->auth();
        $league = $this->league($user);
        $round  = $this->round($league, 1);

        $this->withToken($token)->deleteJson("/api/leagues/{$league->id}")->assertSuccessful();
        $this->assertSame('cancelled', $league->fresh()->status);

        $res = $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseCount('guesses', 0);
    }

    public function test_rounds_per_day_is_enforced_on_the_guess_endpoint(): void
    {
        [$user, $token] = $this->auth();
        $league = $this->league($user, roundsPerDay: 1);
        $round1 = $this->round($league, 1);
        $round2 = $this->round($league, 2);

        $this->withToken($token)->postJson("/api/rounds/{$round1->id}/guess", [
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
        ])->assertSuccessful();

        // Skipping current-round and posting straight at the next round id must
        // not bypass the daily budget.
        $res = $this->withToken($token)->postJson("/api/rounds/{$round2->id}/guess", [
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
        ]);

        $res->assertStatus(422);
        $this->assertSame(1, Guess::where('user_id', $user->id)->count());
    }

    public function test_rounds_per_day_still_allows_the_full_daily_allowance(): void
    {
        [$user, $token] = $this->auth();
        $league = $this->league($user, roundsPerDay: 2);

        // Create all rounds up front: with only one round in existence the
        // tournament would auto-complete after the first guess and the second
        // would be rejected as "not active" rather than by the daily budget.
        $rounds = [$this->round($league, 1), $this->round($league, 2), $this->round($league, 3)];

        foreach ([$rounds[0], $rounds[1]] as $round) {
            $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", [
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            ])->assertSuccessful();
        }

        $this->assertSame(2, Guess::where('user_id', $user->id)->count());
    }

    // ---------------------------------------------------------------
    // Packs: replayable, but only the first completion pays XP.
    // ---------------------------------------------------------------

    private function pack(int $challenges = 2): ChallengePack
    {
        $pack = ChallengePack::create([
            'name'       => 'Sec Pack',
            'slug'       => 'sec-pack',
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id'   => $this->sport()->id,
        ]);
        for ($i = 1; $i <= $challenges; $i++) {
            $pack->challenges()->attach($this->challenge("P{$i}")->id, ['sort_order' => $i]);
        }

        return $pack;
    }

    /** Play a pack start-to-finish through the API. */
    private function playPack(string $token, ChallengePack $pack): void
    {
        $start = $this->withToken($token)->postJson("/api/packs/{$pack->slug}/start");
        $start->assertSuccessful();
        $attemptId = $start->json('attempt.id') ?? $start->json('data.attempt.id') ?? $start->json('id');

        foreach ($pack->challenges()->orderBy('sort_order')->pluck('challenges.id') as $challengeId) {
            $this->withToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
                'challenge_id' => $challengeId,
                'guessed_x'    => 0.5,
                'guessed_y'    => 0.5,
            ])->assertSuccessful();
        }
    }

    public function test_replaying_a_completed_pack_awards_no_further_xp(): void
    {
        [$user, $token] = $this->auth();
        $pack = $this->pack();

        $this->playPack($token, $pack);
        $xpAfterFirstRun = XpEvent::where('user_id', $user->id)->sum('amount');
        $this->assertGreaterThan(0, $xpAfterFirstRun, 'first playthrough should pay XP');

        // Replay the whole pack now that every ball position is known.
        $this->playPack($token, $pack);

        $this->assertSame(
            (int) $xpAfterFirstRun,
            (int) XpEvent::where('user_id', $user->id)->sum('amount'),
            'a pack replay must not award XP again'
        );
    }

    // ---------------------------------------------------------------
    // Push token registration.
    // ---------------------------------------------------------------

    public function test_push_token_must_look_like_an_expo_token(): void
    {
        [, $token] = $this->auth();

        $this->withToken($token)->postJson('/api/me/push-tokens', [
            'token' => str_repeat('a', 200),
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertDatabaseCount('push_tokens', 0);
    }

    public function test_push_token_registration_is_capped_per_user(): void
    {
        [$user, $token] = $this->auth();

        for ($i = 0; $i < 10; $i++) {
            PushToken::create([
                'user_id'      => $user->id,
                'token'        => "ExponentPushToken[seed{$i}]",
                'platform'     => 'ios',
                'last_seen_at' => now(),
            ]);
        }

        $this->withToken($token)->postJson('/api/me/push-tokens', [
            'token'    => 'ExponentPushToken[overflow]',
            'platform' => 'ios',
        ])->assertStatus(422);

        $this->assertSame(10, PushToken::where('user_id', $user->id)->count());
    }

    public function test_reregistering_an_existing_token_still_works_at_the_cap(): void
    {
        [$user, $token] = $this->auth();

        for ($i = 0; $i < 10; $i++) {
            PushToken::create([
                'user_id'      => $user->id,
                'token'        => "ExponentPushToken[seed{$i}]",
                'platform'     => 'ios',
                'last_seen_at' => now(),
            ]);
        }

        // A device refreshing its own registration must not be locked out.
        $this->withToken($token)->postJson('/api/me/push-tokens', [
            'token'    => 'ExponentPushToken[seed3]',
            'platform' => 'ios',
        ])->assertStatus(201);
    }

    // ---------------------------------------------------------------
    // Friend requests: a rejection must stick.
    // ---------------------------------------------------------------

    public function test_a_rejected_friend_request_cannot_be_immediately_resent(): void
    {
        [$sender, $senderToken] = $this->auth();
        [$target] = $this->auth();
        $sender->markEmailAsVerified();
        $target->markEmailAsVerified();
        $targetToken = $target->createToken('t')->plainTextToken;

        $created = $this->withToken($senderToken)->postJson('/api/friends/requests', [
            'friend_code' => $target->friend_code,
        ])->assertCreated();

        $requestId = $created->json('data.id');
        $this->actingWithToken($targetToken)
            ->postJson("/api/friends/requests/{$requestId}/reject")
            ->assertSuccessful();

        // Harassment vector: re-sending straight after a rejection.
        $this->actingWithToken($senderToken)->postJson('/api/friends/requests', [
            'friend_code' => $target->friend_code,
        ])->assertStatus(422);
    }

    public function test_a_rejected_friend_request_can_be_resent_after_the_cooldown(): void
    {
        [$sender, $senderToken] = $this->auth();
        [$target] = $this->auth();
        $sender->markEmailAsVerified();
        $target->markEmailAsVerified();
        $targetToken = $target->createToken('t')->plainTextToken;

        $created = $this->withToken($senderToken)->postJson('/api/friends/requests', [
            'friend_code' => $target->friend_code,
        ])->assertCreated();

        $this->actingWithToken($targetToken)
            ->postJson("/api/friends/requests/{$created->json('data.id')}/reject")
            ->assertSuccessful();

        $this->travel(31)->days();

        $this->actingWithToken($senderToken)->postJson('/api/friends/requests', [
            'friend_code' => $target->friend_code,
        ])->assertCreated();
    }

    // ---------------------------------------------------------------
    // Account deletion / password reset must not leave privilege behind.
    // ---------------------------------------------------------------

    public function test_deleting_an_admin_account_revokes_admin_and_verification(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_admin, 'a deleted account must not stay admin');
        $this->assertNull($fresh->email_verified_at);
        $this->assertNull($fresh->friend_code);
    }

    public function test_account_deletion_purges_web_sessions(): void
    {
        [$user, $token] = $this->auth();

        DB::table('sessions')->insert([
            'id'            => 'sess-delete',
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'phpunit',
            'payload'       => 'x',
            'last_activity' => time(),
        ]);

        $this->withToken($token)->deleteJson('/api/account')->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-delete']);
    }

    public function test_password_reset_purges_web_sessions(): void
    {
        $user = User::factory()->create(['email' => 'reset-sessions@example.com']);
        $rawToken = \Illuminate\Support\Facades\Password::createToken($user);

        DB::table('sessions')->insert([
            'id'            => 'sess-reset',
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'phpunit',
            'payload'       => 'x',
            'last_activity' => time(),
        ]);

        $this->postJson('/api/reset-password', [
            'email'                 => $user->email,
            'token'                 => $rawToken,
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-reset']);
    }

    // ---------------------------------------------------------------
    // Admin web login hardening.
    // ---------------------------------------------------------------

    public function test_admin_web_login_does_not_set_a_remember_cookie(): void
    {
        $admin = User::factory()->create([
            'is_admin'          => true,
            'email_verified_at' => now(),
            'password'          => bcrypt('secret-password'),
        ]);

        $res = $this->post('/admin/login', [
            'email'    => $admin->email,
            'password' => 'secret-password',
        ]);

        $res->assertRedirect('/admin/challenges');
        foreach ($res->headers->getCookies() as $cookie) {
            $this->assertStringNotContainsString(
                'remember_web',
                $cookie->getName(),
                'admin login must not mint a ~400-day remember cookie'
            );
        }
    }

    public function test_admin_web_login_rejects_an_unverified_admin(): void
    {
        $admin = User::factory()->create([
            'is_admin'          => true,
            'email_verified_at' => null,
            'password'          => bcrypt('secret-password'),
        ]);

        $this->post('/admin/login', [
            'email'    => $admin->email,
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_admin_challenges_show_route_is_not_registered(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $challenge = $this->challenge();

        // Previously a 500 (BadMethodCallException) because the controller has
        // no show() method. Now 405: the URI still matches the PUT/DELETE
        // resource routes, but GET is no longer routed to a missing method.
        $this->actingAs($admin)->get("/admin/challenges/{$challenge->id}")->assertStatus(405);
    }
}
