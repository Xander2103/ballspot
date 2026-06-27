<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Sport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreReadinessCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_successfully(): void
    {
        $this->artisan('ballspot:store-readiness-check')
            ->assertExitCode(0);
    }

    public function test_warns_when_no_active_ready_challenges(): void
    {
        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('WARN')
            ->assertExitCode(0);
    }

    public function test_passes_when_enough_active_ready_challenges_exist(): void
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        for ($i = 1; $i <= 7; $i++) {
            Challenge::create([
                'sport_id'          => $sport->id,
                'title'             => "Real Challenge {$i}",
                'ball_x_ratio'      => 0.5,
                'ball_y_ratio'      => 0.5,
                'difficulty'        => 'easy',
                'status'            => 'active',
                'hidden_image_path' => "challenges/hidden/test{$i}.jpg",
            ]);
        }

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('7 active ready challenges available')
            ->assertExitCode(0);
    }

    public function test_warns_about_demo_content_when_present(): void
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Corner Kick', // known demo title
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/demo.jpg',
        ]);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('demo/placeholder')
            ->assertExitCode(0);
    }

    public function test_reports_public_routes_as_passing(): void
    {
        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('/privacy route registered')
            ->expectsOutputToContain('/terms route registered')
            ->expectsOutputToContain('/support route registered')
            ->assertExitCode(0);
    }
}
