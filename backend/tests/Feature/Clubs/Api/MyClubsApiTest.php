<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\CompetitionState;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** GET /api/clubs — My Clubs (AT-MY-01…04). */
class MyClubsApiTest extends ClubApiTestCase
{
    // ------------------------------------------------------------ AT-MY-01

    public function test_lists_only_the_callers_active_and_suspended_memberships(): void
    {
        $user = User::factory()->create();
        $active    = $this->makeClub(['name' => 'Active FC']);
        $suspended = $this->makeClub(['name' => 'Suspended FC', 'status' => ClubStatus::Suspended]);
        $archived  = $this->makeClub(['name' => 'Archived FC', 'status' => ClubStatus::Archived]);
        $leftClub  = $this->makeClub(['name' => 'Left FC']);
        $banned    = $this->makeClub(['name' => 'Banned FC']);
        $notJoined = $this->makeClub(['name' => 'Other FC']);
        $this->addMember($notJoined, User::factory()->create());

        $this->addMember($active, $user, ClubRole::Owner);
        $this->addMember($suspended, $user);
        $this->addMember($archived, $user);
        $this->addMember($leftClub, $user, ClubRole::Member, ClubMemberStatus::Left);
        $this->addMember($banned, $user, ClubRole::Member, ClubMemberStatus::Banned);

        $res = $this->api($user)->getJson('/api/clubs?sort=name')->assertOk();

        $this->assertSame(['Active FC', 'Suspended FC'], array_column($res->json('data'), 'name'));
        $res->assertJsonPath('data.0.role', 'owner')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.1.status', 'suspended')
            ->assertJsonPath('meta.total_joined', 2)
            ->assertJsonPath('meta.query', null)
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.per_page', 20);
        $this->assertSame(['next_cursor', 'per_page', 'total_joined', 'query'], array_keys($res->json('meta')));
    }

    public function test_an_empty_list_for_a_player_without_clubs(): void
    {
        $this->api(User::factory()->create())->getJson('/api/clubs')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null, 'per_page' => 20, 'total_joined' => 0, 'query' => null]]);
    }

    // ------------------------------------------------------------ AT-MY-02

    public function test_q_never_returns_a_club_the_caller_has_not_joined(): void
    {
        $user = User::factory()->create();
        $mine = $this->makeClub(['name' => 'KFC Gent Noord', 'city' => 'Gent']);
        $this->addMember($mine, $user);
        $this->makeClub(['name' => 'KFC Antwerp', 'city' => 'Antwerpen']);
        $this->makeClub(['name' => 'VV Brugge', 'city' => 'Brugge']);

        $this->api($user)->getJson('/api/clubs?q=antwerp')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total_joined', 1)->assertJsonPath('meta.query', 'antwerp');
        $this->api($user)->getJson('/api/clubs?q=brugge')->assertOk()->assertJsonCount(0, 'data');

        // Name and city both match, case-insensitively, trimmed.
        $this->api($user)->getJson('/api/clubs?q=' . urlencode('  kfc  '))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->public_id)->assertJsonPath('meta.query', 'kfc');
        $this->api($user)->getJson('/api/clubs?q=GENT')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_like_wildcards_in_q_are_literal_and_q_is_cut_to_60_characters(): void
    {
        $user = User::factory()->create();
        $this->addMember($this->makeClub(['name' => 'Alpha', 'city' => 'X']), $user);
        $this->addMember($this->makeClub(['name' => '100% Club', 'city' => 'Y']), $user);

        $this->api($user)->getJson('/api/clubs?q=' . urlencode('%'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% Club');
        $this->api($user)->getJson('/api/clubs?q=_')->assertOk()->assertJsonCount(0, 'data');

        $long = str_repeat('a', 80);
        $this->assertSame(60, mb_strlen($this->api($user)->getJson('/api/clubs?q=' . $long)->assertOk()->json('meta.query')));
        $this->api($user)->getJson('/api/clubs?sort=popular')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    // ------------------------------------------------------------ AT-MY-03

    public function test_120_memberships_page_without_duplicates_or_gaps_in_both_sorts(): void
    {
        $user = User::factory()->create();
        $base = now()->subDays(10);
        $expectedIds = [];
        for ($i = 0; $i < 120; $i++) {
            $club = Club::factory()->create(['name' => sprintf('Club %03d', ($i * 37) % 120)]);
            // Every 3 memberships share the same last_seen_at: ties must be broken by id.
            ClubMember::create([
                'club_id'      => $club->id,
                'user_id'      => $user->id,
                'role'         => ClubRole::Member,
                'status'       => ClubMemberStatus::Active,
                'joined_at'    => $base,
                'last_seen_at' => $base->copy()->addMinutes(intdiv($i, 3)),
            ]);
            $expectedIds[] = $club->public_id;
        }

        foreach (['recent', 'name'] as $sort) {
            $seen   = [];
            $cursor = null;
            $pages  = 0;
            do {
                $url = '/api/clubs?sort=' . $sort . ($cursor ? '&cursor=' . urlencode($cursor) : '');
                $res = $this->api($user)->getJson($url)->assertOk()->assertJsonPath('meta.total_joined', 120);
                $this->assertLessThanOrEqual(20, count($res->json('data')));
                foreach ($res->json('data') as $card) {
                    $seen[] = $card['id'];
                }
                $cursor = $res->json('meta.next_cursor');
                $pages++;
            } while ($cursor !== null && $pages < 20);

            $this->assertSame(6, $pages, $sort);
            $this->assertCount(120, $seen, $sort);
            $this->assertCount(120, array_unique($seen), "{$sort}: duplicates across pages");
            $this->assertEqualsCanonicalizing($expectedIds, $seen, "{$sort}: gaps across pages");
        }

        // `recent` = last_seen_at desc (then newest membership first).
        $first = $this->api($user)->getJson('/api/clubs?sort=recent&limit=3')->assertOk()->json('data');
        $this->assertSame(array_slice(array_reverse($expectedIds), 0, 3), array_column($first, 'id'));

        // `name` = alphabetical.
        $names = array_column($this->api($user)->getJson('/api/clubs?sort=name&limit=5')->json('data'), 'name');
        $this->assertSame(['Club 000', 'Club 001', 'Club 002', 'Club 003', 'Club 004'], $names);
    }

    public function test_limit_is_clamped_and_a_bad_cursor_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 55; $i++) {
            $this->addMember($this->makeClub(), $user);
        }

        $this->api($user)->getJson('/api/clubs?limit=500')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.per_page', 50);
        $this->api($user)->getJson('/api/clubs?limit=5')->assertOk()->assertJsonCount(5, 'data');
        $this->api($user)->getJson('/api/clubs?cursor=garbage')->assertStatus(422)->assertJsonValidationErrors('cursor');
    }

    public function test_one_page_runs_a_fixed_number_of_queries(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            $club = $this->makeClub(['sport_id' => $this->clubSport()->id]);
            $this->addMember($club, $user);
            $this->makeClubPack($club, 1);
        }

        $this->api($user); // token creation outside the measured request
        DB::enableQueryLog();
        $this->api($user)->getJson('/api/clubs')->assertOk()->assertJsonCount(20, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, 'My Clubs must not query per club');
    }

    // ------------------------------------------------------------ AT-MY-04

    public function test_counts_reflect_active_packs_and_upcoming_or_active_published_competitions_only(): void
    {
        $user = User::factory()->create();
        $club = $this->makeClub();
        $this->addMember($club, $user);

        $active = $this->makeClubPack($club, 1);
        $this->makeClubPack($club, 1);
        $this->makeClubPack($club, 1, ['status' => ChallengePack::STATUS_DRAFT]);
        $this->makeClubPack($club, 1, ['status' => ChallengePack::STATUS_ARCHIVED]);
        $this->makeClubPack($club, 1, ['visibility' => ChallengePack::VISIBILITY_HIDDEN]);

        $this->makeCompetition($club, [$active], ['state' => CompetitionState::Published, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->makeCompetition($club, [$active], ['state' => CompetitionState::Published, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);
        $this->makeCompetition($club, [$active], ['state' => CompetitionState::Published, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)]);
        $this->makeCompetition($club, [$active], ['state' => CompetitionState::Draft, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->makeCompetition($club, [$active], ['state' => CompetitionState::Cancelled, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->makeCompetition($club, [$active], ['state' => CompetitionState::Published, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subHour()]);
        $this->makeCompetition($club, [$active], ['state' => CompetitionState::Finalized, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

        $this->api($user)->getJson('/api/clubs')->assertOk()
            ->assertJsonPath('data.0.packs_count', 2)
            ->assertJsonPath('data.0.competitions', ['active' => 1, 'upcoming' => 2]);
    }
}
