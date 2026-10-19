<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Enums\NormalPlayPolicy;
use App\Models\ClubCompetitionAttempt;
use App\Models\PackAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Club packs on the normal track: list, detail, start, attempt (contracts
 * §5), the competition reservation (D15) and suspended clubs (D18).
 */
class ClubPackApiTest extends ClubApiTestCase
{
    // ------------------------------------------------------------ list

    public function test_pack_list_cards_have_the_public_card_fields_plus_club_fields(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $featured = $this->makeClubPack($club, 3, ['name' => 'B pack', 'is_featured' => true]);
        $plain    = $this->makeClubPack($club, 2, ['name' => 'A pack']);
        $public   = $this->makePublicPack(2);

        $publicCard = $this->api($user)->getJson('/api/packs')->assertOk()->json('data.0');
        $res = $this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', $featured->slug)
            ->assertJsonPath('data.1.slug', $plain->slug)
            ->assertJsonPath('data.0.challenge_count', 3)
            ->assertJsonPath('data.0.progress', null)
            ->assertJsonPath('data.0.best_score', null)
            ->assertJsonPath('data.0.reserved_for_competition', false)
            ->assertJsonPath('data.0.competitions', [])
            ->assertJsonPath('meta', ['next_cursor' => null, 'per_page' => 20]);

        $this->assertSame(
            array_merge(array_keys($publicCard), ['best_score', 'reserved_for_competition', 'competitions']),
            array_keys($res->json('data.0')),
        );
        $this->assertNotContains($public->slug, array_column($res->json('data'), 'slug'));

        // Progress after one guess (normal track) and best_score.
        $attemptId = $this->startClubPack($user, $club, $plain)->assertOk()->json('attempt.id');
        $this->guessCurrent($user, $attemptId)->assertOk();

        $this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()
            ->assertJsonPath('data.1.progress', ['status' => 'active', 'completed_count' => 1, 'total_challenges' => 2, 'total_score' => 100])
            ->assertJsonPath('data.1.best_score', 100);
    }

    public function test_best_score_takes_the_better_track_and_ignores_voided_competition_attempts(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 2);
        $competition = $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Finalized, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

        PackAttempt::create(['user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => 'completed', 'current_index' => 2,
            'total_score' => 120, 'started_at' => now(), 'completed_at' => now(), 'metadata' => ['challenge_ids' => []]]);
        $this->competitionAttempt($competition->id, $pack->id, $user->id, 1, 150, CompetitionAttemptStatus::Completed);
        $this->competitionAttempt($competition->id, $pack->id, $user->id, 2, 190, CompetitionAttemptStatus::Voided);

        $this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()->assertJsonPath('data.0.best_score', 150);
    }

    public function test_pack_list_pages_with_a_cursor_and_a_fixed_number_of_queries(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        for ($i = 0; $i < 7; $i++) {
            $this->makeClubPack($club, 1, ['sort_order' => $i % 2]);
        }

        $first = $this->api($user)->getJson($this->clubPath($club, '/packs?limit=3'))->assertOk()->assertJsonCount(3, 'data');
        $slugs = array_column($first->json('data'), 'slug');
        $cursor = $first->json('meta.next_cursor');
        while ($cursor) {
            $page = $this->api($user)->getJson($this->clubPath($club, '/packs?limit=3&cursor=' . urlencode($cursor)))->assertOk();
            $slugs = array_merge($slugs, array_column($page->json('data'), 'slug'));
            $cursor = $page->json('meta.next_cursor');
        }
        $this->assertCount(7, array_unique($slugs));

        DB::enableQueryLog();
        $this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()->assertJsonCount(7, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(15, $queries);
    }

    // ------------------------------------------------------------ detail

    public function test_pack_detail_matches_the_public_detail_shape_plus_club_fields(): void
    {
        $user   = User::factory()->create();
        $club   = $this->makeClub([], $user);
        $pack   = $this->makeClubPack($club, 2);
        $public = $this->makePublicPack(2);

        $publicDetail = $this->api($user)->getJson('/api/packs/' . $public->slug)->assertOk()->json('data');
        $res = $this->api($user)->getJson($this->packPath($club, $pack))->assertOk()
            ->assertJsonPath('data.slug', $pack->slug)
            ->assertJsonPath('data.challenge_count', 2)
            ->assertJsonCount(2, 'data.challenges')
            ->assertJsonPath('data.reserved_for_competition', false)
            ->assertJsonPath('data.competitions', []);

        $this->assertSame(array_merge(array_keys($publicDetail), ['reserved_for_competition', 'competitions']), array_keys($res->json('data')));
        $this->assertSame(array_keys($publicDetail['challenges'][0]), array_keys($res->json('data.challenges.0')));
        // Club images are private: signed media URLs, never the ball position.
        $this->assertStringContainsString('/media/clubs/', $res->json('data.challenges.0.hidden_image_url'));
        $this->assertArrayNotHasKey('ball_x_ratio', $res->json('data.challenges.0'));
    }

    // ------------------------------------------------------------ start / attempt

    public function test_start_and_attempt_have_the_public_contract_including_the_replay_409(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 2);

        $this->api($user)->getJson($this->packPath($club, $pack, '/attempt'))->assertOk()
            ->assertExactJson(['attempt' => null, 'challenge' => null, 'completion' => null]);

        $start = $this->startClubPack($user, $club, $pack)->assertOk()
            ->assertJsonPath('attempt.status', 'active')
            ->assertJsonPath('attempt.total_challenges', 2)
            ->assertJsonPath('completion', null);
        $this->assertSame(['attempt', 'challenge', 'completion'], array_keys($start->json()));
        $this->assertStringContainsString('/media/clubs/', $start->json('challenge.hidden_image_url'));

        // Start again = resume the same attempt.
        $this->startClubPack($user, $club, $pack)->assertOk()->assertJsonPath('attempt.id', $start->json('attempt.id'));
        $this->api($user)->getJson($this->packPath($club, $pack, '/attempt'))->assertOk()
            ->assertJsonPath('attempt.id', $start->json('attempt.id'))
            ->assertJsonPath('challenge.id', $start->json('challenge.id'));

        $this->guessCurrent($user, $start->json('attempt.id'))->assertOk();
        $this->guessCurrent($user, $start->json('attempt.id'))->assertOk()->assertJsonPath('pack_completed', true);

        $replay = $this->startClubPack($user, $club, $pack)->assertStatus(409)
            ->assertJsonPath('message', __('messages.packs.already_completed'))
            ->assertJsonPath('attempt.status', 'completed')
            ->assertJsonPath('challenge', null);
        $this->assertNotNull($replay->json('completion'));
        $this->assertSame(1, PackAttempt::count());

        $this->api($user)->getJson($this->packPath($club, $pack, '/attempt'))->assertOk()
            ->assertJsonPath('attempt.status', 'completed')
            ->assertJsonPath('challenge', null)
            ->assertJsonPath('completion.total_score', 200);
    }

    // ------------------------------------------------------------ reservation (D15)

    public function test_a_pack_reserved_by_a_blocked_published_competition_answers_409(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 2);
        $competition = $this->makeCompetition($club, [$pack], [
            'name' => 'Autumn Cup', 'state' => CompetitionState::Published, 'normal_play_policy' => NormalPlayPolicy::Blocked,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(5),
        ]);

        $this->startClubPack($user, $club, $pack)->assertStatus(409)
            ->assertJsonPath('code', 'pack_reserved_for_competition')
            ->assertJsonPath('message', __('clubs.errors.pack_reserved_for_competition'));
        $this->assertSame(0, PackAttempt::count());

        $this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()
            ->assertJsonPath('data.0.reserved_for_competition', true)
            ->assertJsonPath('data.0.competitions', [['id' => $competition->public_id, 'name' => 'Autumn Cup', 'phase' => 'upcoming']]);

        // The detail never previews the photos while reserved.
        $this->api($user)->getJson($this->packPath($club, $pack))->assertOk()
            ->assertJsonPath('data.reserved_for_competition', true)
            ->assertJsonPath('data.challenge_count', 2)
            ->assertJsonPath('data.challenges', [])
            ->assertJsonPath('data.competitions.0.id', $competition->public_id);

        // After finalization the pack returns to normal play.
        $competition->update(['state' => CompetitionState::Finalized, 'finalized_at' => now()]);
        $this->startClubPack($user, $club, $pack)->assertOk();
        $this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()
            ->assertJsonPath('data.0.reserved_for_competition', false)
            ->assertJsonPath('data.0.competitions', []);
    }

    public function test_normal_play_policy_allowed_and_drafts_do_not_reserve(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $allowedPack = $this->makeClubPack($club, 1);
        $draftPack   = $this->makeClubPack($club, 1);
        $this->makeCompetition($club, [$allowedPack], [
            'state' => CompetitionState::Published, 'normal_play_policy' => NormalPlayPolicy::Allowed,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(5),
        ]);
        $this->makeCompetition($club, [$draftPack], ['state' => CompetitionState::Draft]);

        $this->startClubPack($user, $club, $allowedPack)->assertOk();
        $this->startClubPack($user, $club, $draftPack)->assertOk();

        $cards = collect($this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()->json('data'))->keyBy('slug');
        $this->assertFalse($cards[$allowedPack->slug]['reserved_for_competition']);
        $this->assertSame('active', $cards[$allowedPack->slug]['competitions'][0]['phase']);
        $this->assertSame([], $cards[$draftPack->slug]['competitions']);
    }

    public function test_an_attempt_in_progress_is_frozen_while_the_pack_is_reserved_and_resumes_after_cancel(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 2);
        $attemptId = $this->startClubPack($user, $club, $pack)->assertOk()->json('attempt.id');

        $competition = $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Published, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        $this->startClubPack($user, $club, $pack)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition');
        $this->api($user)->getJson($this->packPath($club, $pack, '/attempt'))->assertOk()->assertJsonPath('attempt.id', $attemptId);
        // Frozen (security review 2026-10): the normal reveal would leak the answer key.
        $this->guessCurrent($user, $attemptId)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition');

        $competition->forceFill(['state' => CompetitionState::Cancelled, 'cancelled_at' => now()])->save();
        $this->guessCurrent($user, $attemptId)->assertOk();
        $this->guessCurrent($user, $attemptId)->assertOk()->assertJsonPath('pack_completed', true);
    }

    // ------------------------------------------------------------ suspended (D18)

    public function test_a_suspended_club_is_readable_but_cannot_be_played(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub([], $user);
        $pack = $this->makeClubPack($club, 2);
        $attemptId = $this->startClubPack($user, $club, $pack)->assertOk()->json('attempt.id');
        $club->update(['status' => 'suspended']);

        $this->api($user)->getJson('/api/clubs')->assertOk()->assertJsonPath('data.0.status', 'suspended');
        $this->api($user)->getJson($this->clubPath($club))->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->api($user)->getJson($this->clubPath($club, '/packs'))->assertOk()->assertJsonCount(1, 'data');
        $this->api($user)->getJson($this->packPath($club, $pack))->assertOk();
        $this->api($user)->getJson($this->packPath($club, $pack, '/attempt'))->assertOk()->assertJsonPath('attempt.id', $attemptId);
        $this->api($user)->getJson($this->clubPath($club, '/leaderboard'))->assertOk();

        $other = $this->makeClubPack($club, 1);
        $this->startClubPack($user, $club, $other)->assertStatus(423)->assertJsonPath('code', 'club_unavailable');
        $this->startClubPack($user, $club, $pack)->assertStatus(423)->assertJsonPath('code', 'club_unavailable');
        $this->guessCurrent($user, $attemptId)->assertStatus(423);
        $this->assertSame(1, PackAttempt::count());
    }

    // ------------------------------------------------------------ helpers

    private function competitionAttempt(int $competitionId, int $packId, int $userId, int $number, int $score, CompetitionAttemptStatus $status): ClubCompetitionAttempt
    {
        return ClubCompetitionAttempt::create([
            'club_competition_id' => $competitionId,
            'challenge_pack_id'   => $packId,
            'user_id'             => $userId,
            'attempt_number'      => $number,
            'status'              => $status,
            'active_slot'         => $status === CompetitionAttemptStatus::Active ? 1 : null,
            'challenge_ids'       => [],
            'current_index'       => 2,
            'guesses_count'       => 2,
            'total_score'         => $score,
            'started_at'          => now()->subDays(2),
            'last_scored_at'      => now()->subDays(2),
        ]);
    }
}
