<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Storage URLs handed to the mobile app must be absolute and rooted at APP_URL.
 * A relative or localhost URL renders as a broken image on a real device.
 */
class ImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_today_returns_absolute_hidden_image_url(): void
    {
        // asset()/url() root themselves off the current request's scheme+host
        // (Symfony Request::root()) — this app never calls URL::forceRootUrl(),
        // so a plain config(['app.url' => ...]) override has no effect on the
        // resolved URL here. Driving the test request at a fully-qualified URL
        // (instead of a relative path) is what actually exercises "asset() must
        // build an absolute URL rooted at wherever the app is served" — the
        // exact mechanism a misconfigured reverse proxy / APP_URL would break.
        $sport = Sport::create([
            'slug' => 'football', 'name' => 'Football', 'emoji' => '⚽',
            'primary_color' => '#00E676', 'status' => 'active',
        ]);
        $category = ChallengeCategory::create([
            'sport_id' => $sport->id, 'name' => 'Test', 'slug' => 'test',
        ]);
        $challenge = Challenge::create([
            'sport_id' => $sport->id,
            'challenge_category_id' => $category->id,
            'title' => 'Hidden Ball',
            'hidden_image_path' => 'challenges/hidden.jpg',
            'original_image_path' => 'challenges/original.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active',
        ]);
        DailyChallenge::create([
            'challenge_id' => $challenge->id,
            'challenge_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $user = User::factory()->create(['preferred_sport_id' => $sport->id]);
        $token = $user->createToken('test')->plainTextToken;

        $url = $this->withToken($token)
            ->getJson('https://api.example.test/api/daily/today')
            ->assertOk()
            ->json('daily_challenge.challenge.hidden_image_url');

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://api.example.test/storage/', $url);
        $this->assertSame('https://api.example.test/storage/challenges/hidden.jpg', $url);
    }
}
