<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionPhase;
use App\Enums\CompetitionState;
use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use App\Models\Badge;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionAttemptGuess;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubCompetitionPack;
use App\Models\ClubCompetitionPackResult;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\ClubPackRewardClaim;
use App\Models\ClubPhotoSubmission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Clubs schema (plan §4): every table/column exists, the DB-level guards
 * hold (they are the real guard on SQLite, where row locks are no-ops), the
 * factories build every model, and the model derivations behave.
 */
class ClubSchemaAndModelsTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    public function test_every_club_table_exists_with_its_key_columns(): void
    {
        $expected = [
            'clubs'                            => ['public_id', 'slug', 'status', 'join_enabled', 'settings', 'needs_owner', 'members_count', 'version', 'timezone'],
            'club_members'                     => ['club_id', 'user_id', 'role', 'status', 'invite_code_id', 'removal_reason', 'last_seen_at'],
            'club_invite_codes'                => ['club_id', 'code', 'grants_role', 'max_uses', 'uses_count', 'expires_at', 'revoked_at'],
            'club_competitions'                => ['public_id', 'state', 'starts_at', 'ends_at', 'replay_mode', 'max_attempts', 'score_rule', 'tie_break', 'normal_play_policy', 'rules_locked_at', 'version'],
            'club_competition_packs'           => ['club_competition_id', 'challenge_pack_id', 'sort_order', 'challenge_ids', 'snapshotted_at'],
            'club_competition_attempts'        => ['attempt_number', 'status', 'active_slot', 'challenge_ids', 'total_score', 'is_rewarded_run', 'last_scored_at', 'void_reason'],
            'club_competition_attempt_guesses' => ['attempt_id', 'challenge_id', 'position', 'guess_x', 'guess_y', 'distance', 'score', 'submitted_at'],
            'club_competition_pack_results'    => ['attempts_used', 'counted_attempt_id', 'counted_score', 'best_score', 'first_score', 'completed', 'score_reached_at'],
            'club_competition_entries'         => ['total_score', 'packs_counted', 'packs_completed', 'attempts_used', 'score_reached_at'],
            'club_competition_finishes'        => ['placement', 'total_score', 'total_players', 'metadata'],
            'club_pack_reward_claims'          => ['user_id', 'challenge_pack_id', 'source_type', 'source_id', 'claimed_at'],
            'club_photo_submissions'           => ['disk', 'path', 'sha256', 'status', 'rejection_reason', 'challenge_id', 'rights_confirmed_at'],
            'club_audit_events'                => ['club_id', 'actor_user_id', 'actor_kind', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_hash', 'created_at'],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "missing table {$table}");
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "missing {$table}.{$column}");
            }
        }

        foreach (['challenge_packs', 'challenges', 'badges'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'club_id'), "missing {$table}.club_id");
        }
        $this->assertTrue(Schema::hasIndex('pack_attempts', ['challenge_pack_id', 'status']));
        $this->assertFalse(Schema::hasColumn('club_audit_events', 'updated_at'), 'audit rows are append-only');
    }

    public function test_existing_content_rows_default_to_ballpicker_content(): void
    {
        $pack = $this->makePublicPack(1);

        $this->assertNull($pack->fresh()->club_id);
        $this->assertNull($pack->challenges()->first()->club_id);
        $this->assertNull(Badge::create(['code' => 'x_badge', 'name' => 'X', 'description' => 'x'])->fresh()->club_id);
    }

    public function test_factories_build_every_club_model(): void
    {
        foreach ([
            Club::class, ClubMember::class, ClubInviteCode::class, ClubCompetition::class, ClubCompetitionPack::class,
            ClubCompetitionAttempt::class, ClubCompetitionAttemptGuess::class, ClubCompetitionPackResult::class,
            ClubCompetitionEntry::class, ClubCompetitionFinish::class, ClubPackRewardClaim::class,
            ClubPhotoSubmission::class, ClubAuditEvent::class,
        ] as $model) {
            $row = $model::factory()->create();
            $this->assertTrue($row->exists, "{$model} factory failed");
        }

        $club = Club::first();
        $this->assertSame(26, strlen($club->public_id));
        $this->assertInstanceOf(ClubStatus::class, $club->status);
        $this->assertInstanceOf(ClubRole::class, ClubMember::first()->role);
        $this->assertInstanceOf(CompetitionAttemptStatus::class, ClubCompetitionAttempt::first()->status);
    }

    public function test_club_member_pair_is_unique(): void
    {
        $club = $this->makeClub();
        $user = User::factory()->create();
        ClubMember::factory()->create(['club_id' => $club->id, 'user_id' => $user->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        ClubMember::factory()->create(['club_id' => $club->id, 'user_id' => $user->id]);
    }

    public function test_invite_codes_are_globally_unique(): void
    {
        ClubInviteCode::factory()->create(['code' => 'ABCDEFGH']);

        $this->expectException(UniqueConstraintViolationException::class);
        ClubInviteCode::factory()->create(['code' => 'ABCDEFGH']);
    }

    public function test_active_slot_allows_one_active_attempt_but_many_finished_ones(): void
    {
        [$competition, $pack, $user] = $this->attemptFixture();

        $base = ['club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id, 'user_id' => $user->id];

        // Finished attempts release the slot (NULL) — NULLs never collide.
        ClubCompetitionAttempt::factory()->completed()->create($base + ['attempt_number' => 1]);
        ClubCompetitionAttempt::factory()->completed()->create($base + ['attempt_number' => 2]);
        ClubCompetitionAttempt::factory()->create($base + ['attempt_number' => 3]); // active, slot 1

        $this->expectException(UniqueConstraintViolationException::class);
        ClubCompetitionAttempt::factory()->create($base + ['attempt_number' => 4]); // second active
    }

    public function test_attempt_number_is_unique_per_player_pack_and_competition(): void
    {
        [$competition, $pack, $user] = $this->attemptFixture();
        $base = ['club_competition_id' => $competition->id, 'challenge_pack_id' => $pack->id, 'user_id' => $user->id, 'attempt_number' => 1];

        ClubCompetitionAttempt::factory()->completed()->create($base);

        $this->expectException(UniqueConstraintViolationException::class);
        ClubCompetitionAttempt::factory()->completed()->create($base);
    }

    public function test_a_challenge_is_answered_once_per_competition_attempt(): void
    {
        $guess = ClubCompetitionAttemptGuess::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        ClubCompetitionAttemptGuess::factory()->create([
            'attempt_id'   => $guess->attempt_id,
            'challenge_id' => $guess->challenge_id,
            'position'     => 1,
        ]);
    }

    public function test_one_reward_claim_per_player_and_club_pack(): void
    {
        $claim = ClubPackRewardClaim::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        ClubPackRewardClaim::factory()->create([
            'user_id'           => $claim->user_id,
            'challenge_pack_id' => $claim->challenge_pack_id,
            'source_type'       => ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT,
        ]);
    }

    public function test_a_club_with_content_cannot_be_hard_deleted_at_db_level(): void
    {
        $club = $this->makeClub();
        $this->makeClubPack($club, 1);

        $this->expectException(QueryException::class);
        $club->delete();
    }

    public function test_competition_phase_is_derived_from_state_and_window(): void
    {
        $club = $this->makeClub();
        $c = ClubCompetition::factory()->create(['club_id' => $club->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        $this->assertSame(CompetitionPhase::Draft, $c->phase());

        $c->state = CompetitionState::Published;
        $this->assertSame(CompetitionPhase::Upcoming, $c->phase());
        $this->assertSame(CompetitionPhase::Active, $c->phase(now()->addDay()));            // starts_at inclusive
        $this->assertSame(CompetitionPhase::Ended, $c->phase(now()->addDays(2)));           // ends_at exclusive
        $this->assertFalse($c->isLocked());
        $this->assertTrue($c->isLocked(now()->addDay()));

        $c->state = CompetitionState::Finalized;
        $this->assertSame(CompetitionPhase::Finalized, $c->phase());
        $c->state = CompetitionState::Cancelled;
        $this->assertSame(CompetitionPhase::Cancelled, $c->phase());
        $c->state = CompetitionState::Voided;
        $this->assertSame(CompetitionPhase::Voided, $c->phase());
    }

    public function test_competition_defaults_follow_the_sprint_decisions(): void
    {
        $club = $this->makeClub();
        $c = ClubCompetition::create([
            'club_id' => $club->id, 'name' => 'Cup', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3),
        ])->fresh();

        $this->assertSame(ReplayMode::SingleAttempt, $c->replay_mode);         // D2
        $this->assertSame(NormalPlayPolicy::Blocked, $c->normal_play_policy);  // D15
        $this->assertSame(1, $c->max_attempts);
        $this->assertSame('earliest_reached', $c->tie_break);
        $this->assertSame($club->timezone, $c->timezone);
        $this->assertSame(26, strlen($c->public_id));
    }

    public function test_competition_scopes_split_upcoming_live_and_completed(): void
    {
        $club = $this->makeClub();
        $upcoming  = ClubCompetition::factory()->published()->create(['club_id' => $club->id]);
        $live      = ClubCompetition::factory()->live()->create(['club_id' => $club->id]);
        $ended     = ClubCompetition::factory()->ended()->create(['club_id' => $club->id]);
        $finalized = ClubCompetition::factory()->finalized()->create(['club_id' => $club->id]);
        ClubCompetition::factory()->create(['club_id' => $club->id]); // draft

        $this->assertSame([$upcoming->id], ClubCompetition::upcoming()->pluck('id')->all());
        $this->assertSame([$live->id], ClubCompetition::live()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$ended->id, $finalized->id], ClubCompetition::completed()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$upcoming->id, $live->id, $ended->id], ClubCompetition::reservingNormalPlay()->pluck('id')->all());
    }

    public function test_club_scopes_settings_and_relations(): void
    {
        $owner  = User::factory()->create();
        $active = $this->makeClub([], $owner);
        $this->makeClub(['status' => ClubStatus::Suspended]);
        $this->makeClub(['status' => ClubStatus::Archived]);

        $this->assertSame([$active->id], Club::active()->pluck('id')->all());
        $this->assertSame(2, Club::listedForMembers()->count());

        $this->assertSame('admins', $active->setting('member_list_visibility'));       // D9 default
        $this->assertSame('single_attempt', $active->setting('default_replay_mode'));
        $this->assertSame(500, $active->effectiveMaxMembers());                          // D11
        $this->assertSame([$active->id], $owner->clubs()->pluck('clubs.id')->all());
        $this->assertSame(1, $owner->clubMemberships()->count());
        $this->assertSame(1, $active->owners()->count());

        $pack = $this->makeClubPack($active, 1);
        $this->assertTrue($pack->isClubPack());
        $this->assertSame($active->id, $pack->club->id);
        $this->assertSame([$pack->id], ChallengePack::forClub($active)->pluck('id')->all());
        $this->assertSame(0, ChallengePack::publicCatalog()->count());
        $this->assertSame(0, ChallengePack::withoutClub()->count());
    }

    public function test_attempt_mark_inactive_releases_the_slot(): void
    {
        $attempt = ClubCompetitionAttempt::factory()->create();
        $this->assertSame(1, $attempt->active_slot);

        $attempt->markInactive(CompetitionAttemptStatus::Expired)->save();

        $this->assertNull($attempt->fresh()->active_slot);
        $this->assertSame(CompetitionAttemptStatus::Expired, $attempt->fresh()->status);
    }

    public function test_invite_code_state_helpers(): void
    {
        $code = ClubInviteCode::factory()->create(['code' => 'ABCDEFGH']);

        $this->assertTrue($code->isUsable());
        $this->assertSame('ABCD-EFGH', $code->formatted());
        $this->assertSame('ABCD-••••', $code->masked());
        $this->assertArrayNotHasKey('code', $code->toArray(), 'codes are hidden from serialisation');

        $this->assertFalse(ClubInviteCode::factory()->revoked()->create()->isUsable());
        $this->assertFalse(ClubInviteCode::factory()->expired()->create()->isUsable());
        $this->assertFalse(ClubInviteCode::factory()->exhausted()->create()->isUsable());
        $this->assertSame(1, ClubInviteCode::usable()->count());
    }

    public function test_contact_fields_are_hidden_from_serialisation(): void
    {
        $club = $this->makeClub(['contact_email' => 'secretary@example.test', 'contact_name' => 'Sec']);

        $this->assertArrayNotHasKey('contact_email', $club->toArray());
        $this->assertArrayNotHasKey('contact_name', $club->toArray());
    }

    public function test_membership_status_enum_round_trips(): void
    {
        $member = ClubMember::factory()->status(ClubMemberStatus::Banned)->create();

        $this->assertTrue($member->fresh()->isBanned());
        $this->assertFalse($member->fresh()->isActive());
        $this->assertSame(0, ClubMember::active()->count());
    }

    /** @return array{0: ClubCompetition, 1: ChallengePack, 2: User} */
    private function attemptFixture(): array
    {
        $club = $this->makeClub();
        $pack = $this->makeClubPack($club, 1);
        $competition = $this->makeCompetition($club, [$pack]);

        return [$competition, $pack, User::factory()->create()];
    }
}
