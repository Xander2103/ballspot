<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Other players only ever see a username. `name` stays in every payload because
 * published apps render `name || username`, so it carries the username too.
 */
class PublicPlayerNameTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_NAME = 'Jan Peeters';

    private User $viewer;
    private User $player;
    private Sport $sport;
    private Challenge $challenge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = User::factory()->create(['username' => 'viewer1', 'name' => 'Vera Viewer', 'email' => 'vera@example.test']);
        $this->player = User::factory()->create(['username' => 'GoalGetter', 'name' => self::REAL_NAME, 'email' => 'jan.peeters@example.test']);
        $this->sport  = Sport::create(['name' => 'Football', 'slug' => 'football', 'emoji' => '⚽', 'primary_color' => '#00c853', 'status' => 'active']);
        $this->challenge = Challenge::create(['sport_id' => $this->sport->id, 'title' => 'T', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function assertNoPrivateData($response, User $user): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString($user->name, $body);
        $this->assertStringNotContainsString($user->email, $body);
    }

    private function league(array $users, bool $withGuesses = true): League
    {
        $league = League::create(['name' => 'Cup', 'join_code' => 'ZZZZZZ', 'owner_user_id' => $users[0]->id,
            'sport_id' => $this->sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        foreach ($users as $user) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        }
        if ($withGuesses) {
            $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $this->challenge->id, 'round_number' => 1, 'status' => 'open']);
            foreach ($users as $i => $user) {
                Guess::create(['league_round_id' => $round->id, 'user_id' => $user->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
                    'distance' => 0.0, 'score' => 90 - $i, 'submitted_at' => now()]);
            }
        }

        return $league;
    }

    private function dailyGuess(User $user, int $score): void
    {
        $daily = DailyChallenge::firstOrCreate(['challenge_date' => now()->toDateString()], ['challenge_id' => $this->challenge->id, 'status' => 'active']);
        DailyChallengeGuess::create(['daily_challenge_id' => $daily->id, 'user_id' => $user->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.0, 'score' => $score, 'submitted_at' => now()]);
    }

    public function test_tournament_leaderboard_including_meta_shows_only_the_username(): void
    {
        $league = $this->league([$this->player, $this->viewer]);

        $res = $this->as($this->viewer)->getJson("/api/leagues/{$league->id}/leaderboard")->assertOk();

        $res->assertJsonPath('data.0.user_id', $this->player->id)
            ->assertJsonPath('data.0.username', 'GoalGetter')
            ->assertJsonPath('data.0.name', 'GoalGetter')
            ->assertJsonPath('meta.top_users.0.name', 'GoalGetter')
            ->assertJsonPath('meta.nearby_users.0.name', 'GoalGetter');
        $this->assertNoPrivateData($res, $this->player);
    }

    public function test_competition_leaderboard_including_meta_shows_only_the_username(): void
    {
        $this->dailyGuess($this->player, 90);
        $this->dailyGuess($this->viewer, 50);

        $res = $this->as($this->viewer)->getJson('/api/daily/leaderboard/weekly')->assertOk();

        $res->assertJsonPath('data.0.user_id', $this->player->id)
            ->assertJsonPath('data.0.username', 'GoalGetter')
            ->assertJsonPath('data.0.name', 'GoalGetter')
            ->assertJsonPath('meta.top_users.0.name', 'GoalGetter');
        $this->assertNoPrivateData($res, $this->player);
    }

    public function test_daily_challenge_screens_expose_no_other_player(): void
    {
        $this->dailyGuess($this->player, 90);

        foreach (['/api/daily/today', '/api/daily/stats', '/api/daily/leaderboard'] as $url) {
            $this->assertNoPrivateData($this->as($this->viewer)->getJson($url), $this->player);
        }
    }

    public function test_tournament_members_show_only_the_username(): void
    {
        $league = $this->league([$this->viewer, $this->player], false);

        $res = $this->as($this->viewer)->getJson("/api/leagues/{$league->id}")->assertOk();

        $res->assertJsonPath('data.members.1.username', 'GoalGetter')
            ->assertJsonPath('data.members.1.name', 'GoalGetter');
        $this->assertNoPrivateData($res, $this->player);
    }

    public function test_friends_requests_and_suggestions_show_only_the_username(): void
    {
        Friendship::create(['user_id' => $this->viewer->id, 'friend_id' => $this->player->id]);
        Friendship::create(['user_id' => $this->player->id, 'friend_id' => $this->viewer->id]);
        $asker = User::factory()->create(['username' => 'asker', 'name' => 'Anna Asker', 'email' => 'anna@example.test']);
        FriendRequest::create(['requester_id' => $asker->id, 'recipient_id' => $this->viewer->id, 'status' => 'pending']);
        $this->league([$this->viewer, $suggested = User::factory()->create(['username' => 'suggested', 'name' => 'Sue Gested'])], false);

        $friends = $this->as($this->viewer)->getJson('/api/friends')->assertOk();
        $friends->assertJsonPath('data.0.username', 'GoalGetter')->assertJsonPath('data.0.name', 'GoalGetter');
        $this->assertNoPrivateData($friends, $this->player);

        $requests = $this->as($this->viewer)->getJson('/api/friends/requests')->assertOk();
        $requests->assertJsonPath('incoming.0.user.name', 'asker');
        $this->assertNoPrivateData($requests, $asker);

        $suggestions = $this->as($this->viewer)->getJson('/api/friends/suggestions')->assertOk();
        $suggestions->assertJsonPath('data.0.name', 'suggested');
        $this->assertNoPrivateData($suggestions, $suggested);
    }

    public function test_public_profile_shows_only_the_username(): void
    {
        $res = $this->as($this->viewer)->getJson("/api/users/{$this->player->id}/public-profile")->assertOk();

        $res->assertJsonPath('data.name', 'GoalGetter')->assertJsonPath('data.username', 'GoalGetter');
        $this->assertNoPrivateData($res, $this->player);
    }

    public function test_data_export_does_not_carry_another_players_real_name(): void
    {
        Friendship::create(['user_id' => $this->viewer->id, 'friend_id' => $this->player->id]);

        $res = $this->as($this->viewer)->getJson('/api/me/export')->assertOk();

        $res->assertJsonPath('friends.0.username', 'GoalGetter');
        $this->assertNoPrivateData($res, $this->player);
        $this->assertStringContainsString('Vera Viewer', $res->getContent()); // the subject's own name stays
    }

    public function test_the_players_own_account_still_returns_their_entered_name(): void
    {
        $this->as($this->player)->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.name', self::REAL_NAME)
            ->assertJsonPath('data.username', 'GoalGetter');
    }

    /**
     * Sweep instead of a list: a GET endpoint added later is covered automatically.
     * /me/export is the viewer's own data and has its own test above.
     */
    public function test_no_api_get_endpoint_exposes_another_players_name_or_email(): void
    {
        $league = $this->league([$this->viewer, $this->player]);
        $this->dailyGuess($this->viewer, 80);
        $this->dailyGuess($this->player, 90);
        Friendship::create(['user_id' => $this->viewer->id, 'friend_id' => $this->player->id]);
        Friendship::create(['user_id' => $this->player->id, 'friend_id' => $this->viewer->id]);

        $params = [
            'league'         => $league->id,
            'dailyChallenge' => DailyChallenge::first()->id,
            'round'          => LeagueRound::first()->id,
            'user'           => $this->player->id,
            'slug'           => 'none',
        ];

        $checked = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/') || !in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]] ?? 'unknown', $route->uri());
            $res = $this->as($this->viewer)->getJson('/' . $uri);

            $this->assertLessThan(500, $res->getStatusCode(), $uri);
            $this->assertStringNotContainsString(self::REAL_NAME, $res->getContent(), $uri);
            $this->assertStringNotContainsString($this->player->email, $res->getContent(), $uri);
            $checked++;
        }

        $this->assertGreaterThan(30, $checked, 'the sweep really walked the API');
    }

    public function test_two_players_with_the_same_real_name_stay_distinguishable(): void
    {
        $twin = User::factory()->create(['username' => 'TheOtherJan', 'name' => self::REAL_NAME]);
        $league = $this->league([$this->player, $twin, $this->viewer]);

        $res = $this->as($this->viewer)->getJson("/api/leagues/{$league->id}/leaderboard")->assertOk();

        $this->assertSame(['GoalGetter', 'TheOtherJan', 'viewer1'], array_column($res->json('data'), 'name'));
        $this->assertStringNotContainsString(self::REAL_NAME, $res->getContent());
    }

    public function test_username_letter_case_is_shown_as_chosen(): void
    {
        $mixed = User::factory()->create(['username' => 'xAnDeR', 'name' => 'Someone Else']);

        $this->as($this->viewer)->getJson("/api/users/{$mixed->id}/public-profile")->assertOk()
            ->assertJsonPath('data.username', 'xAnDeR');
    }

    public function test_missing_or_email_shaped_legacy_usernames_get_a_neutral_label(): void
    {
        $blank = User::factory()->create(['username' => '   ', 'name' => 'Blank Name']);
        $email = User::factory()->create(['username' => 'legacy@example.test', 'name' => 'Legacy Name']);

        foreach ([$blank, $email] as $legacy) {
            $res = $this->as($this->viewer)->getJson("/api/users/{$legacy->id}/public-profile")->assertOk();
            $res->assertJsonPath('data.name', "Player #{$legacy->id}")->assertJsonPath('data.username', "Player #{$legacy->id}");
            $this->assertStringNotContainsString('example.test', $res->getContent());
            $this->assertStringNotContainsString('Name', (string) $res->json('data.name'));
        }
    }

    public function test_public_name_helper_handles_edge_cases_without_a_real_name_input(): void
    {
        $this->assertSame('sam', User::publicNameFor(1, ' @sam '));
        $this->assertSame('Player #7', User::publicNameFor(7, null));
        $this->assertSame('Player #7', User::publicNameFor(7, '@'));
        $this->assertSame('Deleted player', User::publicNameFor(7, 'deleted-7', true));

        app()->setLocale('nl');
        $this->assertSame('Speler #7', User::publicNameFor(7, null));
    }

    public function test_every_language_has_the_public_name_labels(): void
    {
        foreach (['en', 'nl', 'fr', 'de', 'es'] as $locale) {
            app()->setLocale($locale);
            $this->assertStringContainsString('#7', User::publicNameFor(7, null), $locale);
            $this->assertNotSame('messages.player.deleted', User::publicNameFor(7, null, true), $locale);
            $this->assertNotSame('auth_codes.username_invalid', __('auth_codes.username_invalid'), $locale);
        }
    }

    public function test_response_shapes_for_published_apps_are_unchanged(): void
    {
        $league = $this->league([$this->player, $this->viewer]);
        $this->dailyGuess($this->player, 90);
        Friendship::create(['user_id' => $this->viewer->id, 'friend_id' => $this->player->id]);

        $this->as($this->viewer)->getJson("/api/leagues/{$league->id}/leaderboard")->assertJsonStructure([
            'data' => [['rank', 'user_id', 'username', 'name', 'total_score', 'guesses_count', 'avg_score', 'is_current_user']],
            'meta' => ['total_players', 'current_user_rank', 'top_users', 'nearby_users'],
        ]);
        $this->as($this->viewer)->getJson('/api/daily/leaderboard/weekly')->assertJsonStructure([
            'data' => [['user_id', 'username', 'name', 'total_score', 'challenges_played', 'avg_score', 'rank', 'is_current_user']],
            'week_start', 'week_end', 'period_label', 'period', 'meta',
        ]);
        $this->as($this->viewer)->getJson('/api/friends')->assertJsonStructure([
            'data' => [['id', 'name', 'username', 'avatar_url', 'rank_name', 'level', 'total_xp']],
        ]);
        $this->as($this->viewer)->getJson("/api/leagues/{$league->id}")->assertJsonStructure([
            'data' => ['members' => [['id', 'name', 'username', 'is_owner', 'joined_at']]],
        ]);
    }
}
