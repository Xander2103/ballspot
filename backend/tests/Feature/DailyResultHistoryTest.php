<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DailyChallengeController;
use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A daily result must describe the challenge that was played. The app used to
 * take the title, category and fallback image from /daily/today, so a result
 * opened after the next daily went live showed the wrong challenge.
 */
class DailyResultHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Sport $sport;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0, config('app.timezone')));
        config(['ballspot.competition.period' => 'monthly', 'ballspot.competition.label' => null]);

        $this->user  = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
        $this->sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function category(string $name): ChallengeCategory
    {
        return ChallengeCategory::create([
            'sport_id' => $this->sport->id,
            'name'     => $name,
            'slug'     => strtolower(str_replace(' ', '-', $name)),
        ]);
    }

    private function daily(string $date, string $title, ?ChallengeCategory $category, array $challenge = []): DailyChallenge
    {
        $model = Challenge::create($challenge + [
            'sport_id'              => $this->sport->id,
            'challenge_category_id' => $category?->id,
            'title'                 => $title,
            'ball_x_ratio'          => 0.25,
            'ball_y_ratio'          => 0.75,
            'difficulty'            => 'medium',
            'status'                => 'active',
            'hidden_image_path'     => "challenges/hidden/{$title}.jpg",
            'original_image_path'   => "challenges/original/{$title}.jpg",
        ]);

        return DailyChallenge::create([
            'challenge_id'   => $model->id,
            'challenge_date' => $date,
            'status'         => 'active',
        ]);
    }

    private function guess(DailyChallenge $daily, int $score = 87): DailyChallengeGuess
    {
        return DailyChallengeGuess::create([
            'daily_challenge_id' => $daily->id,
            'user_id'            => $this->user->id,
            'guess_x_ratio'      => 0.43,
            'guess_y_ratio'      => 0.72,
            'score'              => $score,
            'distance'           => 0.052,
            'submitted_at'       => Carbon::parse($daily->challenge_date)->setTime(12, 0),
        ]);
    }

    private function openResult(DailyChallenge $daily)
    {
        return $this->withToken($this->token)->getJson("/api/daily/{$daily->id}/result");
    }

    private function assertShowsChallenge($response, DailyChallenge $daily, string $title, ?string $category): void
    {
        $response->assertOk()
            ->assertJsonPath('data.challenge_date', $daily->challenge_date->toDateString())
            ->assertJsonPath('data.challenge.id', $daily->challenge_id)
            ->assertJsonPath('data.challenge.title', $title)
            ->assertJsonPath('data.challenge.category.name', $category);

        $this->assertStringEndsWith("challenges/hidden/{$title}.jpg", $response->json('data.challenge.hidden_image_url'));
        $this->assertStringEndsWith("challenges/original/{$title}.jpg", $response->json('data.reveal_image_url'));
    }

    public function test_todays_result_shows_todays_challenge(): void
    {
        $today = $this->daily('2026-07-15', 'Today', $this->category('Headers'));
        $this->guess($today);

        $this->assertShowsChallenge($this->openResult($today), $today, 'Today', 'Headers');
    }

    public function test_yesterdays_result_shows_its_own_challenge_not_todays(): void
    {
        $yesterday = $this->daily('2026-07-14', 'Yesterday', $this->category('Headers'));
        $this->daily('2026-07-15', 'Today', $this->category('Penalties'));
        $this->guess($yesterday);

        $this->assertShowsChallenge($this->openResult($yesterday), $yesterday, 'Yesterday', 'Headers');
    }

    public function test_previous_month_result_stays_correct_outside_the_current_competition(): void
    {
        $june = $this->daily('2026-06-10', 'June', $this->category('Dribbles'));
        $this->daily('2026-07-15', 'Today', $this->category('Penalties'));
        $this->guess($june, 64);

        $response = $this->openResult($june);

        $this->assertShowsChallenge($response, $june, 'June', 'Dribbles');
        $response->assertJsonPath('data.score', 64)->assertJsonPath('data.rank', 1);

        // It belongs to a closed period: this month's board must not count it.
        $this->withToken($this->token)->getJson('/api/daily/leaderboard/weekly')
            ->assertOk()
            ->assertJsonPath('period.period_start', '2026-07-01')
            ->assertJsonCount(0, 'data');
    }

    public function test_results_keep_their_own_category(): void
    {
        $headers = $this->daily('2026-07-13', 'First', $this->category('Headers'));
        $corners = $this->daily('2026-07-14', 'Second', $this->category('Corner Kicks'));
        $this->guess($headers);
        $this->guess($corners);

        $this->assertShowsChallenge($this->openResult($headers), $headers, 'First', 'Headers');
        $this->assertShowsChallenge($this->openResult($corners), $corners, 'Second', 'Corner Kicks');
    }

    public function test_a_new_daily_does_not_change_an_open_result(): void
    {
        $old = $this->daily('2026-07-14', 'Old', $this->category('Headers'));
        $guess = $this->guess($old);

        $rival = User::factory()->create();
        DailyChallengeGuess::create([
            'daily_challenge_id' => $old->id, 'user_id' => $rival->id,
            'guess_x_ratio' => 0.1, 'guess_y_ratio' => 0.1,
            'score' => 95, 'distance' => 0.01, 'submitted_at' => Carbon::parse('2026-07-14 13:00'),
        ]);
        $storedGuess = $guess->fresh()->getAttributes();

        $before = $this->openResult($old)->assertOk()->json('data');

        $new = $this->daily('2026-07-15', 'New', $this->category('Penalties'));
        $this->withToken($this->token)->getJson('/api/daily/today')
            ->assertJsonPath('daily_challenge.id', $new->id);

        $after = $this->openResult($old)->assertOk()->json('data');

        $this->assertSame($before, $after);
        $this->assertSame($storedGuess, $guess->fresh()->getAttributes());

        $this->assertSame($guess->id, $after['id']);
        $this->assertSame(87, $after['score']);
        $this->assertEqualsWithDelta(0.052, $after['distance'], 1e-9);
        $this->assertEqualsWithDelta(0.43, $after['guess_x_ratio'], 1e-9);
        $this->assertEqualsWithDelta(0.72, $after['guess_y_ratio'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $after['ball_x_ratio'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $after['ball_y_ratio'], 1e-9);
        $this->assertSame(2, $after['rank']);
        $this->assertSame(2, $after['total_players']);
    }

    public function test_the_guess_response_names_the_same_challenge_as_the_result(): void
    {
        $today = $this->daily('2026-07-15', 'Today', $this->category('Headers'));

        $guess = $this->withToken($this->token)->postJson("/api/daily/{$today->id}/guess", [
            'guess_x_ratio' => 0.3,
            'guess_y_ratio' => 0.7,
        ])->assertOk();

        $this->assertSame($this->openResult($today)->json('data.challenge'), $guess->json('data.challenge'));
        $this->assertSame('Today', $guess->json('data.challenge.title'));
    }

    public function test_every_language_gets_the_same_challenge(): void
    {
        $old = $this->daily('2026-07-14', 'Old', $this->category('Headers'));
        $this->guess($old);

        foreach (['nl', 'en', 'fr', 'de', 'es'] as $locale) {
            $response = $this->withToken($this->token)
                ->withHeader('Accept-Language', $locale)
                ->getJson("/api/daily/{$old->id}/result");

            $this->assertShowsChallenge($response, $old, 'Old', 'Headers');
        }
    }

    public function test_archived_challenge_still_shows_its_result(): void
    {
        $old = $this->daily('2026-07-14', 'Archived', $this->category('Headers'));
        $this->guess($old);
        $old->challenge->update(['status' => 'archived']);

        $this->assertShowsChallenge($this->openResult($old), $old, 'Archived', 'Headers');
    }

    public function test_removed_category_leaves_the_rest_of_the_result_intact(): void
    {
        $category = $this->category('Headers');
        $old = $this->daily('2026-07-14', 'Uncategorised', $category);
        $this->guess($old);

        $category->delete();

        $this->assertShowsChallenge($this->openResult($old), $old, 'Uncategorised', null);
        $this->openResult($old)->assertJsonPath('data.challenge.category', null)->assertJsonPath('data.score', 87);
    }

    public function test_challenge_without_images_returns_null_image_urls(): void
    {
        $old = $this->daily('2026-07-14', 'NoImages', null, ['original_image_path' => null]);
        $this->guess($old);

        $this->openResult($old)->assertOk()
            ->assertJsonPath('data.reveal_image_url', null)
            ->assertJsonPath('data.challenge.category', null)
            ->assertJsonPath('data.score', 87);
    }

    public function test_deleted_challenge_gives_a_clean_404(): void
    {
        $old = $this->daily('2026-07-14', 'Deleted', $this->category('Headers'));
        $this->guess($old);

        $old->challenge->delete();

        $this->openResult($old)->assertNotFound();
    }

    public function test_daily_whose_challenge_is_missing_gives_a_404_not_a_500(): void
    {
        $old = $this->daily('2026-07-14', 'Orphan', $this->category('Headers'));
        $this->guess($old);
        $old->setRelation('challenge', null);

        $request = Request::create("/api/daily/{$old->id}/result");
        $request->setUserResolver(fn () => $this->user);

        $response = app(DailyChallengeController::class)->result($request, $old);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_todays_response_still_hides_the_answer(): void
    {
        $this->daily('2026-07-15', 'Today', $this->category('Headers'));

        $challenge = $this->withToken($this->token)->getJson('/api/daily/today')
            ->assertOk()
            ->assertJsonPath('daily_challenge.challenge.title', 'Today')
            ->assertJsonPath('daily_challenge.challenge.category.name', 'Headers')
            ->json('daily_challenge.challenge');

        foreach (['ball_x_ratio', 'ball_y_ratio', 'reveal_image_url', 'original_image_path'] as $secret) {
            $this->assertArrayNotHasKey($secret, $challenge);
        }
        $this->assertArrayHasKey('sport', $challenge);
        $this->assertArrayHasKey('tags', $challenge);
    }
}
