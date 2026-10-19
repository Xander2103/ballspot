<?php

namespace Tests\Concerns;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionPack;
use App\Models\ClubMember;
use App\Models\Sport;
use App\Models\User;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the Clubs test suites (contracts §3.1).
 *
 * Rows are written directly (factories), not through the services, so a
 * fixture never adds audit events or touches limiter counters; counters are
 * refreshed so members_count / needs_owner match. Use the services in the
 * test body when the behaviour under test IS the service.
 */
trait CreatesClubs
{
    /** Turn the Clubs feature flag on for this test. */
    protected function enableClubs(bool $on = true): void
    {
        config(['ballspot.clubs.enabled' => $on]);
    }

    protected function makeClub(array $attrs = [], ?User $owner = null): Club
    {
        $club = Club::factory()->create($attrs);

        if ($owner) {
            $this->addMember($club, $owner, ClubRole::Owner);
        } else {
            app(ClubMembershipService::class)->refreshCounters($club);
        }

        return $club->refresh();
    }

    protected function addMember(Club $club, User $user, ClubRole $role = ClubRole::Member, ClubMemberStatus $status = ClubMemberStatus::Active): ClubMember
    {
        $member = ClubMember::updateOrCreate(
            ['club_id' => $club->id, 'user_id' => $user->id],
            [
                'role'         => $role,
                'status'       => $status,
                'joined_at'    => now(),
                'left_at'      => $status === ClubMemberStatus::Active ? null : now(),
                'last_seen_at' => now(),
            ],
        );

        app(ClubMembershipService::class)->refreshCounters($club);
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();

        return $member;
    }

    protected function clubSport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'emoji' => '⚽', 'status' => 'active']);
    }

    /** A ready, active club challenge (private-disk style paths, usage_pool club). */
    protected function makeClubChallenge(Club $club, array $attrs = []): Challenge
    {
        $suffix = Str::random(40);

        return Challenge::create(array_merge([
            'sport_id'            => $this->clubSport()->id,
            'title'               => 'Club challenge ' . Str::random(6),
            'hidden_image_path'   => $club->id . '/challenges/hidden/' . $suffix . '.jpg',
            'original_image_path' => $club->id . '/challenges/original/' . $suffix . '.jpg',
            'ball_x_ratio'        => 0.5,
            'ball_y_ratio'        => 0.5,
            'difficulty'          => 'easy',
            'status'              => 'active',
            'usage_pool'          => Challenge::POOL_CLUB,
            'club_id'             => $club->id,
        ], $attrs));
    }

    /** A ready, active BallPicker (public) challenge. */
    protected function makePublicChallenge(array $attrs = []): Challenge
    {
        return Challenge::create(array_merge([
            'sport_id'            => $this->clubSport()->id,
            'title'               => 'Public challenge ' . Str::random(6),
            'hidden_image_path'   => 'challenges/hidden/' . Str::random(10) . '.jpg',
            'original_image_path' => 'challenges/original/' . Str::random(10) . '.jpg',
            'ball_x_ratio'        => 0.5,
            'ball_y_ratio'        => 0.5,
            'difficulty'          => 'easy',
            'status'              => 'active',
        ], $attrs));
    }

    /** An active club pack with N ready club challenges. */
    protected function makeClubPack(Club $club, int $challenges = 3, array $attrs = []): ChallengePack
    {
        $pack = ChallengePack::create(array_merge([
            'name'       => 'Club pack ' . Str::random(5),
            'slug'       => 'club-pack-' . Str::lower(Str::random(8)),
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id'   => $this->clubSport()->id,
            'club_id'    => $club->id,
        ], $attrs));

        for ($i = 1; $i <= $challenges; $i++) {
            $pack->challenges()->attach($this->makeClubChallenge($club)->id, ['sort_order' => $i]);
        }

        app(ClubMembershipService::class)->refreshCounters($club);

        return $pack;
    }

    /** An active + public BallPicker pack with N ready public challenges. */
    protected function makePublicPack(int $challenges = 3, array $attrs = []): ChallengePack
    {
        $pack = ChallengePack::create(array_merge([
            'name'       => 'Public pack ' . Str::random(5),
            'slug'       => 'public-pack-' . Str::lower(Str::random(8)),
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id'   => $this->clubSport()->id,
        ], $attrs));

        for ($i = 1; $i <= $challenges; $i++) {
            $pack->challenges()->attach($this->makePublicChallenge()->id, ['sort_order' => $i]);
        }

        return $pack;
    }

    /**
     * A competition of the club linked to the given packs (in order). Default
     * state: draft starting tomorrow; pass e.g. ['state' => 'published',
     * 'starts_at' => now()->subHour()] or use the factory states.
     *
     * @param array<int, ChallengePack> $packs
     */
    protected function makeCompetition(Club $club, array $packs, array $attrs = []): ClubCompetition
    {
        $competition = ClubCompetition::factory()->create(array_merge(['club_id' => $club->id], $attrs));

        foreach (array_values($packs) as $i => $pack) {
            ClubCompetitionPack::create([
                'club_competition_id' => $competition->id,
                'challenge_pack_id'   => $pack->id,
                'sort_order'          => $i,
            ]);
        }

        return $competition;
    }

    /** A Sanctum bearer token for the user (use with actingWithToken()). */
    protected function clubToken(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
