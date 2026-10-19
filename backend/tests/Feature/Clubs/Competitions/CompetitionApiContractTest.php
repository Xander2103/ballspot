<?php

namespace Tests\Feature\Clubs\Competitions;

use App\Models\ClubCompetition;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionCloseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * HTTP contract of the competition endpoints (contracts §5) — the exact
 * keys the mobile client (mobile/src/types/club.ts) reads — plus the
 * isolation matrix (AT-ISO-04/05 for Wave C routes) and the feature flag.
 */
class CompetitionApiContractTest extends CompetitionTestCase
{
    private const CARD_KEYS = ['id', 'name', 'description', 'phase', 'starts_at', 'ends_at', 'timezone', 'rules', 'packs_count', 'participants_count', 'me'];
    private const ATTEMPT_KEYS = ['id', 'competition_id', 'pack_slug', 'attempt_number', 'status', 'current_index', 'completed_count',
        'total_challenges', 'total_score', 'is_rewarded_run', 'started_at', 'completed_at'];
    private const ENTRY_KEYS = ['rank', 'user_id', 'username', 'avatar_url', 'total_score', 'packs_completed', 'attempts_used', 'is_current_user'];

    private function keys(array $row): array
    {
        $k = array_keys($row);
        sort($k);

        return $k;
    }

    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    public function test_list_by_phase_with_cursor_and_card_shape(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $user = $this->member('player');
        $live1 = $this->liveCompetition([$pack], ['name' => 'Live A', 'ends_at' => now()->addDays(2)]);
        $live2 = $this->liveCompetition([$pack], ['name' => 'Live B', 'ends_at' => now()->addDays(3)]);
        $up    = $this->liveCompetition([$pack], ['name' => 'Soon', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4)]);
        $ended = $this->liveCompetition([$pack], ['name' => 'Ended', 'starts_at' => now()->subDays(5), 'ends_at' => now()->subDay()]);
        $final = $this->liveCompetition([$pack], ['name' => 'Final', 'state' => 'finalized', 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(8)]);
        $this->makeCompetition($this->club, [$pack], ['name' => 'Draft']);
        $this->liveCompetition([$pack], ['name' => 'Cancelled', 'state' => 'cancelled']);
        $this->play($user, $live1, $pack, [70]);

        $active = $this->api($user)->getJson($this->clubPath($this->club, '/competitions?limit=1'))->assertOk();
        $this->assertSame(['Live A'], array_column($active->json('data'), 'name'));
        $this->assertSame(self::CARD_KEYS, array_keys($active->json('data.0')));
        $this->assertSame(['rank' => 1, 'total_score' => 70, 'packs_completed' => 1], $active->json('data.0.me'));
        $this->assertSame(['replay_mode' => 'single_attempt', 'max_attempts' => 1, 'score_rule' => 'first', 'normal_play_policy' => 'blocked'],
            $active->json('data.0.rules'));
        $active->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.phase', 'active');
        $next = $this->api($user)->getJson($this->clubPath($this->club, '/competitions?limit=1&cursor=' . $active->json('meta.next_cursor')))->assertOk();
        $this->assertSame(['Live B'], array_column($next->json('data'), 'name'));
        $this->assertNull($next->json('meta.next_cursor'));

        $this->assertSame(['Soon'], array_column($this->api($user)->getJson($this->clubPath($this->club, '/competitions?phase=upcoming'))->json('data'), 'name'));
        $this->assertSame(['Ended', 'Final'], array_column($this->api($user)->getJson($this->clubPath($this->club, '/competitions?phase=completed'))->json('data'), 'name'));
        $this->api($user)->getJson($this->clubPath($this->club, '/competitions?phase=draft'))->assertStatus(422)->assertJsonValidationErrors('phase');
        $this->assertNotNull($up->id + $ended->id + $final->id + $live2->id);
    }

    public function test_detail_start_attempt_guess_and_my_attempts_shapes(): void
    {
        $pack = $this->makeClubPack($this->club, 2);
        $c    = $this->liveCompetition([$pack], $this->multiple(2, 'best') + ['description' => 'Derby week']);
        $user = $this->member('player');

        $detail = $this->api($user)->getJson($this->competitionPath($c))->assertOk()->json('data');
        $this->assertSame($this->sorted(array_merge(self::CARD_KEYS, ['packs', 'my_entry', 'leaderboard_preview'])), $this->keys($detail));
        $this->assertSame('Derby week', $detail['description']);
        $this->assertSame('active', $detail['phase']);
        $this->assertSame(1, $detail['packs_count']);
        $this->assertSame(['id', 'slug', 'name', 'cover_image_url', 'challenge_count', 'state'], array_keys($detail['packs'][0]));
        $this->assertSame([$pack->id, $pack->slug, 2], [$detail['packs'][0]['id'], $detail['packs'][0]['slug'], $detail['packs'][0]['challenge_count']]);
        $this->assertSame(['attempts_used', 'attempts_remaining', 'next_attempt_number', 'can_start', 'blocked_reason', 'active_attempt_id',
            'counted_score', 'best_score', 'first_score'], array_keys($detail['packs'][0]['state']));
        $this->assertNull($detail['my_entry']);
        $this->assertSame([], $detail['leaderboard_preview']);

        $start = $this->startAttempt($user, $c, $pack)->assertCreated();
        $this->assertSame(['attempt', 'challenge'], array_keys($start->json()));
        $this->assertSame(self::ATTEMPT_KEYS, array_keys($start->json('attempt')));
        $start->assertJsonPath('attempt.competition_id', $c->public_id)->assertJsonPath('attempt.pack_slug', $pack->slug)
            ->assertJsonPath('attempt.status', 'active')->assertJsonPath('attempt.total_challenges', 2);
        $this->assertSame(['id', 'title', 'difficulty', 'hidden_image_url', 'sport', 'category'], array_keys($start->json('challenge')));
        $this->assertStringNotContainsString('ball_x', $start->getContent(), 'never the ball position before the guess');
        $this->assertStringContainsString('signature=', $start->json('challenge.hidden_image_url'), 'club challenges use signed URLs');
        $id = $start->json('attempt.id');

        $show = $this->api($user)->getJson("/api/club-competition-attempts/{$id}")->assertOk();
        $this->assertSame($start->json('attempt'), $show->json('attempt'));
        $this->assertSame($start->json('challenge.id'), $show->json('challenge.id'));

        $guess = $this->guess($user, $id, 80)->assertOk();
        $this->assertSame($this->sorted(['result', 'progress', 'next_challenge', 'rank_progress', 'rank_up', 'new_badges', 'pack_completed',
            'already_completed', 'final_score', 'completion_xp', 'completion', 'replayed', 'is_rewarded_run', 'competition']), $this->keys($guess->json()));
        $this->assertSame(['score', 'distance', 'guessed_x', 'guessed_y', 'ball_x_ratio', 'ball_y_ratio', 'reveal_image_url', 'sport'], array_keys($guess->json('result')));
        $this->assertSame(self::ATTEMPT_KEYS, array_keys($guess->json('progress')));
        $guess->assertJsonPath('result.score', 80)->assertJsonPath('result.ball_x_ratio', 0.5)
            ->assertJsonPath('progress.current_index', 1)->assertJsonPath('progress.total_score', 80)
            ->assertJsonPath('pack_completed', false)->assertJsonPath('final_score', null)->assertJsonPath('completion_xp', null)
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('competition', ['counted_score' => 80, 'total_score' => 80, 'rank' => 1, 'attempts_remaining' => 1]);
        $this->assertNotNull($guess->json('next_challenge.id'));
        $this->assertSame(['xp_gained', 'rank'], array_keys($guess->json('rank_progress')));

        $done = $this->guess($user, $id, 60)->assertOk()->assertJsonPath('pack_completed', true)->assertJsonPath('final_score', 140)
            ->assertJsonPath('next_challenge', null)->assertJsonPath('progress.status', 'completed')->assertJsonPath('progress.completed_count', 2);
        $this->assertNotNull($done->json('progress.completed_at'));
        $this->api($user)->getJson("/api/club-competition-attempts/{$id}")->assertOk()->assertJsonPath('challenge', null);

        $mine = $this->api($user)->getJson($this->competitionPath($c, '/me/attempts'))->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame(self::ATTEMPT_KEYS, array_keys($mine[0]));

        $detail = $this->api($user)->getJson($this->competitionPath($c))->json('data');
        $this->assertSame(['rank', 'total_score', 'packs_completed', 'attempts_used', 'score_reached_at'], array_keys($detail['my_entry']));
        $this->assertSame(self::ENTRY_KEYS, array_keys($detail['leaderboard_preview'][0]));
        $this->assertTrue($detail['leaderboard_preview'][0]['is_current_user']);
        $this->assertSame(1, $detail['participants_count']);

        $board = $this->api($user)->getJson($this->competitionPath($c, '/leaderboard'))->assertOk();
        $this->assertSame(['data', 'meta'], array_keys($board->json()));
        $this->assertSame($this->sorted(['total_players', 'current_user_rank', 'current_user_score', 'better_than_percentage', 'top_users',
            'nearby_users', 'next_cursor', 'per_page', 'rules', 'phase']), $this->keys($board->json('meta')));
        $this->assertSame(self::ENTRY_KEYS, array_keys($board->json('data.0')));
    }

    public function test_club_detail_competitions_preview_matches_the_competition_endpoints(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack]);
        $me   = $this->member('me');
        $this->play($this->member('top'), $c, $pack, [100]);
        $this->play($me, $c, $pack, [50]);

        $preview = $this->api($me)->getJson($this->clubPath($this->club))->assertOk()->json('data.competitions_preview.active.0');
        $card    = $this->api($me)->getJson($this->clubPath($this->club, '/competitions'))->json('data.0');
        $detail  = $this->api($me)->getJson($this->competitionPath($c))->json('data');
        $this->assertSame($card, $preview);
        $this->assertSame(array_intersect_key($detail, $card), $card);
        $this->assertSame(['rank' => 2, 'total_score' => 50, 'packs_completed' => 1], $preview['me']);
        $this->assertSame(2, $preview['participants_count']);
    }

    public function test_my_club_finishes(): void
    {
        $pack = $this->makeClubPack($this->club, 1);
        $c    = $this->liveCompetition([$pack], ['name' => 'Autumn Cup', 'ends_at' => now()->addHour()]);
        $user = $this->member('player');
        $this->play($this->member('top'), $c, $pack, [100]);
        $this->play($user, $c, $pack, [40]);
        $this->api($user)->getJson('/api/me/club-finishes')->assertOk()->assertJsonPath('data', []);

        Carbon::setTestNow(now()->addHours(2));
        app(ClubCompetitionCloseService::class)->finalize($c);

        $row = $this->api($user)->getJson('/api/me/club-finishes')->assertOk()->json('data.0');
        $this->assertSame([
            'competition'   => ['id' => $c->public_id, 'name' => 'Autumn Cup'],
            'club'          => ['id' => $this->club->public_id, 'name' => $this->club->name, 'logo_url' => $this->club->logoUrl()],
            'placement'     => 2,
            'total_score'   => 40,
            'total_players' => 2,
            'finalized_at'  => $c->fresh()->finalized_at->toISOString(),
        ], $row);

        // Archived clubs drop out (D18).
        $this->club->forceFill(['status' => 'archived'])->save();
        $this->api($user)->getJson('/api/me/club-finishes')->assertOk()->assertJsonPath('data', []);
    }

    public function test_isolation_matrix_for_competition_routes(): void
    {
        $pack  = $this->makeClubPack($this->club, 1);
        $c     = $this->liveCompetition([$pack]);
        $draft = $this->makeCompetition($this->club, [$pack]);
        $member = $this->member('member');
        $attemptId = $this->startAttempt($member, $c, $pack)->json('attempt.id');

        $otherClub = $this->makeClub(['name' => 'Club B']);
        $otherPack = $this->makeClubPack($otherClub, 1);
        $otherComp = $this->makeCompetition($otherClub, [$otherPack], ['state' => 'published', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $outsider  = User::factory()->create();
        $bMember   = User::factory()->create();
        $this->addMember($otherClub, $bMember);
        $admin     = User::factory()->create(['is_admin' => true]);

        $routes = fn (ClubCompetition $comp, string $slug) => [
            ['GET', $this->clubPath($this->club, '/competitions'), []],
            ['GET', $this->competitionPath($comp), []],
            ['POST', $this->competitionPath($comp, '/packs/' . $slug . '/attempts'), ['attempt_number' => 1]],
            ['GET', $this->competitionPath($comp, '/leaderboard'), []],
            ['GET', $this->competitionPath($comp, '/me/attempts'), []],
            ['GET', "/api/club-competition-attempts/{$attemptId}", []],
            ['POST', "/api/club-competition-attempts/{$attemptId}/guess", ['challenge_id' => 1, 'guessed_x' => 0.5, 'guessed_y' => 0.5]],
        ];

        foreach ([$outsider, $bMember, $admin] as $who) {
            foreach ($routes($c, $pack->slug) as [$method, $uri, $body]) {
                $this->api($who)->json($method, $uri, $body)->assertNotFound()->assertJsonPath('code', 'club_not_found');
            }
        }

        // AT-ISO-05: club B's competition / pack under club A, drafts, unlinked packs → 404.
        $unlinked = $this->makeClubPack($this->club, 1);
        foreach ([
            ['GET', $this->competitionPath($otherComp)],
            ['GET', $this->competitionPath($draft)],
            ['POST', $this->competitionPath($draft, '/packs/' . $pack->slug . '/attempts')],
            ['POST', $this->competitionPath($c, '/packs/' . $otherPack->slug . '/attempts')],
            ['POST', $this->competitionPath($c, '/packs/' . $unlinked->slug . '/attempts')],
            ['GET', $this->competitionPath($otherComp, '/leaderboard')],
            ['GET', $this->clubPath($this->club, '/competitions/01JUNKNOWNPUBLICID00000000')],
        ] as [$method, $uri]) {
            $this->api($member)->json($method, $uri, ['attempt_number' => 1])->assertNotFound()->assertJsonPath('code', 'club_not_found');
        }

        // Feature flag off → 404 feature_disabled.
        $this->enableClubs(false);
        $this->api($member)->getJson($this->competitionPath($c))->assertNotFound()->assertJsonPath('code', 'feature_disabled');
        $this->api($member)->getJson('/api/me/club-finishes')->assertNotFound()->assertJsonPath('code', 'feature_disabled');
    }

    public function test_competition_routes_carry_the_member_middleware_and_gameplay_throttle(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.clubs.')
            && (str_contains($r->uri(), 'competition') || str_contains($r->uri(), 'club-finishes')));
        $this->assertCount(8, $routes);
        foreach ($routes as $route) {
            $mw = $route->gatherMiddleware();
            foreach (['auth:sanctum', 'verified', 'feature:clubs'] as $required) {
                $this->assertContains($required, $mw, $route->uri());
            }
            if (in_array('POST', $route->methods(), true)) {
                $this->assertContains('throttle:gameplay', $mw, $route->uri());
            }
        }
    }
}
