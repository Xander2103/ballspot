<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueRound;
use App\Models\PackAttempt;
use App\Models\PackAttemptGuess;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A guess stores only the player's position and score. The image, ball position
 * and sport it was played against live on the challenge, so they freeze at the
 * first guess; otherwise old results and prize rankings could be falsified.
 */
class PlayedChallengeLockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $player;
    private Sport $sport;
    private Challenge $challenge;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->player = User::factory()->create();
        $this->sport = Sport::create(['name' => 'Football', 'slug' => 'football', 'emoji' => '⚽', 'primary_color' => '#00c853', 'status' => 'active']);
        $this->challenge = $this->makeChallenge('Original title');
    }

    private function makeChallenge(string $title): Challenge
    {
        Storage::disk('public')->put("challenges/hidden/$title.jpg", 'hidden');
        Storage::disk('public')->put("challenges/original/$title.jpg", 'reveal');

        return Challenge::create(['sport_id' => $this->sport->id, 'title' => $title, 'difficulty' => 'easy', 'status' => 'active',
            'hidden_image_path' => "challenges/hidden/$title.jpg", 'original_image_path' => "challenges/original/$title.jpg",
            'ball_x_ratio' => 0.515234, 'ball_y_ratio' => 0.487612]);
    }

    private function playDaily(Challenge $challenge, string $date = '2026-10-10', int $score = 87): DailyChallenge
    {
        $daily = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => $date, 'status' => 'active']);
        DailyChallengeGuess::create(['daily_challenge_id' => $daily->id, 'user_id' => $this->player->id, 'guess_x_ratio' => 0.4, 'guess_y_ratio' => 0.4,
            'distance' => 0.1, 'score' => $score, 'submitted_at' => $date . ' 10:00:00']);

        return $daily;
    }

    private function form(array $overrides = []): array
    {
        return array_merge(['title' => 'Original title', 'difficulty' => 'easy', 'status' => 'active'], $overrides);
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->admin)->patch("/admin/challenges/{$this->challenge->id}", $this->form($overrides));
    }

    private function assertFrozen(): void
    {
        $fresh = $this->challenge->fresh();
        $this->assertSame(0.515234, $fresh->ball_x_ratio);
        $this->assertSame(0.487612, $fresh->ball_y_ratio);
        $this->assertSame('challenges/hidden/Original title.jpg', $fresh->hidden_image_path);
        $this->assertSame('challenges/original/Original title.jpg', $fresh->original_image_path);
        $this->assertSame($this->sport->id, $fresh->sport_id);
        Storage::disk('public')->assertExists(['challenges/hidden/Original title.jpg', 'challenges/original/Original title.jpg']);
    }

    public function test_an_unplayed_challenge_stays_fully_editable(): void
    {
        $other = Sport::create(['name' => 'Golf', 'slug' => 'golf', 'emoji' => '⛳', 'primary_color' => '#00c853', 'status' => 'active']);
        // Scheduled for a later day but nobody has guessed yet: still correctable.
        DailyChallenge::create(['challenge_id' => $this->challenge->id, 'challenge_date' => '2026-12-01', 'status' => 'scheduled']);

        $this->save(['ball_x_ratio' => 0.25, 'ball_y_ratio' => 0.75, 'sport_id' => $other->id,
            'hidden_image' => UploadedFile::fake()->image('new.jpg')])->assertRedirect('/admin/challenges');

        $fresh = $this->challenge->fresh();
        $this->assertSame(0.25, $fresh->ball_x_ratio);
        $this->assertSame($other->id, $fresh->sport_id);
        $this->assertNotSame('challenges/hidden/Original title.jpg', $fresh->hidden_image_path);
        Storage::disk('public')->assertMissing('challenges/hidden/Original title.jpg');
    }

    public function test_an_unplayed_challenge_still_requires_a_ball_position(): void
    {
        $this->save()->assertSessionHasErrors(['ball_x_ratio', 'ball_y_ratio']);
    }

    public function test_moving_the_ball_of_a_played_challenge_is_refused(): void
    {
        $this->playDaily($this->challenge);

        $this->save(['title' => 'Sneaky', 'ball_x_ratio' => 0.9, 'ball_y_ratio' => 0.1])
            ->assertSessionHasErrors(['ball_x_ratio', 'ball_y_ratio']);

        $this->assertFrozen();
        $this->assertSame('Original title', $this->challenge->fresh()->title, 'nothing at all was saved');
    }

    public function test_replacing_or_adding_images_of_a_played_challenge_is_refused(): void
    {
        $this->playDaily($this->challenge);

        $this->save(['hidden_image' => UploadedFile::fake()->image('a.jpg')])->assertSessionHasErrors('hidden_image');
        $this->save(['original_image' => UploadedFile::fake()->image('b.jpg')])->assertSessionHasErrors('original_image');

        $this->assertFrozen();
        $this->assertCount(1, Storage::disk('public')->files('challenges/hidden'));
    }

    public function test_changing_the_sport_of_a_played_challenge_is_refused(): void
    {
        $this->playDaily($this->challenge);
        $other = Sport::create(['name' => 'Golf', 'slug' => 'golf', 'emoji' => '⛳', 'primary_color' => '#00c853', 'status' => 'active']);

        $this->save(['sport_id' => $other->id])->assertSessionHasErrors('sport_id');

        $this->assertFrozen();
    }

    public function test_display_fields_stay_editable_without_moving_the_stored_ball(): void
    {
        $this->playDaily($this->challenge);
        $category = ChallengeCategory::create(['sport_id' => $this->sport->id, 'name' => 'Headers', 'slug' => 'headers']);

        // Rounding to the 3 decimals of the form used to move the ball on any save.
        $this->save(['title' => 'Fixed typo', 'difficulty' => 'hard', 'status' => 'archived', 'challenge_category_id' => $category->id])
            ->assertRedirect('/admin/challenges')->assertSessionHasNoErrors();

        $this->assertFrozen();
        $fresh = $this->challenge->fresh();
        $this->assertSame('Fixed typo', $fresh->title);
        $this->assertSame('hard', $fresh->difficulty);
        $this->assertSame('archived', $fresh->status);
        $this->assertSame($category->id, $fresh->challenge_category_id);
    }

    public function test_a_stale_form_with_the_unchanged_rounded_ball_can_still_save(): void
    {
        $this->playDaily($this->challenge);

        $this->save(['title' => 'From an old tab', 'ball_x_ratio' => 0.515, 'ball_y_ratio' => 0.488, 'sport_id' => $this->sport->id])
            ->assertSessionHasNoErrors();

        $this->assertFrozen();
        $this->assertSame('From an old tab', $this->challenge->fresh()->title);
    }

    public function test_tournament_and_pack_guesses_lock_too(): void
    {
        $league = League::create(['name' => 'L', 'join_code' => 'LOCK01', 'owner_user_id' => $this->player->id, 'sport_id' => $this->sport->id,
            'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $this->challenge->id, 'round_number' => 1, 'status' => 'open']);
        $this->assertFalse($this->challenge->hasBeenPlayed(), 'a drawn round without a guess is not played');

        Guess::create(['league_round_id' => $round->id, 'user_id' => $this->player->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.1, 'score' => 50, 'submitted_at' => now()]);
        $this->assertTrue($this->challenge->hasBeenPlayed());

        $packed = $this->makeChallenge('Pack photo');
        $this->assertFalse($packed->hasBeenPlayed());
        PackAttemptGuess::unguard();
        $pack = \App\Models\ChallengePack::create(['name' => 'P', 'slug' => 'p', 'status' => 'active']);
        $attempt = PackAttempt::create(['user_id' => $this->player->id, 'challenge_pack_id' => $pack->id, 'status' => PackAttempt::STATUS_COMPLETED, 'started_at' => now()]);
        PackAttemptGuess::create(['pack_attempt_id' => $attempt->id, 'challenge_id' => $packed->id, 'score' => 10, 'guessed_x' => 0.1, 'guessed_y' => 0.1, 'distance' => 0.1]);
        PackAttemptGuess::reguard();
        $this->assertTrue($packed->hasBeenPlayed());
    }

    public function test_the_model_refuses_any_other_writer(): void
    {
        $this->playDaily($this->challenge);

        foreach ([['ball_x_ratio' => 0.1], ['hidden_image_path' => 'x.jpg'], ['original_image_path' => null], ['sport_id' => 999]] as $change) {
            try {
                $this->challenge->fresh()->update($change);
                $this->fail('Expected a refusal for ' . key($change));
            } catch (\LogicException) {
                $this->assertFrozen();
            }
        }

        $this->challenge->fresh()->update(['status' => 'archived', 'title' => 'Allowed']);
        $this->assertSame('archived', $this->challenge->fresh()->status);
    }

    public function test_a_played_daily_cannot_be_repointed_or_redated(): void
    {
        $daily = $this->playDaily($this->challenge);
        $other = $this->makeChallenge('Other');

        foreach ([['challenge_id' => $other->id], ['challenge_date' => '2026-01-01']] as $change) {
            try {
                $daily->fresh()->update($change);
                $this->fail('Expected a refusal');
            } catch (\LogicException) {
                $this->assertSame($this->challenge->id, $daily->fresh()->challenge_id);
                $this->assertSame('2026-10-10', $daily->fresh()->challenge_date->toDateString());
            }
        }

        $daily->fresh()->update(['status' => 'archived']);
        $this->assertSame('archived', $daily->fresh()->status);
    }

    public function test_schedule_force_skips_played_dailies_and_replaces_only_unplayed_ones(): void
    {
        $this->challenge->update(['usage_pool' => 'daily']);
        $played = $this->playDaily($this->challenge, '2026-10-10');
        $spare = $this->makeChallenge('Spare one');
        $spareTwo = $this->makeChallenge('Spare two');
        $unplayed = DailyChallenge::create(['challenge_id' => $spare->id, 'challenge_date' => '2026-10-11', 'status' => 'scheduled']);

        Artisan::call('ballspot:schedule-daily-challenges', ['--start' => '2026-10-10', '--days' => 2, '--force' => true]);

        $this->assertSame($this->challenge->id, $played->fresh()->challenge_id);
        $this->assertSame(1, $played->guesses()->count());
        $this->assertStringContainsString('already played', Artisan::output());
        $this->assertNotSame($spare->id, $unplayed->fresh()->challenge_id, 'the unplayed one was replaced');
        $this->assertSame($spareTwo->id, $unplayed->fresh()->challenge_id);
    }

    public function test_a_refused_edit_leaves_the_historic_result_and_ranking_identical(): void
    {
        $daily = $this->playDaily($this->challenge, now()->startOfMonth()->toDateString());
        $read = fn () => [
            $this->actingAs($this->player, 'sanctum')->getJson("/api/daily/{$daily->id}/result")->assertOk()->json('data'),
            $this->actingAs($this->player, 'sanctum')->getJson('/api/daily/leaderboard/weekly')->assertOk()->json('data'),
        ];
        $before = $read();

        $this->save(['ball_x_ratio' => 0.9, 'ball_y_ratio' => 0.9, 'hidden_image' => UploadedFile::fake()->image('c.jpg')]);
        auth()->forgetGuards();

        $this->assertEquals($before, $read());
        $this->assertSame(0.515234, $before[0]['ball_x_ratio'], 'API contract unchanged');
    }

    public function test_the_form_shows_the_lock_only_when_played(): void
    {
        $edit = fn () => $this->actingAs($this->admin)->get("/admin/challenges/{$this->challenge->id}/edit")->assertOk();

        $edit()->assertDontSee('data-challenge-locked', false)->assertSee('name="hidden_image"', false);

        $this->playDaily($this->challenge);

        $res = $edit()->assertSee('data-challenge-locked', false)->assertDontSee('name="hidden_image"', false)->assertDontSee('name="original_image"', false);
        $this->assertMatchesRegularExpression('/name="ball_x_ratio"[^>]*disabled/s', $res->getContent());
        $this->assertMatchesRegularExpression('/name="sport_id"[^>]*disabled/s', $res->getContent());
    }

    public function test_clearing_daily_history_is_refused_on_production(): void
    {
        $this->playDaily($this->challenge);
        $this->app->detectEnvironment(fn () => 'production');

        // CSRF is only waived for the "testing" environment, which this test leaves.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->actingAs($this->admin)->post(route('admin.diagnostics.clear-daily-history'), [
                'pin' => \App\Services\DailyHistoryClearService::CONFIRMATION_PIN, 'acknowledge' => '1',
            ])->assertSessionHas('error', fn ($m) => str_contains($m, 'disabled on production'));
        $this->assertSame(1, DailyChallengeGuess::count());

        $this->assertSame(1, Artisan::call('ballspot:reset-test-daily-history', ['--force' => true, '--confirm-prelaunch' => true]));
        $this->assertSame(1, DailyChallengeGuess::count());
        $this->assertSame(1, DailyChallenge::count());

        // The dry run stays available: it deletes nothing.
        $this->assertSame(0, Artisan::call('ballspot:reset-test-daily-history'));

        $this->actingAs($this->admin)->get(route('admin.diagnostics.index'))->assertOk()
            ->assertSee('data-daily-clear-disabled', false);
    }

    public function test_only_an_explicit_setting_reopens_the_clear_on_production(): void
    {
        $this->playDaily($this->challenge);
        $this->app->detectEnvironment(fn () => 'production');
        config(['ballspot.allow_daily_history_clear' => true]);

        $this->assertSame(0, Artisan::call('ballspot:reset-test-daily-history', ['--force' => true, '--confirm-prelaunch' => true]));
        $this->assertSame(0, DailyChallengeGuess::count());
    }
}
