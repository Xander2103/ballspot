<?php

namespace Tests\Feature\Clubs\Security;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionRulesGuard;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Clubs\Competitions\CompetitionTestCase;

/**
 * Security review 2026-10, findings 2 + 6:
 *  - a challenge in the snapshot of a published competition with locked rules
 *    keeps its ball position, images and active status (competition_rules_locked);
 *  - it cannot be deleted while it is in a not-finalized competition's snapshot;
 *  - a snapshotted challenge that vanished anyway answers a domain error;
 *  - with the Clubs flag off a club pack cannot be played at all.
 */
class CompetitionContentLockTest extends CompetitionTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('club_private');
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    /** @return array{0: ChallengePack, 1: ClubCompetition, 2: Challenge} a locked live competition */
    private function lockedSetup(): array
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack]);
        app(ClubCompetitionRulesGuard::class)->lock($c);

        return [$pack, $c->fresh(), $pack->challenges()->orderBy('challenge_pack_challenge.sort_order')->first()];
    }

    private function form(Challenge $challenge, array $overrides = []): array
    {
        return array_merge([
            'title'        => $challenge->title,
            'difficulty'   => $challenge->difficulty,
            'status'       => $challenge->status,
            'ball_x_ratio' => (string) $challenge->ball_x_ratio,
            'ball_y_ratio' => (string) $challenge->ball_y_ratio,
        ], $overrides);
    }

    private function putChallenge(Challenge $challenge, array $overrides = [])
    {
        return $this->actingAs($this->admin)->from('/admin/challenges/' . $challenge->id . '/edit')
            ->put('/admin/challenges/' . $challenge->id, $this->form($challenge, $overrides));
    }

    public function test_locked_challenge_keeps_ball_position_images_and_active_status(): void
    {
        [, $c, $challenge] = $this->lockedSetup();
        $locked = __('clubs.errors.competition_rules_locked');

        $this->putChallenge($challenge, ['ball_x_ratio' => '0.9'])->assertSessionHas('error', $locked);
        $this->putChallenge($challenge, ['status' => 'archived'])->assertSessionHas('error', $locked);
        $this->putChallenge($challenge, ['hidden_image' => UploadedFile::fake()->image('new.jpg', 40, 40)])->assertSessionHas('error', $locked);
        $this->actingAs($this->admin)->post('/admin/challenges/' . $challenge->id . '/status', ['status' => 'draft'])->assertSessionHas('error', $locked);

        $fresh = $challenge->fresh();
        $this->assertSame(0.5, (float) $fresh->ball_x_ratio);
        $this->assertSame('active', $fresh->status);
        $this->assertSame($challenge->hidden_image_path, $fresh->hidden_image_path);

        // Harmless edits stay possible (title; the same ball position resubmitted).
        $this->putChallenge($challenge, ['title' => 'Renamed'])->assertSessionHas('success');
        $this->assertSame('Renamed', $challenge->fresh()->title);

        // After finalize the content is free again.
        Carbon::setTestNow($c->ends_at->copy()->addMinute());
        app(ClubCompetitionCloseService::class)->finalize($c->fresh());
        $this->putChallenge($challenge->fresh(), ['ball_x_ratio' => '0.9'])->assertSessionHas('success');
        $this->assertSame(0.9, (float) $challenge->fresh()->ball_x_ratio);
    }

    public function test_locked_before_the_lazy_snapshot_is_written_counts_too(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $this->liveCompetition([$pack]); // started, lock action not run yet
        $challenge = $pack->challenges()->first();

        $this->putChallenge($challenge, ['ball_y_ratio' => '0.1'])->assertSessionHas('error', __('clubs.errors.competition_rules_locked'));
    }

    public function test_upcoming_competition_does_not_freeze_edits_but_blocks_delete(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $this->liveCompetition([$pack], ['starts_at' => now()->addDay()]);
        $challenge = $pack->challenges()->first();

        $this->putChallenge($challenge, ['ball_x_ratio' => '0.7'])->assertSessionHas('success');
        $this->actingAs($this->admin)->delete('/admin/challenges/' . $challenge->id)->assertSessionHas('error');
        $this->assertNotNull($challenge->fresh());
    }

    public function test_delete_is_refused_while_in_any_not_finalized_snapshot_even_unplayed(): void
    {
        [, $c, $challenge] = $this->lockedSetup();

        $this->actingAs($this->admin)->delete('/admin/challenges/' . $challenge->id)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'has not been finalized'));
        $this->assertNotNull($challenge->fresh());

        Carbon::setTestNow($c->ends_at->copy()->addMinute());
        app(ClubCompetitionCloseService::class)->finalize($c->fresh());
        $this->actingAs($this->admin)->delete('/admin/challenges/' . $challenge->id)->assertSessionHas('success');
        $this->assertNull($challenge->fresh());
    }

    public function test_a_missing_snapshotted_challenge_answers_a_domain_error_not_a_404_or_500(): void
    {
        [$pack, $c] = $this->lockedSetup();
        $player    = $this->member('player');
        $attemptId = (int) $this->startAttempt($player, $c, $pack)->assertCreated()->json('attempt.id');
        $attempt   = ClubCompetitionAttempt::findOrFail($attemptId);
        $missing   = $attempt->challengeIds()[0];

        // Removed behind the app's back (the delete route refuses it).
        DB::table('challenge_pack_challenge')->where('challenge_id', $missing)->delete();
        DB::table('challenges')->where('id', $missing)->delete();

        $this->guess($player, $attemptId, 100, $missing)->assertStatus(422)
            ->assertJsonPath('code', 'competition_invalid_pack')->assertJsonPath('reason', 'challenge_unavailable');
        $this->api($player)->getJson("/api/club-competition-attempts/{$attemptId}")->assertStatus(422)
            ->assertJsonPath('code', 'competition_invalid_pack');
        $this->assertSame(0, $attempt->fresh()->guesses_count);
    }

    public function test_flag_off_refuses_club_pack_play_on_every_route(): void
    {
        $pack   = $this->makeClubPack($this->club, 2);
        $player = $this->member('player');
        $normal = (int) $this->startClubPack($player, $this->club, $pack)->assertOk()->json('attempt.id');
        $c      = $this->liveCompetition([$this->makeClubPack($this->club, 1)]);

        config(['ballspot.clubs.enabled' => false]);

        // The generic guess route is outside `feature:clubs` — the gate refuses it.
        $this->guessCurrent($player, $normal)->assertStatus(404)->assertJsonPath('code', 'feature_disabled');
        $this->assertSame(0, \App\Models\PackAttempt::find($normal)->current_index);
        // Club and competition routes are behind `feature:clubs`.
        $this->startClubPack($player, $this->club, $pack)->assertStatus(404)->assertJsonPath('code', 'feature_disabled');
        $this->startAttempt($player, $c, $c->packs()->first())->assertStatus(404)->assertJsonPath('code', 'feature_disabled');
        $this->api($player)->postJson('/api/club-competition-attempts/1/guess', ['challenge_id' => 1, 'guessed_x' => 0.5, 'guessed_y' => 0.5])
            ->assertStatus(404)->assertJsonPath('code', 'feature_disabled');
    }
}
