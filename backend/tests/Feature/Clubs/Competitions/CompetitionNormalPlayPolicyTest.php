<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Models\PackAttempt;
use App\Services\Clubs\ClubCompetitionCloseService;
use Carbon\Carbon;

/**
 * normal_play_policy (D15) against the NORMAL pack routes (AT-CMP-13):
 * `blocked` reserves linked packs from publish until finalize; `allowed`
 * leaves normal play open. In-progress normal attempts on a reserved pack
 * are frozen until finalize/cancel/void (security review 2026-10).
 */
class CompetitionNormalPlayPolicyTest extends CompetitionTestCase
{
    public function test_at_cmp_13_blocked_reserves_the_pack_from_publish_until_finalize(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $user = $this->member('player');
        $c    = $this->liveCompetition([$pack], ['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);

        // Upcoming (published) → reserved, and the detail hides the photos.
        $this->startClubPack($user, $this->club, $pack)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition');
        $this->api($user)->getJson($this->packPath($this->club, $pack))->assertOk()
            ->assertJsonPath('data.reserved_for_competition', true)->assertJsonPath('data.challenges', []);

        // Active and ended (not finalized yet) → still reserved.
        Carbon::setTestNow(now()->addHours(2));
        $this->startClubPack($user, $this->club, $pack)->assertStatus(409);
        Carbon::setTestNow(now()->addHours(2));
        $this->startClubPack($user, $this->club, $pack)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition');

        app(ClubCompetitionCloseService::class)->finalize($c);
        $this->startClubPack($user, $this->club, $pack)->assertOk()->assertJsonPath('attempt.status', 'active');
        $this->api($user)->getJson($this->packPath($this->club, $pack))->assertJsonPath('data.reserved_for_competition', false);
    }

    public function test_drafts_do_not_reserve_and_cancelled_ones_release(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $user = $this->member('player');
        $c = $this->makeCompetition($this->club, [$pack]); // draft
        $this->startClubPack($user, $this->club, $pack)->assertOk();

        $other = $this->makeClubPack($this->club, 1);
        $live  = $this->liveCompetition([$other], ['starts_at' => now()->addHour()]);
        $this->startClubPack($user, $this->club, $other)->assertStatus(409);
        $live->forceFill(['state' => 'cancelled'])->save();
        $this->startClubPack($user, $this->club, $other)->assertOk();
        $this->assertNotNull($c);
    }

    public function test_an_in_progress_normal_attempt_is_frozen_while_reserved_and_resumes_after_finalize(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $user = $this->member('player');
        $attemptId = $this->startClubPack($user, $this->club, $pack)->assertOk()->json('attempt.id');
        $this->guessCurrent($user, $attemptId)->assertOk();

        $c = $this->liveCompetition([$pack]);
        $this->guessCurrent($user, $attemptId)->assertStatus(409)->assertJsonPath('code', 'pack_reserved_for_competition');
        $this->assertSame(1, PackAttempt::find($attemptId)->current_index);

        Carbon::setTestNow($c->ends_at->copy()->addMinute());
        app(ClubCompetitionCloseService::class)->finalize($c->fresh());
        $this->guessCurrent($user, $attemptId)->assertOk()->assertJsonPath('pack_completed', true);
        $this->assertSame(PackAttempt::STATUS_COMPLETED, PackAttempt::find($attemptId)->status);
    }

    public function test_allowed_keeps_normal_play_open_and_interplays_with_the_replay_modes(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $single = $this->liveCompetition([$pack], ['normal_play_policy' => 'allowed']);
        $user = $this->member('player');

        $this->api($user)->getJson($this->packPath($this->club, $pack))->assertJsonPath('data.reserved_for_competition', false);
        $this->playClubPack($user, $this->club, $pack)->assertJsonPath('pack_completed', true);

        // single_attempt: a completed normal run does not block the competition attempt.
        $attemptId = (int) $this->startAttempt($user, $single, $pack)->assertCreated()->assertJsonPath('attempt.is_rewarded_run', false)->json('attempt.id');
        $this->guess($user, $attemptId)->assertOk();
        $this->guess($user, $attemptId)->assertOk()->assertJsonPath('pack_completed', true);

        // no_replay: it does (in a later competition — one live competition per pack).
        Carbon::setTestNow($single->ends_at->copy()->addMinute());
        $noReplay = $this->liveCompetition([$pack], ['normal_play_policy' => 'allowed', 'replay_mode' => 'no_replay']);
        $this->startAttempt($user, $noReplay, $pack)->assertStatus(422)->assertJsonPath('code', 'competition_pack_already_played');

        // A completed normal pack still answers the existing 409 replay contract.
        $this->startClubPack($user, $this->club, $pack)->assertStatus(409)->assertJsonPath('attempt.status', 'completed');
    }
}
