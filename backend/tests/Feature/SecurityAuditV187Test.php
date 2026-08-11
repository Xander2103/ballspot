<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\PushToken;
use App\Models\Sport;
use App\Models\User;
use App\Services\DailyStreakService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the v1.8.7 launch security audit. Each test pins a
 * specific fixed vulnerability so it cannot silently regress:
 *   - solo-tournament XP farm (see TournamentCompletionTest)
 *   - admin announcement double-blast
 *   - anonymized user still receiving pushes
 *   - avatar_path mass assignment
 *   - cross-user pack-attempt guessing
 *   - EXIF/GPS metadata surviving avatar upload
 *   - best-streak stuck at 1 under Carbon 3
 *   - unthrottled friend-read endpoints
 */
class SecurityAuditV187Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function auth(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function readyChallenge(string $title): Challenge
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

    // --- Push F1: admin announcement must never re-blast once sent -----------

    public function test_already_sent_announcement_is_not_resent(): void
    {
        Http::fake(['*' => Http::response(['data' => [['status' => 'ok']]], 200)]);

        $user = User::factory()->create();
        PushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[a]']);

        $notification = AdminNotification::create([
            'title'       => 'Ping',
            'body'        => 'Body',
            'target_type' => AdminNotification::TARGET_ALL,
            'status'      => AdminNotification::STATUS_DRAFT,
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.notifications.send', $notification));
        // Second send (double-click / replayed POST) must be a no-op.
        $this->actingAs($admin)->post(route('admin.notifications.send', $notification->fresh()));

        Http::assertSentCount(1);
    }

    // --- Push F3: anonymized (deleted) users never receive pushes ------------

    public function test_anonymized_user_is_excluded_from_announcements(): void
    {
        Http::fake(['*' => Http::response(['data' => [['status' => 'ok']]], 200)]);

        $ghost = User::factory()->create(['anonymized_at' => now()]);
        $ghost->notificationSettings()->update(['admin_notifications_enabled' => true]);
        PushToken::create(['user_id' => $ghost->id, 'token' => 'ExponentPushToken[ghost]']);

        $notification = AdminNotification::create([
            'title'       => 'Ping',
            'body'        => 'Body',
            'target_type' => AdminNotification::TARGET_ALL,
            'status'      => AdminNotification::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin())->post(route('admin.notifications.send', $notification));

        $this->assertSame(0, $notification->fresh()->metadata['recipients']);
        Http::assertNothingSent();
    }

    // --- A3: avatar_path must not be mass-assignable -------------------------

    public function test_avatar_path_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        // Direct fill() with a hostile payload must be ignored.
        $user->fill(['avatar_path' => 'avatars/someone-elses-file.jpg']);
        $this->assertNull($user->avatar_path);

        // Register endpoint must likewise ignore an injected avatar_path.
        $res = $this->postJson('/api/register', [
            'name'           => 'Mallory',
            'username'       => 'mallory1',
            'email'          => 'mallory@example.com',
            'password'       => 'password123',
            'terms_accepted' => true,
            'age_confirmed'  => true,
            'avatar_path'    => 'avatars/victim.jpg',
        ]);
        $res->assertStatus(201);
        $this->assertNull(User::where('email', 'mallory@example.com')->first()->avatar_path);
    }

    // --- Pack F2: cannot guess on another user's attempt --------------------

    public function test_user_cannot_guess_on_another_users_pack_attempt(): void
    {
        $pack = ChallengePack::create([
            'name'       => 'Play Pack',
            'slug'       => 'play-pack',
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id'   => $this->sport()->id,
        ]);
        $pack->challenges()->attach($this->readyChallenge('C1')->id, ['sort_order' => 1]);
        $pack->challenges()->attach($this->readyChallenge('C2')->id, ['sort_order' => 2]);

        [$alice, $aliceToken] = $this->auth();
        [$bob, $bobToken]     = $this->auth();

        $start = $this->actingWithToken($aliceToken)->postJson("/api/packs/{$pack->slug}/start");
        $start->assertOk();
        $attemptId = $start->json('attempt.id');
        $this->assertNotNull($attemptId);

        // Bob attempts to guess on Alice's attempt — rejected at the boundary.
        $res = $this->actingWithToken($bobToken)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $pack->challenges()->first()->id,
            'guessed_x'    => 0.5,
            'guessed_y'    => 0.5,
        ]);

        $res->assertStatus(403);
    }

    // --- A1: EXIF/GPS metadata is stripped from uploaded avatars -------------

    public function test_avatar_upload_strips_embedded_metadata(): void
    {
        Storage::fake('public');
        [$user, $token] = $this->auth();

        // Build a real JPEG and append a recognizable metadata marker (stands in
        // for an EXIF GPS segment). A faithful re-encode drops everything that is
        // not pixel data, so the marker must be absent from the stored file.
        $gd = imagecreatetruecolor(48, 48);
        ob_start();
        imagejpeg($gd);
        $jpeg = ob_get_clean();
        imagedestroy($gd);

        $marker = 'GPS-HOME-LAT-52.5200-LON-13.4050';
        $tmp = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($tmp, $jpeg . $marker);

        $upload = new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true);

        $res = $this->actingWithToken($token)->post('/api/me/avatar', ['avatar' => $upload]);
        $res->assertOk();

        $path  = $user->fresh()->avatar_path;
        $bytes = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString($marker, $bytes);
    }

    // --- F4: best streak counts consecutive days (Carbon 3 regression) ------

    public function test_best_streak_counts_consecutive_days(): void
    {
        $user = User::factory()->create();
        $challenge = $this->readyChallenge('Daily');

        // Three consecutive days, all before yesterday (so current streak is 0
        // but best streak must be 3). Pre-fix this returned 1 under Carbon 3.
        foreach ([5, 4, 3] as $daysAgo) {
            $daily = DailyChallenge::create([
                'challenge_id'   => $challenge->id,
                'challenge_date' => today()->subDays($daysAgo)->toDateString(),
                'status'         => 'active',
            ]);
            DailyChallengeGuess::create([
                'daily_challenge_id' => $daily->id,
                'user_id'            => $user->id,
                'guess_x_ratio'      => 0.5,
                'guess_y_ratio'      => 0.5,
                'distance'           => 0.1,
                'score'              => 50,
                'submitted_at'       => today()->subDays($daysAgo),
            ]);
        }

        $streak = app(DailyStreakService::class)->getStreakForUser($user);

        $this->assertSame(3, $streak['best']);
        $this->assertSame(0, $streak['current']);
    }

    // --- HIGH-1 mitigation: friend-read endpoints are throttled --------------

    public function test_friends_read_endpoint_is_throttled(): void
    {
        [, $token] = $this->auth();

        // Limiter is 40/min; the 41st request in the window must be blocked.
        for ($i = 0; $i < 40; $i++) {
            $this->actingWithToken($token)->getJson('/api/friends')->assertOk();
        }

        $this->actingWithToken($token)->getJson('/api/friends')->assertStatus(429);
    }
}
