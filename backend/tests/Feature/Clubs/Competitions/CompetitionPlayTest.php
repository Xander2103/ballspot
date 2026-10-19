<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Enums\CompetitionAttemptStatus;
use App\Models\ChallengePack;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionAttemptGuess;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubCompetitionPack;
use App\Models\PackAttempt;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Competition attempts and guesses through the real member API
 * (AT-CMP-01…03, 06…09, 12; plan §5.2, §5.3, §5.9).
 */
class CompetitionPlayTest extends CompetitionTestCase
{
    /** A completed NORMAL run of the pack, finished before the competition was published. */
    private function completeNormally($user, ChallengePack $pack): void
    {
        $attempt = PackAttempt::create([
            'user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => PackAttempt::STATUS_COMPLETED,
            'started_at' => now()->subDays(3), 'completed_at' => now()->subDays(3), 'current_index' => 3, 'total_score' => 300,
            'metadata' => ['challenge_ids' => $pack->challenges()->pluck('challenges.id')->all()],
        ]);
        $this->assertNotNull($attempt->id);
    }

    public function test_at_cmp_01_no_replay_blocks_players_who_completed_the_pack(): void
    {
        $pack = $this->makeClubPack($this->club, 3);
        $c    = $this->liveCompetition([$pack], ['replay_mode' => 'no_replay']);
        $done = $this->member('done');
        $new  = $this->member('fresh');
        $this->completeNormally($done, $pack);

        $state = $this->api($done)->getJson($this->competitionPath($c))->assertOk()->json('data.packs.0.state');
        $this->assertFalse($state['can_start']);
        $this->assertSame('already_played', $state['blocked_reason']);

        $this->startAttempt($done, $c, $pack)->assertStatus(422)->assertJsonPath('code', 'competition_pack_already_played');
        $this->assertSame(0, ClubCompetitionAttempt::count());

        // Not completed before → allowed, but only once.
        $this->startAttempt($new, $c, $pack)->assertCreated();
    }

    public function test_no_replay_also_counts_a_completed_attempt_in_another_competition(): void
    {
        $pack  = $this->makeClubPack($this->club, 2);
        $other = $this->liveCompetition([$pack], ['normal_play_policy' => 'allowed']);
        $c     = $this->liveCompetition([$pack], ['replay_mode' => 'no_replay']);
        $user  = $this->member('player');

        $this->play($user, $other, $pack, [100, 100]);
        $this->startAttempt($user, $c, $pack)->assertStatus(422)->assertJsonPath('code', 'competition_pack_already_played');
    }

    public function test_at_cmp_02_single_attempt_allows_one_attempt_even_after_normal_completion(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $this->completeNormally($user, $pack);

        $state = $this->api($user)->getJson($this->competitionPath($c))->assertOk()->json('data.packs.0.state');
        $this->assertSame([
            'attempts_used' => 0, 'attempts_remaining' => 1, 'next_attempt_number' => 1, 'can_start' => true, 'blocked_reason' => null,
            'active_attempt_id' => null, 'counted_score' => null, 'best_score' => null, 'first_score' => null,
        ], $state);

        $this->play($user, $c, $pack, [90, 80]);

        $state = $this->api($user)->getJson($this->competitionPath($c))->assertOk()->json('data.packs.0.state');
        $this->assertSame(['attempts_used' => 1, 'attempts_remaining' => 0, 'next_attempt_number' => null, 'can_start' => false,
            'blocked_reason' => 'limit_reached', 'active_attempt_id' => null, 'counted_score' => 170, 'best_score' => 170, 'first_score' => 170], $state);

        $this->startAttempt($user, $c, $pack, 2)->assertStatus(422)->assertJsonPath('code', 'competition_attempt_limit');
    }

    public function test_at_cmp_03_multiple_attempts_limit_and_parallel_start_returns_the_active_attempt(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], $this->multiple(3, 'best'));
        $user = $this->member('player');

        $first = $this->startAttempt($user, $c, $pack, 1)->assertCreated()->json('attempt.id');
        // Start of the next number while #1 is active → resume #1 (no parallel attempts).
        $this->startAttempt($user, $c, $pack, 2)->assertOk()->assertJsonPath('attempt.id', $first)->assertJsonPath('attempt.attempt_number', 1);
        $state = $this->api($user)->getJson($this->competitionPath($c))->json('data.packs.0.state');
        $this->assertSame('attempt_in_progress', $state['blocked_reason']);
        $this->assertSame($first, $state['active_attempt_id']);

        $this->guess($user, $first, 50)->assertOk();
        $this->guess($user, $first, 50)->assertOk();
        $this->play($user, $c, $pack, [60, 60], 2);
        $this->play($user, $c, $pack, [70, 70], 3);

        $this->startAttempt($user, $c, $pack, 4)->assertStatus(422)->assertJsonPath('code', 'competition_attempt_limit');
        $this->assertSame(3, ClubCompetitionAttempt::count());
    }

    public function test_attempt_number_must_be_the_next_one(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack], $this->multiple(3, 'first'));
        $user = $this->member('player');

        $this->startAttempt($user, $c, $pack, 2)->assertStatus(422)->assertJsonValidationErrors('attempt_number');
        $this->startAttempt($user, $c, $pack, 0)->assertStatus(422)->assertJsonValidationErrors('attempt_number');
    }

    public function test_at_cmp_06_window_edges_and_partial_attempt_expires_with_its_score(): void
    {
        $pack = $this->makeClubPack($this->club, 3);
        $c    = $this->liveCompetition([$pack], ['starts_at' => now()->addMinutes(10), 'ends_at' => now()->addMinutes(30)]);
        $user = $this->member('player');

        // Before starts_at.
        $this->startAttempt($user, $c, $pack)->assertStatus(422)->assertJsonPath('code', 'competition_not_started');

        $this->tick(600); // exactly starts_at → open
        $id = $this->startAttempt($user, $c, $pack)->assertCreated()->json('attempt.id');
        $this->guess($user, $id, 80)->assertOk();

        \Carbon\Carbon::setTestNow($c->ends_at->copy()); // exactly ends_at → closed (no grace)
        $this->guess($user, $id, 100)->assertStatus(422)->assertJsonPath('code', 'competition_ended');

        $attempt = ClubCompetitionAttempt::find($id);
        $this->assertSame(CompetitionAttemptStatus::Expired, $attempt->status);
        $this->assertNull($attempt->active_slot);
        $this->assertSame(80, $attempt->total_score);
        $this->assertSame(1, ClubCompetitionAttemptGuess::count());
        $this->assertSame(80, ClubCompetitionEntry::first()->total_score);
    }

    public function test_at_cmp_07_start_is_idempotent_per_attempt_number_and_unique_constraints_hold(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], $this->multiple(2, 'best'));
        $user = $this->member('player');

        $a = $this->startAttempt($user, $c, $pack, 1)->assertCreated();
        $b = $this->startAttempt($user, $c, $pack, 1)->assertOk();
        $this->assertSame($a->json('attempt'), $b->json('attempt'));
        $this->assertSame($a->json('challenge'), $b->json('challenge'));
        $this->assertSame(1, ClubCompetitionAttempt::count());

        // The DB is the real guard against a concurrent second start: same number…
        $row = ClubCompetitionAttempt::first();
        try {
            ClubCompetitionAttempt::create($row->only(['club_competition_id', 'challenge_pack_id', 'user_id']) + [
                'attempt_number' => 1, 'active_slot' => null, 'challenge_ids' => [], 'status' => 'completed']);
            $this->fail('Duplicate attempt_number accepted.');
        } catch (UniqueConstraintViolationException) {
        }
        // …or a second ACTIVE attempt (active_slot unique while NULLs stay distinct).
        try {
            ClubCompetitionAttempt::create($row->only(['club_competition_id', 'challenge_pack_id', 'user_id']) + [
                'attempt_number' => 2, 'active_slot' => 1, 'challenge_ids' => []]);
            $this->fail('Second active attempt accepted.');
        } catch (UniqueConstraintViolationException) {
        }
        ClubCompetitionAttempt::create($row->only(['club_competition_id', 'challenge_pack_id', 'user_id']) + [
            'attempt_number' => 2, 'active_slot' => null, 'status' => 'completed', 'challenge_ids' => []]);
        $this->assertSame(2, ClubCompetitionAttempt::count());
    }

    public function test_at_cmp_08_duplicate_guess_returns_the_stored_result_replayed(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $id   = $this->startAttempt($user, $c, $pack)->json('attempt.id');
        $first = ClubCompetitionAttempt::find($id)->challengeIds()[0];

        $one = $this->guess($user, $id, 70)->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('result.score', 70);
        // Retry of the same challenge (lost response) with a different position: stored result, nothing changes.
        $two = $this->guess($user, $id, 100, $first)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('result.score', 70)
            ->assertJsonPath('rank_progress.xp_gained', 0);
        $this->assertSame($one->json('result'), $two->json('result'));
        $this->assertSame(70, ClubCompetitionAttempt::find($id)->total_score);
        $this->assertSame(1, ClubCompetitionAttemptGuess::count());

        // Finish, then retry the last guess: still the stored result, attempt stays completed.
        $this->guess($user, $id, 40)->assertOk()->assertJsonPath('pack_completed', true);
        $last = ClubCompetitionAttempt::find($id)->challengeIds()[1];
        $this->guess($user, $id, 100, $last)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('already_completed', true)
            ->assertJsonPath('result.score', 40)->assertJsonPath('progress.total_score', 110);
        $this->assertSame(110, ClubCompetitionEntry::first()->total_score);
    }

    public function test_at_cmp_09_out_of_order_challenge_is_rejected_and_nothing_is_stored(): void
    {
        $pack = $this->makeClubPack($this->club, 3);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $id   = $this->startAttempt($user, $c, $pack)->json('attempt.id');
        $ids  = ClubCompetitionAttempt::find($id)->challengeIds();

        $this->guess($user, $id, 100, $ids[2])->assertStatus(422)->assertJsonPath('code', 'unexpected_challenge');
        $this->guess($user, $id, 100, 999999)->assertStatus(422)->assertJsonPath('code', 'unexpected_challenge');
        $this->assertSame(0, ClubCompetitionAttemptGuess::count());
        $this->assertSame(0, ClubCompetitionAttempt::find($id)->current_index);
        $this->assertSame(0, ClubCompetitionEntry::count());
    }

    public function test_guess_on_a_completed_attempt_is_attempt_not_active(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $other = $this->makeClubChallenge($this->club);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $id   = $this->play($user, $c, $pack, [100]);

        $this->guess($user, $id, 100, $other->id)->assertStatus(409)->assertJsonPath('code', 'attempt_not_active');
    }

    public function test_at_cmp_12_attempts_use_the_snapshot_taken_at_rule_lock(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $snapshotBefore = $pack->challenges()->pluck('challenges.id')->map(fn ($id) => (int) $id)->all();

        $id = $this->startAttempt($user, $c, $pack)->assertCreated()->json('attempt.id');
        $link = ClubCompetitionPack::first();
        $this->assertSame($snapshotBefore, $link->snapshotIds());
        $this->assertNotNull($c->fresh()->rules_locked_at);

        // The pack is edited after the lock: a new challenge in front.
        $extra = $this->makeClubChallenge($this->club);
        $pack->challenges()->attach($extra->id, ['sort_order' => 0]);

        $this->assertSame($snapshotBefore, ClubCompetitionAttempt::find($id)->challengeIds());
        $other = $this->member('other');
        $otherId = $this->startAttempt($other, $c, $pack)->json('attempt.id');
        $this->assertSame($snapshotBefore, ClubCompetitionAttempt::find($otherId)->challengeIds());
        $this->api($other)->getJson($this->competitionPath($c))->assertJsonPath('data.packs.0.challenge_count', 2);
        $this->guess($other, $otherId, 100, $extra->id)->assertStatus(422)->assertJsonPath('code', 'unexpected_challenge');
    }

    public function test_attempts_are_owner_only_and_lost_membership_stops_play(): void
    {
        $pack  = $this->makeClubPack($this->club, 2);
        $c     = $this->liveCompetition([$pack]);
        $user  = $this->member('player');
        $other = $this->member('other');
        $id    = $this->startAttempt($user, $c, $pack)->json('attempt.id');

        $this->api($other)->getJson("/api/club-competition-attempts/{$id}")->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->guess($other, $id, 100)->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->api($user)->getJson('/api/club-competition-attempts/987654')->assertNotFound()->assertJsonPath('code', 'club_not_found');

        // AT-ISO-07: removed member with an active attempt → next guess rejected.
        app(\App\Services\Clubs\ClubMembershipService::class)->remove(
            \App\Models\ClubMember::where('user_id', $user->id)->first(), $this->owner, 'platform_admin', 'test');
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();
        $this->guess($user, $id, 100)->assertNotFound()->assertJsonPath('code', 'club_not_found');
        $this->assertSame(0, ClubCompetitionAttemptGuess::count());
    }

    public function test_suspended_club_is_read_only(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack]);
        $user = $this->member('player');
        $id   = $this->startAttempt($user, $c, $pack)->json('attempt.id');

        $this->club->forceFill(['status' => 'suspended'])->save();
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();

        $this->api($user)->getJson($this->competitionPath($c))->assertOk()->assertJsonPath('data.packs.0.state.blocked_reason', 'club_unavailable');
        $this->guess($user, $id, 100)->assertStatus(423)->assertJsonPath('code', 'club_unavailable');
        $this->startAttempt($this->member('late'), $c, $pack)->assertStatus(423)->assertJsonPath('code', 'club_unavailable');
    }
}
