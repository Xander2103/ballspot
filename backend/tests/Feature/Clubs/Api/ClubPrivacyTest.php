<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\ClubPackRewardClaim;
use App\Models\ClubPhotoSubmission;
use App\Models\ClubPortalLoginCode;
use App\Models\PackAttempt;
use App\Models\User;

/** Account deletion (AT-PRV-01) and data export (AT-PRV-02) for Clubs. */
class ClubPrivacyTest extends ClubApiTestCase
{
    private function photo(int $clubId, ?int $uploaderId, array $attrs = []): ClubPhotoSubmission
    {
        return ClubPhotoSubmission::create(array_merge([
            'club_id' => $clubId, 'uploaded_by_user_id' => $uploaderId, 'source' => 'club_upload',
            'disk' => 'club_private', 'path' => $clubId . '/photos/' . str_repeat('a', 40) . '.jpg',
            'mime' => 'image/jpeg', 'width' => 1200, 'height' => 800, 'bytes' => 123456,
            'sha256' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'status' => 'pending',
            'club_note' => 'derby goal', 'admin_note' => 'internal admin note',
        ], $attrs));
    }

    // ------------------------------------------------------------ AT-PRV-01

    public function test_account_deletion_removes_memberships_nulls_creator_and_uploader_and_keeps_history(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $soleOwned = $this->makeClub([], $user);
        $joined    = $this->makeClub([], $other);
        $this->addMember($joined, $user, ClubRole::Admin);
        $bannedIn  = $this->makeClub([], $other);
        $this->addMember($bannedIn, $user, ClubRole::Member, ClubMemberStatus::Banned);

        $pack = $this->makeClubPack($joined, 1);
        $attemptId = $this->startClubPack($user, $joined, $pack)->assertOk()->json('attempt.id');
        $this->guessCurrent($user, $attemptId)->assertOk();

        $competition = $this->makeCompetition($joined, [$pack], ['state' => CompetitionState::Finalized, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $compAttempt = ClubCompetitionAttempt::create(['club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id,
            'user_id' => $user->id, 'attempt_number' => 1, 'status' => CompetitionAttemptStatus::Completed, 'active_slot' => null,
            'challenge_ids' => [], 'total_score' => 77, 'started_at' => now()->subDays(2)]);
        ClubCompetitionFinish::create(['club_competition_id' => $competition->id, 'user_id' => $user->id, 'placement' => 1,
            'total_score' => 77, 'total_players' => 3]);

        $code   = ClubInviteCode::factory()->create(['club_id' => $joined->id, 'created_by_user_id' => $user->id]);
        $photo  = $this->photo($joined->id, $user->id);
        ClubPortalLoginCode::create(['challenge_id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
            'code_hash' => bcrypt('123456'), 'sent_at' => now(), 'expires_at' => now()->addMinutes(10)]);

        $this->api($user)->deleteJson('/api/account')->assertSuccessful();
        $this->assertSame(0, ClubPortalLoginCode::where('user_id', $user->id)->count(), 'pending portal sign-in codes die with the account');

        // Memberships → removed (banned stays banned), counters + needs_owner (D6).
        $this->assertSame(ClubMemberStatus::Removed, ClubMember::where('club_id', $soleOwned->id)->where('user_id', $user->id)->first()->status);
        $this->assertSame(ClubMemberStatus::Removed, ClubMember::where('club_id', $joined->id)->where('user_id', $user->id)->first()->status);
        $this->assertSame(ClubMemberStatus::Banned, ClubMember::where('club_id', $bannedIn->id)->where('user_id', $user->id)->first()->status);
        $this->assertTrue($soleOwned->fresh()->needs_owner);
        $this->assertSame(0, $soleOwned->fresh()->members_count);
        $this->assertSame(1, $joined->fresh()->members_count);
        $this->assertTrue(ClubAuditEvent::where('club_id', $joined->id)->where('action', 'member.removed')->exists());

        // The code keeps working without its creator; the uploader is gone.
        $this->assertNull($code->fresh()->created_by_user_id);
        $this->assertNull($code->fresh()->revoked_at);
        $this->assertNull($photo->fresh()->uploaded_by_user_id);

        // Gameplay history is kept against the anonymized row.
        $this->assertTrue(PackAttempt::whereKey($attemptId)->where('user_id', $user->id)->exists());
        $this->assertTrue(ClubCompetitionAttempt::whereKey($compAttempt->id)->where('user_id', $user->id)->exists());
        $this->assertTrue(ClubPackRewardClaim::where('user_id', $user->id)->exists());
        $this->assertSame('Deleted User', ClubCompetitionFinish::sole()->user->name);

        // Gone from the club's member list and leaderboard.
        $members = $this->api($other)->getJson($this->clubPath($joined, '/members'))->assertOk()->json('data');
        $this->assertNotContains($user->id, array_column($members, 'user_id'));
        $board = $this->api($other)->getJson($this->clubPath($joined, '/leaderboard'))->assertOk()->json('data');
        $this->assertNotContains($user->id, array_column($board, 'user_id'));
    }

    public function test_the_public_profile_never_shows_club_data(): void
    {
        $user   = User::factory()->create();
        $viewer = User::factory()->create();
        $club   = $this->makeClub(['name' => 'Secret Club Name'], $user);
        $pack   = $this->makeClubPack($club, 1, ['name' => 'Secret Pack Name']);
        $this->playClubPack($user, $club, $pack);

        $body = $this->api($viewer)->getJson("/api/users/{$user->id}/public-profile")->assertOk()->getContent();
        $this->assertStringNotContainsString('Secret Club Name', $body);
        $this->assertStringNotContainsString('Secret Pack Name', $body);
        $this->assertStringNotContainsString($club->public_id, $body);
    }

    // ------------------------------------------------------------ AT-PRV-02

    public function test_export_contains_own_club_data_but_never_other_members_or_codes(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create(['email' => 'other-member@private.test', 'username' => 'othermember']);
        $club  = $this->makeClub(['name' => 'Export FC', 'contact_email' => 'contact@club.test'], $other);
        $this->addMember($club, $user, ClubRole::Admin);
        $left  = $this->makeClub(['name' => 'Left FC'], $other);
        $this->addMember($left, $user, ClubRole::Member, ClubMemberStatus::Left);
        $code  = ClubInviteCode::factory()->create(['club_id' => $club->id, 'code' => 'XPRT2345', 'created_by_user_id' => $user->id]);

        $pack = $this->makeClubPack($club, 1, ['name' => 'Export Pack']);
        $competition = $this->makeCompetition($club, [$pack], ['name' => 'Export Cup', 'state' => CompetitionState::Finalized,
            'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        ClubCompetitionAttempt::create(['club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id,
            'user_id' => $user->id, 'attempt_number' => 1, 'status' => CompetitionAttemptStatus::Completed, 'active_slot' => null,
            'challenge_ids' => [], 'guesses_count' => 1, 'total_score' => 88, 'started_at' => now()->subDays(2)]);
        ClubCompetitionAttempt::create(['club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id,
            'user_id' => $other->id, 'attempt_number' => 1, 'status' => CompetitionAttemptStatus::Completed, 'active_slot' => null,
            'challenge_ids' => [], 'guesses_count' => 1, 'total_score' => 99, 'started_at' => now()->subDays(2)]);
        ClubCompetitionFinish::create(['club_competition_id' => $competition->id, 'user_id' => $user->id, 'placement' => 2,
            'total_score' => 88, 'total_players' => 2]);
        $this->photo($club->id, $user->id);
        $this->photo($club->id, $other->id, ['club_note' => 'not mine']);
        $this->playClubPack($user, $club, $pack);

        $res = $this->api($user)->getJson('/api/me/export')->assertOk();
        $clubs = $res->json('clubs');

        $this->assertEqualsCanonicalizing(['Export FC', 'Left FC'], array_column(array_column($clubs['memberships'], 'club'), 'name'));
        $byName = collect($clubs['memberships'])->keyBy('club.name');
        $this->assertSame('admin', $byName['Export FC']['role']);
        $this->assertSame('active', $byName['Export FC']['status']);
        $this->assertSame('left', $byName['Left FC']['status']);
        $this->assertNotNull($byName['Export FC']['joined_at']);
        $this->assertSame($club->public_id, $byName['Export FC']['club']['id']);

        $this->assertSame(['count' => 1, 'completed' => 1, 'total_score' => 88], $clubs['competition_attempts_summary']);
        $this->assertCount(1, $clubs['competition_attempts']);
        $this->assertSame('Export Cup', $clubs['competition_attempts'][0]['competition']['name']);
        $this->assertSame('Export Pack', $clubs['competition_attempts'][0]['pack']);
        $this->assertSame([['competition' => 'Export Cup', 'placement' => 2, 'total_score' => 88, 'total_players' => 2]], $clubs['competition_finishes']);
        $this->assertSame('Export Pack', $clubs['pack_reward_claims'][0]['pack']);

        $this->assertCount(1, $clubs['photo_submissions']);
        $this->assertSame('derby goal', $clubs['photo_submissions'][0]['club_note']);
        $this->assertSame('pending', $clubs['photo_submissions'][0]['status']);

        $body = $res->getContent();
        foreach (['other-member@private.test', 'othermember', 'contact@club.test', 'XPRT2345', 'XPRT-2345', 'internal admin note', 'not mine', '/photos/'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "export leaks {$secret}");
        }
        $this->assertNotNull($code);
    }
}
