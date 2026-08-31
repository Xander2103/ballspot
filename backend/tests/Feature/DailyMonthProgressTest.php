<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyMonthProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, $token];
    }

    private function createDailyForToday(): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Progress Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => today()->toDateString(),
            'status'         => 'active',
        ]);
    }

    private function assertProgress(string $date, int $expectedIndex, int $expectedTotal): void
    {
        Carbon::setTestNow(Carbon::parse($date));

        [$user, $token] = $this->createUser();
        $this->createDailyForToday();

        $response = $this->withToken($token)->getJson('/api/daily/today');
        $response->assertOk();
        $response->assertJsonPath('has_daily', true);
        $response->assertJsonPath('daily_challenge.daily_month_index', $expectedIndex);
        $response->assertJsonPath('daily_challenge.daily_month_total', $expectedTotal);
    }

    public function test_progress_in_31_day_month(): void
    {
        $this->assertProgress('2026-08-04', 4, 31);
    }

    public function test_progress_in_30_day_month(): void
    {
        $this->assertProgress('2026-04-01', 1, 30);
    }

    public function test_progress_in_february_non_leap_year(): void
    {
        $this->assertProgress('2026-02-28', 28, 28);
    }

    public function test_progress_in_february_leap_year(): void
    {
        $this->assertProgress('2028-02-01', 1, 29);
    }
}
