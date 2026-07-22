<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Services\SportReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function sport(string $slug, string $status = Sport::STATUS_COMING_SOON): Sport
    {
        return Sport::create([
            'name' => ucfirst($slug), 'slug' => $slug, 'emoji' => '🎾',
            'object_name' => 'ball', 'primary_color' => '#cddc39', 'status' => $status,
        ]);
    }

    private function readyChallenge(Sport $sport, string $title): Challenge
    {
        return Challenge::create([
            'sport_id' => $sport->id, 'title' => $title, 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'challenges/hidden/' . $title . '.jpg',
        ]);
    }

    public function test_readiness_counts_only_ready_challenges(): void
    {
        $tennis = $this->sport('tennis');
        $this->readyChallenge($tennis, 'a');
        $this->readyChallenge($tennis, 'b');
        // Not ready: draft status.
        Challenge::create(['sport_id' => $tennis->id, 'title' => 'draft', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'draft', 'hidden_image_path' => 'x.jpg']);
        // Not ready: missing image.
        Challenge::create(['sport_id' => $tennis->id, 'title' => 'noimg', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => '']);

        $r = app(SportReadinessService::class)->for($tennis);

        $this->assertSame(2, $r['ready_challenges']);
        $this->assertSame(0, $r['scheduled_dailies']);
        $this->assertFalse($r['is_ready']); // below the 5-challenge / 1-daily threshold
    }

    public function test_readiness_true_when_thresholds_met(): void
    {
        $tennis = $this->sport('tennis');
        for ($i = 0; $i < 5; $i++) {
            $this->readyChallenge($tennis, "c{$i}");
        }
        $c = $this->readyChallenge($tennis, 'daily');
        DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);

        $r = app(SportReadinessService::class)->for($tennis);

        $this->assertGreaterThanOrEqual(5, $r['ready_challenges']);
        $this->assertSame(1, $r['scheduled_dailies']);
        $this->assertTrue($r['is_ready']);
    }

    public function test_schedule_warns_and_skips_for_coming_soon_sport(): void
    {
        $tennis = $this->sport('tennis'); // coming_soon
        $this->readyChallenge($tennis, 'a');

        $this->artisan('ballspot:schedule-daily-challenges', ['--sport' => 'tennis', '--days' => 1])
            ->expectsOutputToContain('not active')
            ->assertSuccessful();

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    public function test_schedule_allows_coming_soon_with_flag(): void
    {
        $tennis = $this->sport('tennis');
        $this->readyChallenge($tennis, 'a');

        $this->artisan('ballspot:schedule-daily-challenges', ['--sport' => 'tennis', '--days' => 1, '--allow-coming-soon' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('daily_challenges', 1);
    }
}
