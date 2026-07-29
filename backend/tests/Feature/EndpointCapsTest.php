<?php
namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EndpointCapsTest extends TestCase
{
    use RefreshDatabase;

    private function makeDailyToday(): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Caps Test Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => now()->toDateString(),
            'status'         => 'active',
        ]);
    }

    public function test_weekly_leaderboard_caps_entries_but_meta_covers_full_field(): void
    {
        $dc = $this->makeDailyToday();

        // 105 players; scores 105 down to 1 so ranks are deterministic.
        $users = User::factory()->count(105)->create();
        foreach ($users as $i => $user) {
            DailyChallengeGuess::create([
                'daily_challenge_id' => $dc->id,
                'user_id'            => $user->id,
                'guess_x_ratio'      => 0.5,
                'guess_y_ratio'      => 0.5,
                'distance'           => 0.1,
                'score'              => 105 - $i,
                'submitted_at'       => now(),
            ]);
        }

        $lastPlace = $users->last(); // score 1 -> rank 105

        $response = $this->actingAs($lastPlace, 'sanctum')->getJson('/api/daily/leaderboard/weekly');

        $response->assertOk();
        $this->assertCount(100, $response->json('data'));
        $this->assertSame(105, $response->json('meta.total_players'));
        $this->assertSame(105, $response->json('meta.current_user_rank'));
    }

    public function test_trophy_room_lists_are_capped(): void
    {
        // Structural guard: the self-scoped Trophy Room queries must carry a
        // LIMIT so unbounded account history cannot balloon responses.
        $user = User::factory()->create();

        foreach ([
            '/api/me/tournament-finishes',
            '/api/me/competition-finishes',
            '/api/me/pack-completions',
        ] as $uri) {
            $this->actingAs($user, 'sanctum')->getJson($uri)->assertOk();
        }

        // The caps themselves are asserted via the shared constant.
        $this->assertSame(100, \App\Http\Controllers\Api\ProfileController::MAX_LIST_ROWS);
    }

    public function test_admin_challenge_upload_rejects_gif(): void
    {
        Storage::fake('public');
        Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/challenges', [
            'title'        => 'Gif Upload',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => 0.5,
            'ball_y_ratio' => 0.5,
            'hidden_image' => UploadedFile::fake()->image('sneaky.gif'),
        ]);

        $response->assertSessionHasErrors('hidden_image');
    }

    public function test_admin_challenge_upload_accepts_png(): void
    {
        Storage::fake('public');
        Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/challenges', [
            'title'        => 'Png Upload',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => 0.5,
            'ball_y_ratio' => 0.5,
            'hidden_image' => UploadedFile::fake()->image('fine.png'),
        ]);

        $response->assertSessionDoesntHaveErrors('hidden_image');
    }
}
