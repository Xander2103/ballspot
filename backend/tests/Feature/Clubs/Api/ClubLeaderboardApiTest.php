<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Models\ChallengePack;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\PackAttempt;
use App\Models\User;
use Carbon\Carbon;

/**
 * GET /api/clubs/{club}/leaderboard — club all-time board (D19): per member
 * per club pack the best of the normal attempt total and the non-voided
 * competition attempt totals, summed; active members only; shared ranks.
 */
class ClubLeaderboardApiTest extends ClubApiTestCase
{
    private function normal(User $user, ChallengePack $pack, int $score, string $at, string $status = 'completed', int $index = 2): void
    {
        PackAttempt::create([
            'user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => $status, 'current_index' => $index,
            'total_score' => $score, 'started_at' => $at, 'completed_at' => $status === 'completed' ? $at : null,
            'metadata' => ['challenge_ids' => []],
        ])->forceFill(['updated_at' => $at])->saveQuietly();
    }

    private function competition(ClubCompetition $c, User $user, ChallengePack $pack, int $number, int $score, string $at,
        CompetitionAttemptStatus $status = CompetitionAttemptStatus::Completed): void
    {
        ClubCompetitionAttempt::create([
            'club_competition_id' => $c->id, 'challenge_pack_id' => $pack->id, 'user_id' => $user->id,
            'attempt_number' => $number, 'status' => $status, 'active_slot' => $status === CompetitionAttemptStatus::Active ? 1 : null,
            'challenge_ids' => [], 'current_index' => 2, 'guesses_count' => 2, 'total_score' => $score,
            'started_at' => $at, 'last_scored_at' => $at,
        ]);
    }

    public function test_totals_take_the_best_track_per_pack_and_rank_with_shared_ranks(): void
    {
        $anna  = User::factory()->create(['username' => 'anna']);
        $jules = User::factory()->create(['username' => 'jules']);
        $tom   = User::factory()->create(['username' => 'tom']);
        $zoe   = User::factory()->create(['username' => 'zoe']);
        $gone  = User::factory()->create(['username' => 'gone']);
        $idle  = User::factory()->create(['username' => 'idle']);
        $club  = $this->makeClub([], $anna);
        foreach ([$jules, $tom, $zoe, $idle] as $u) {
            $this->addMember($club, $u);
        }
        $this->addMember($club, $gone, ClubRole::Member, ClubMemberStatus::Left);

        $p1 = $this->makeClubPack($club, 2);
        $p2 = $this->makeClubPack($club, 2);
        $otherClub = $this->makeClub([], User::factory()->create());
        $foreignPack = $this->makeClubPack($otherClub, 2);
        $public = $this->makePublicPack(2);

        $cup    = $this->makeCompetition($club, [$p1, $p2], ['state' => CompetitionState::Finalized, 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(2)]);
        $voided = $this->makeCompetition($club, [$p1], ['state' => CompetitionState::Voided, 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(2)]);

        $t1 = '2026-10-01 10:00:00';
        $t2 = '2026-10-02 10:00:00';
        $t3 = '2026-10-03 10:00:00';

        // anna: p1 normal 150, p1 competition 180 (best 180) + p2 normal 100 = 280, reached t2.
        $this->normal($anna, $p1, 150, $t1);
        $this->competition($cup, $anna, $p1, 1, 180, $t2);
        $this->normal($anna, $p2, 100, $t1);
        // jules: p1 normal 200 + p2 competition 80 (+ a voided 199 attempt and a voided competition) = 280, reached t2.
        $this->normal($jules, $p1, 200, $t1);
        $this->competition($cup, $jules, $p2, 1, 80, $t2);
        $this->competition($cup, $jules, $p2, 2, 199, $t2, CompetitionAttemptStatus::Voided);
        $this->competition($voided, $jules, $p1, 1, 999, $t1);
        // tom: same total and moment as jules but fewer attempts (anna/jules used 3 / 2 counted).
        $this->normal($tom, $p1, 280, $t2, 'active', 1);
        // zoe: 280 too, reached later.
        $this->normal($zoe, $p1, 280, $t3);
        // gone (left) and points outside the club never count.
        $this->normal($gone, $p1, 500, $t1);
        $this->normal($anna, $foreignPack, 900, $t1);
        $this->normal($anna, $public, 900, $t1);
        // idle started but never guessed: not on the board.
        $this->normal($idle, $p2, 0, $t1, 'active', 0);

        $res = $this->api($jules)->getJson($this->clubPath($club, '/leaderboard'))->assertOk();
        $rows = $res->json('data');

        $this->assertSame(['tom', 'jules', 'anna', 'zoe'], array_column($rows, 'username'));
        $this->assertSame([280, 280, 280, 280], array_column($rows, 'total_score'));
        // tom: 1 attempt; jules: 2 counted (voided ones excluded); anna: 3.
        $this->assertSame([1, 2, 3, 1], array_column($rows, 'attempts_used'));
        $this->assertSame([1, 2, 3, 4], array_column($rows, 'rank'));
        $this->assertSame([0, 2, 2, 1], array_column($rows, 'packs_completed'));
        $this->assertSame([false, true, false, false], array_column($rows, 'is_current_user'));
        $this->assertSame(['rank', 'user_id', 'username', 'avatar_url', 'total_score', 'packs_completed', 'attempts_used', 'is_current_user'], array_keys($rows[0]));

        $res->assertJsonPath('meta.total_players', 4)
            ->assertJsonPath('meta.current_user_rank', 2)
            ->assertJsonPath('meta.current_user_score', 280)
            ->assertJsonPath('meta.better_than_percentage', 50)
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.per_page', 20);
        $this->assertSame(['tom', 'jules', 'anna'], array_column($res->json('meta.top_users'), 'username'));
        $this->assertSame(['tom', 'jules', 'anna'], array_column($res->json('meta.nearby_users'), 'username'));
    }

    public function test_fully_equal_players_share_the_rank_1_2_2_4(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        $a = User::factory()->create(['username' => 'a-first']);
        $b = User::factory()->create(['username' => 'b-tied']);
        $c = User::factory()->create(['username' => 'c-tied']);
        $d = User::factory()->create(['username' => 'd-last']);
        $club = $this->makeClub([], $a);
        foreach ([$b, $c, $d] as $u) {
            $this->addMember($club, $u);
        }
        $pack = $this->makeClubPack($club, 2);
        $at = '2026-10-05 10:00:00';
        $this->normal($a, $pack, 300, $at);
        $this->normal($c, $pack, 200, $at);
        $this->normal($b, $pack, 200, $at);
        $this->normal($d, $pack, 100, $at);

        $rows = $this->api($d)->getJson($this->clubPath($club, '/leaderboard'))->assertOk()->json('data');

        $this->assertSame([1, 2, 2, 4], array_column($rows, 'rank'));
        // Inside a shared rank: username order (display only).
        $this->assertSame(['a-first', 'b-tied', 'c-tied', 'd-last'], array_column($rows, 'username'));
        Carbon::setTestNow();
    }

    public function test_the_board_pages_with_a_cursor_and_keeps_the_meta(): void
    {
        $users = User::factory()->count(7)->create();
        $club  = $this->makeClub([], $users[0]);
        $pack  = $this->makeClubPack($club, 1);
        foreach ($users as $i => $u) {
            if ($i > 0) {
                $this->addMember($club, $u);
            }
            $this->normal($u, $pack, 100 + $i, '2026-10-01 10:00:00');
        }

        $first = $this->api($users[0])->getJson($this->clubPath($club, '/leaderboard?limit=3'))->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('meta.per_page', 3)->assertJsonPath('meta.current_user_rank', 7);
        $ids = array_column($first->json('data'), 'user_id');
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        while ($cursor) {
            $page = $this->api($users[0])->getJson($this->clubPath($club, '/leaderboard?limit=3&cursor=' . urlencode($cursor)))->assertOk();
            $ids = array_merge($ids, array_column($page->json('data'), 'user_id'));
            $cursor = $page->json('meta.next_cursor');
        }
        $this->assertSame($users->reverse()->pluck('id')->values()->all(), $ids);

        // Last place: nearby = one above + me.
        $this->assertSame([6, 7], array_column($first->json('meta.nearby_users'), 'rank'));
        $this->api($users[0])->getJson($this->clubPath($club, '/leaderboard?cursor=xx'))->assertStatus(422);
    }

    public function test_a_player_not_on_the_board_gets_null_meta_and_an_empty_club_is_empty(): void
    {
        $owner = User::factory()->create();
        $club  = $this->makeClub([], $owner);

        $this->api($owner)->getJson($this->clubPath($club, '/leaderboard'))->assertOk()
            ->assertExactJson(['data' => [], 'meta' => [
                'total_players' => 0, 'current_user_rank' => null, 'current_user_score' => null, 'better_than_percentage' => null,
                'top_users' => [], 'nearby_users' => [], 'next_cursor' => null, 'per_page' => 20,
            ]]);
    }

    public function test_club_detail_shows_the_leaderboard_preview(): void
    {
        $a = User::factory()->create(['username' => 'alpha']);
        $b = User::factory()->create(['username' => 'bravo']);
        $club = $this->makeClub([], $a);
        $this->addMember($club, $b);
        $pack = $this->makeClubPack($club, 1);
        $this->normal($a, $pack, 90, '2026-10-01 10:00:00');
        $this->normal($b, $pack, 60, '2026-10-01 10:00:00');

        $this->api($b)->getJson($this->clubPath($club))->assertOk()
            ->assertJsonPath('data.leaderboard_preview.top.0.username', 'alpha')
            ->assertJsonPath('data.leaderboard_preview.top.1.username', 'bravo')
            ->assertJsonPath('data.leaderboard_preview.me.rank', 2)
            ->assertJsonPath('data.leaderboard_preview.me.is_current_user', true);
    }
}
