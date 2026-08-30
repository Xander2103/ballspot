<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveMembershipLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT_MSG = 'You can only be in two active tournaments at the same time.';

    private function actingAsUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, ['Authorization' => "Bearer $token"]];
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    /** Create a league owned by $owner with given status; optionally add $member. */
    private function league(User $owner, string $status, ?User $member = null, ?string $code = null): League
    {
        $league = League::create([
            'name'           => 'L-' . uniqid(),
            'join_code'      => $code ?? strtoupper(substr(md5(uniqid()), 0, 6)),
            'owner_user_id'  => $owner->id,
            'sport_id'       => $this->sport()->id,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => $status,
        ]);
        $league->members()->attach($owner->id, ['joined_at' => now()]);
        if ($member) {
            $league->members()->attach($member->id, ['joined_at' => now()]);
        }
        return $league;
    }

    public function test_cannot_join_third_active_tournament(): void
    {
        $owner = User::factory()->create();
        [$me, $headers] = $this->actingAsUser();

        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'active', $me);
        $this->league($owner, 'lobby', null, 'JOINME');

        $this->postJson('/api/leagues/join', ['join_code' => 'JOINME'], $headers)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => self::LIMIT_MSG]);
    }

    public function test_cannot_create_when_already_in_two_tournaments(): void
    {
        $owner = User::factory()->create();
        [$me, $headers] = $this->actingAsUser();

        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'active', $me);

        $this->postJson('/api/leagues', [
            'name' => 'Mine', 'duration_days' => 7,
        ], $headers)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => self::LIMIT_MSG]);
    }

    public function test_completed_and_cancelled_do_not_count(): void
    {
        $owner = User::factory()->create();
        [$me, $headers] = $this->actingAsUser();

        $this->league($owner, 'completed', $me);
        $this->league($owner, 'cancelled', $me);
        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'lobby', null, 'ROOMOK');

        $this->postJson('/api/leagues/join', ['join_code' => 'ROOMOK'], $headers)
            ->assertOk();
    }

    public function test_hidden_completed_tournament_does_not_count(): void
    {
        $owner = User::factory()->create();
        [$me, $headers] = $this->actingAsUser();

        $done = $this->league($owner, 'completed', $me);
        $done->members()->updateExistingPivot($me->id, ['hidden_at' => now()]);
        $this->league($owner, 'lobby', $me);
        $this->league($owner, 'lobby', null, 'ROOMOK');

        $this->postJson('/api/leagues/join', ['join_code' => 'ROOMOK'], $headers)
            ->assertOk();
    }

    public function test_hosted_tournament_counts_toward_membership_limit(): void
    {
        $this->sport();
        $owner = User::factory()->create();
        [$me, $headers] = $this->actingAsUser();

        // I host one (auto-membership) + I'm a member of another = 2.
        $this->postJson('/api/leagues', [
            'name' => 'Hosted', 'duration_days' => 7,
        ], $headers)->assertStatus(201);
        $this->league($owner, 'active', $me);
        $this->league($owner, 'lobby', null, 'THIRDX');

        $this->postJson('/api/leagues/join', ['join_code' => 'THIRDX'], $headers)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => self::LIMIT_MSG]);
    }

    public function test_rejoining_own_lobby_is_not_blocked(): void
    {
        $other = User::factory()->create();
        [$owner, $headers] = $this->actingAsUser();

        // Idempotent re-join of a league I'm already in must not 422,
        // even while at the membership limit.
        $this->league($owner, 'lobby', null, 'MINE01');
        $this->league($other, 'active', $owner);

        $this->postJson('/api/leagues/join', ['join_code' => 'MINE01'], $headers)
            ->assertOk();
    }
}
