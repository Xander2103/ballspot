<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\CompetitionState;
use App\Enums\ReplayMode;
use App\Enums\ScoreRule;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubMember;
use App\Models\User;

/** GET /api/clubs/{club} and GET /api/clubs/{club}/members (contracts §5, D9, D12). */
class ClubDetailApiTest extends ClubApiTestCase
{
    public function test_detail_is_the_club_card_plus_identity_abilities_and_previews(): void
    {
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $club   = $this->makeClub([
            'name' => 'KFC Example', 'description' => 'Our club', 'region' => 'Oost-Vlaanderen',
            'contact_email' => 'secret@example.test', 'contact_name' => 'Secret Person',
        ], $owner);
        $this->addMember($club, $member);
        foreach (range(1, 4) as $i) {
            $this->makeClubPack($club, 1);
        }

        $res = $this->api($member)->getJson($this->clubPath($club))->assertOk()
            ->assertJsonPath('data.id', $club->public_id)
            ->assertJsonPath('data.name', 'KFC Example')
            ->assertJsonPath('data.description', 'Our club')
            ->assertJsonPath('data.region', 'Oost-Vlaanderen')
            ->assertJsonPath('data.members_count', 2)
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.packs_count', 4)
            ->assertJsonPath('data.abilities', ['view_members' => false, 'leave' => true, 'manage' => false, 'manage_codes' => false, 'manage_members' => false, 'upload_photos' => false])
            ->assertJsonCount(3, 'data.packs_preview')
            ->assertJsonPath('data.competitions_preview', ['active' => [], 'upcoming' => [], 'completed_count' => 0])
            ->assertJsonPath('data.leaderboard_preview', ['top' => [], 'me' => null]);

        $this->assertSame([
            'id', 'name', 'slug', 'logo_url', 'city', 'country_code', 'club_type', 'sport', 'role', 'status', 'packs_count',
            'competitions', 'joined_at', 'description', 'region', 'members_count', 'abilities', 'packs_preview',
            'competitions_preview', 'leaderboard_preview',
        ], array_keys($res->json('data')));

        $body = $res->getContent();
        $this->assertStringNotContainsString('secret@example.test', $body);
        $this->assertStringNotContainsString('Secret Person', $body);
        $this->assertStringNotContainsString('"club_id"', $body);

        // The sole owner may not leave but may manage and see members.
        $this->api($owner)->getJson($this->clubPath($club))->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.abilities', ['view_members' => true, 'leave' => false, 'manage' => true, 'manage_codes' => true, 'manage_members' => true, 'upload_photos' => true]);
    }

    public function test_opening_a_club_refreshes_last_seen_for_the_recent_sort(): void
    {
        $user = User::factory()->create();
        $old  = $this->makeClub(['name' => 'Old']);
        $new  = $this->makeClub(['name' => 'New']);
        $this->addMember($old, $user)->forceFill(['last_seen_at' => now()->subDays(5)])->save();
        $this->addMember($new, $user)->forceFill(['last_seen_at' => now()->subDay()])->save();

        $this->assertSame('New', $this->api($user)->getJson('/api/clubs')->json('data.0.name'));
        $this->api($user)->getJson($this->clubPath($old))->assertOk();
        $this->assertSame('Old', $this->api($user)->getJson('/api/clubs')->json('data.0.name'));
    }

    public function test_competitions_preview_shows_published_phases_only_with_my_standing(): void
    {
        $me    = User::factory()->create();
        $rival = User::factory()->create();
        $club  = $this->makeClub([], $me);
        $this->addMember($club, $rival);
        $pack = $this->makeClubPack($club, 1);

        $live = $this->makeCompetition($club, [$pack], [
            'name' => 'Live Cup', 'state' => CompetitionState::Published, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
            'replay_mode' => ReplayMode::MultipleAttempts, 'max_attempts' => 3, 'score_rule' => ScoreRule::Best,
        ]);
        foreach (range(1, 4) as $i) {
            $this->makeCompetition($club, [$pack], ['name' => "Next {$i}", 'state' => CompetitionState::Published,
                'starts_at' => now()->addDays($i), 'ends_at' => now()->addDays($i + 1)]);
        }
        $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Draft]);
        $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Cancelled, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Voided, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Published, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subHour()]);
        $this->makeCompetition($club, [$pack], ['state' => CompetitionState::Finalized, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

        ClubCompetitionEntry::create(['club_competition_id' => $live->id, 'user_id' => $rival->id, 'total_score' => 300, 'packs_completed' => 1,
            'attempts_used' => 1, 'score_reached_at' => now()->subMinutes(30)]);
        ClubCompetitionEntry::create(['club_competition_id' => $live->id, 'user_id' => $me->id, 'total_score' => 200, 'packs_completed' => 1,
            'attempts_used' => 1, 'score_reached_at' => now()->subMinutes(40)]);

        $preview = $this->api($me)->getJson($this->clubPath($club))->assertOk()->json('data.competitions_preview');

        $this->assertCount(1, $preview['active']);
        $this->assertSame([
            'id' => $live->public_id, 'name' => 'Live Cup', 'description' => $live->description, 'phase' => 'active',
            'starts_at' => $live->starts_at->toISOString(), 'ends_at' => $live->ends_at->toISOString(), 'timezone' => $live->timezone,
            'rules' => ['replay_mode' => 'multiple_attempts', 'max_attempts' => 3, 'score_rule' => 'best', 'normal_play_policy' => 'blocked'],
            'packs_count' => 1, 'participants_count' => 0,
            'me' => ['rank' => 2, 'total_score' => 200, 'packs_completed' => 1],
        ], $preview['active'][0]);
        $this->assertSame(['Next 1', 'Next 2', 'Next 3'], array_column($preview['upcoming'], 'name'));
        $this->assertSame('upcoming', $preview['upcoming'][0]['phase']);
        $this->assertNull($preview['upcoming'][0]['me']);
        $this->assertSame(2, $preview['completed_count']);

        // A removed rival no longer ranks above me (D22).
        ClubMember::where('user_id', $rival->id)->update(['status' => ClubMemberStatus::Removed->value]);
        $this->api($me)->getJson($this->clubPath($club))->assertOk()->assertJsonPath('data.competitions_preview.active.0.me.rank', 1);
    }

    // ------------------------------------------------------------ members (D9)

    public function test_member_list_is_hidden_from_ordinary_members_by_default(): void
    {
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $club   = $this->makeClub([], $owner);
        $this->addMember($club, $member);

        $this->api($member)->getJson($this->clubPath($club, '/members'))
            ->assertForbidden()
            ->assertExactJson(['message' => __('clubs.errors.club_forbidden'), 'code' => 'club_forbidden']);

        $this->api($owner)->getJson($this->clubPath($club, '/members'))->assertOk()->assertJsonCount(2, 'data');

        $club->update(['settings' => ['member_list_visibility' => 'members']]);
        $this->api($member)->getJson($this->clubPath($club, '/members'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_member_list_shape_order_search_and_paging(): void
    {
        $owner = User::factory()->create(['username' => 'zed-owner', 'email' => 'owner@private.test']);
        $club  = $this->makeClub([], $owner);
        $admin = User::factory()->create(['username' => 'yann-admin']);
        $this->addMember($club, $admin, ClubRole::Admin);
        foreach (['carla', 'bram', 'anouk'] as $name) {
            $this->addMember($club, User::factory()->create(['username' => $name]));
        }
        $this->addMember($club, User::factory()->create(['username' => 'left-one']), ClubRole::Member, ClubMemberStatus::Left);

        $res = $this->api($admin)->getJson($this->clubPath($club, '/members'))->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.next_cursor', null);
        $this->assertSame(['zed-owner', 'yann-admin', 'anouk', 'bram', 'carla'], array_column($res->json('data'), 'username'));
        $this->assertSame(['user_id', 'username', 'avatar_url', 'role', 'joined_at', 'actions'], array_keys($res->json('data.0')));
        $this->assertSame('owner', $res->json('data.0.role'));
        $this->assertStringNotContainsString('owner@private.test', $res->getContent());

        $this->api($admin)->getJson($this->clubPath($club, '/members?q=BR'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', 'bram')->assertJsonPath('meta.total', 5);

        $page1 = $this->api($admin)->getJson($this->clubPath($club, '/members?limit=2'))->assertOk();
        $page2 = $this->api($admin)->getJson($this->clubPath($club, '/members?limit=2&cursor=' . urlencode($page1->json('meta.next_cursor'))))->assertOk();
        $page3 = $this->api($admin)->getJson($this->clubPath($club, '/members?limit=2&cursor=' . urlencode($page2->json('meta.next_cursor'))))->assertOk();
        $this->assertSame(
            ['zed-owner', 'yann-admin', 'anouk', 'bram', 'carla'],
            array_merge(array_column($page1->json('data'), 'username'), array_column($page2->json('data'), 'username'), array_column($page3->json('data'), 'username')),
        );
        $this->assertNull($page3->json('meta.next_cursor'));
    }
}
