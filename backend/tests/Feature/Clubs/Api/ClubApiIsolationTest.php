<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\PackAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Tenant isolation of the member API (plan §11.1): AT-ISO-04 (every member
 * route answers 404 club_not_found to outsiders, members of another club,
 * left/removed/banned users and non-member platform admins), AT-ISO-05
 * (another club's pack under this club → 404), AT-ISO-07 (guess re-checks
 * access), unknown ids and the feature flag.
 */
class ClubApiIsolationTest extends ClubApiTestCase
{
    /** Every member route of club A, with a pack slug of club A where needed. */
    private function memberRoutes(Club $club, ChallengePack $pack): array
    {
        return [
            'show'          => ['GET', $this->clubPath($club)],
            'leave'         => ['POST', $this->clubPath($club, '/leave')],
            'members'       => ['GET', $this->clubPath($club, '/members')],
            'packs'         => ['GET', $this->clubPath($club, '/packs')],
            'pack detail'   => ['GET', $this->packPath($club, $pack)],
            'pack start'    => ['POST', $this->packPath($club, $pack, '/start')],
            'pack attempt'  => ['GET', $this->packPath($club, $pack, '/attempt')],
            'leaderboard'   => ['GET', $this->clubPath($club, '/leaderboard')],
            // Wave F (club-admin API + reports): non-members get the same 404 before any validation.
            'codes'         => ['GET', $this->clubPath($club, '/invite-codes')],
            'code create'   => ['POST', $this->clubPath($club, '/invite-codes')],
            'code revoke'   => ['POST', $this->clubPath($club, '/invite-codes/999999/revoke')],
            'member role'   => ['PATCH', $this->clubPath($club, '/members/999999')],
            'member remove' => ['POST', $this->clubPath($club, '/members/999999/remove')],
            'member ban'    => ['POST', $this->clubPath($club, '/members/999999/ban')],
            'photos'        => ['GET', $this->clubPath($club, '/photos')],
            'photo upload'  => ['POST', $this->clubPath($club, '/photos')],
            'report'        => ['POST', $this->clubPath($club, '/reports')],
        ];
    }

    public function test_every_registered_member_route_is_covered_by_the_matrix(): void
    {
        $uris = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.clubs.') && str_contains($r->uri(), '{club'))
            ->filter(fn ($r) => !str_contains($r->uri(), 'competitions')) // Wave C routes have their own matrix
            ->map(fn ($r) => $r->uri())
            ->sort()->values()->all();

        $this->assertSame([
            'api/clubs/{club}',
            'api/clubs/{club}/invite-codes',
            'api/clubs/{club}/invite-codes',
            'api/clubs/{club}/invite-codes/{inviteCode}/revoke',
            'api/clubs/{club}/leaderboard',
            'api/clubs/{club}/leave',
            'api/clubs/{club}/members',
            'api/clubs/{club}/members/{userId}',
            'api/clubs/{club}/members/{userId}/ban',
            'api/clubs/{club}/members/{userId}/remove',
            'api/clubs/{club}/packs',
            'api/clubs/{club}/packs/{pack}',
            'api/clubs/{club}/packs/{pack}/attempt',
            'api/clubs/{club}/packs/{pack}/start',
            'api/clubs/{club}/photos',
            'api/clubs/{club}/photos',
            'api/clubs/{club}/reports',
        ], $uris);

        foreach (collect(Route::getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.clubs.')) as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('feature:clubs', $middleware, $route->uri());
            $this->assertContains('verified', $middleware, $route->uri());
            $this->assertContains('auth:sanctum', $middleware, $route->uri());
        }
    }

    // ------------------------------------------------------------ AT-ISO-04

    public function test_non_members_get_404_club_not_found_on_every_member_route(): void
    {
        $clubA = $this->makeClub(['settings' => ['member_list_visibility' => 'members']], User::factory()->create());
        $clubB = $this->makeClub([], User::factory()->create());
        $packA = $this->makeClubPack($clubA, 2);
        $this->makeClubPack($clubB, 2);

        $outsider = User::factory()->create();
        $memberB  = User::factory()->create();
        $this->addMember($clubB, $memberB);
        $left    = User::factory()->create();
        $this->addMember($clubA, $left, ClubRole::Member, ClubMemberStatus::Left);
        $removed = User::factory()->create();
        $this->addMember($clubA, $removed, ClubRole::Member, ClubMemberStatus::Removed);
        $banned  = User::factory()->create();
        $this->addMember($clubA, $banned, ClubRole::Admin, ClubMemberStatus::Banned);
        $admin   = User::factory()->create(['is_admin' => true]);

        $actors = compact('outsider', 'memberB', 'left', 'removed', 'banned', 'admin');

        foreach ($actors as $label => $actor) {
            foreach ($this->memberRoutes($clubA, $packA) as $route => [$method, $uri]) {
                $this->api($actor)->json($method, $uri)
                    ->assertNotFound()
                    ->assertExactJson(['message' => __('clubs.errors.club_not_found'), 'code' => 'club_not_found']);
            }
        }

        // Nothing was written by any of those requests.
        $this->assertSame(0, PackAttempt::count());
        $this->assertSame(ClubMemberStatus::Left, $clubA->members()->where('user_id', $left->id)->first()->status);
    }

    public function test_archived_clubs_are_404_even_for_their_members(): void
    {
        $member = User::factory()->create();
        $club   = $this->makeClub([], User::factory()->create());
        $this->addMember($club, $member);
        $pack = $this->makeClubPack($club, 1);
        $club->update(['status' => ClubStatus::Archived]);

        foreach ($this->memberRoutes($club, $pack) as [$method, $uri]) {
            $this->api($member)->json($method, $uri)->assertNotFound()->assertJsonPath('code', 'club_not_found');
        }
    }

    public function test_unknown_club_ids_answer_exactly_like_a_foreign_club(): void
    {
        $user    = User::factory()->create();
        $foreign = $this->makeClub([], User::factory()->create());

        $unknown = $this->api($user)->getJson('/api/clubs/01JBUNKNOWNCLUBID000000000')->assertNotFound();
        $hidden  = $this->api($user)->getJson($this->clubPath($foreign))->assertNotFound();

        $this->assertSame($hidden->json(), $unknown->json());
        $this->assertSame('club_not_found', $unknown->json('code'));
        // The internal id and the slug never address a club on the member API.
        $this->api($user)->getJson('/api/clubs/' . $foreign->id)->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->api($user)->getJson('/api/clubs/' . $foreign->slug)->assertNotFound()->assertJsonPath('code', 'club_not_found');
    }

    // ------------------------------------------------------------ AT-ISO-05

    public function test_a_pack_of_another_club_or_a_public_pack_under_this_club_is_404(): void
    {
        $member = User::factory()->create();
        $clubA  = $this->makeClub([], User::factory()->create());
        $clubB  = $this->makeClub([], User::factory()->create());
        $this->addMember($clubA, $member);
        $this->addMember($clubB, $member);
        $packB  = $this->makeClubPack($clubB, 2);
        $public = $this->makePublicPack(2);

        foreach ([$packB, $public] as $pack) {
            foreach (['', '/start', '/attempt'] as $suffix) {
                $method = $suffix === '/start' ? 'POST' : 'GET';
                $this->api($member)->json($method, $this->packPath($clubA, $pack, $suffix))
                    ->assertNotFound()
                    ->assertJsonPath('code', 'club_not_found');
            }
        }

        // The same pack works under its own club.
        $this->api($member)->getJson($this->packPath($clubB, $packB))->assertOk()->assertJsonPath('data.slug', $packB->slug);
        $this->assertSame(0, PackAttempt::count());
    }

    public function test_draft_hidden_and_archived_club_packs_are_404_for_members(): void
    {
        $member = User::factory()->create();
        $club   = $this->makeClub([], $member);
        $draft    = $this->makeClubPack($club, 1, ['status' => ChallengePack::STATUS_DRAFT]);
        $hidden   = $this->makeClubPack($club, 1, ['visibility' => ChallengePack::VISIBILITY_HIDDEN]);
        $archived = $this->makeClubPack($club, 1, ['status' => ChallengePack::STATUS_ARCHIVED]);

        foreach ([$draft, $hidden, $archived] as $pack) {
            $this->api($member)->getJson($this->packPath($club, $pack))->assertNotFound()->assertJsonPath('code', 'club_not_found');
            $this->api($member)->postJson($this->packPath($club, $pack, '/start'))->assertNotFound();
        }
        $this->api($member)->getJson($this->clubPath($club, '/packs'))->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ AT-ISO-07

    public function test_a_removed_member_or_a_suspended_club_cannot_continue_an_active_attempt(): void
    {
        $member = User::factory()->create();
        $club   = $this->makeClub([], User::factory()->create());
        $this->addMember($club, $member);
        $pack = $this->makeClubPack($club, 3);

        $attemptId = $this->startClubPack($member, $club, $pack)->assertOk()->json('attempt.id');
        $this->guessCurrent($member, $attemptId)->assertOk()->assertJsonPath('progress.completed_count', 1);

        $club->update(['status' => ClubStatus::Suspended]);
        $this->guessCurrent($member, $attemptId)->assertStatus(423)->assertJsonPath('code', 'club_unavailable');

        $club->update(['status' => ClubStatus::Active]);
        $this->addMember($club, $member, ClubRole::Member, ClubMemberStatus::Removed);
        $this->guessCurrent($member, $attemptId)->assertNotFound()->assertJsonPath('code', 'club_not_found');

        $this->assertSame(1, PackAttempt::find($attemptId)->guesses()->count());
        $this->assertSame(1, PackAttempt::find($attemptId)->current_index);
    }

    public function test_another_user_cannot_guess_on_my_club_attempt(): void
    {
        $member = User::factory()->create();
        $other  = User::factory()->create();
        $club   = $this->makeClub([], $member);
        $this->addMember($club, $other);
        $pack = $this->makeClubPack($club, 2);

        $attemptId = $this->startClubPack($member, $club, $pack)->assertOk()->json('attempt.id');

        $this->guessCurrent($other, $attemptId)->assertForbidden();
        $this->assertSame(0, PackAttempt::find($attemptId)->guesses()->count());
    }

    // ------------------------------------------------------------ feature flag

    public function test_feature_flag_off_answers_404_feature_disabled_everywhere(): void
    {
        $member = User::factory()->create();
        $club   = $this->makeClub([], $member);
        $pack   = $this->makeClubPack($club, 1);
        $this->enableClubs(false);

        $routes = $this->memberRoutes($club, $pack) + [
            'my clubs'      => ['GET', '/api/clubs'],
            'join'          => ['POST', '/api/clubs/join'],
            'unknown club'  => ['GET', '/api/clubs/01JBUNKNOWNCLUBID000000000'],
            'unknown pack'  => ['GET', $this->clubPath($club, '/packs/nope')],
        ];

        foreach ($routes as $label => [$method, $uri]) {
            $this->api($member)->json($method, $uri, ['code' => 'ABCDEFGH'])
                ->assertNotFound()
                ->assertExactJson(['message' => __('clubs.errors.feature_disabled'), 'code' => 'feature_disabled']);
        }

        $this->assertSame(0, PackAttempt::count());
        $this->assertTrue($club->members()->where('user_id', $member->id)->first()->isActive());
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $club = $this->makeClub([], User::factory()->create());

        $this->getJson('/api/clubs')->assertUnauthorized();
        $this->getJson($this->clubPath($club))->assertUnauthorized();
        $this->postJson('/api/clubs/join', ['code' => 'ABCDEFGH'])->assertUnauthorized();
    }
}
