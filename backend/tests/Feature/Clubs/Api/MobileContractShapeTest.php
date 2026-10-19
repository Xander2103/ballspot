<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubRole;
use App\Models\ChallengePack;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubInviteCode;
use App\Models\PackAttempt;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionCloseService;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Clubs\Competitions\CompetitionTestCase;

/**
 * Permanent contract guard between the Expo client and the Clubs member API.
 *
 * Every schema below is a transcription of a TypeScript type in
 * mobile/src/types/club.ts (and the pack shapes in mobile/src/types/pack.ts
 * the club screens reuse). The test calls every endpoint the app calls —
 * with the query parameters and bodies mobile/src/api/clubApi.ts sends — and
 * asserts that each key the app reads is present with the JSON type the app
 * expects (nullable only where the TS type allows null). Extra keys are
 * allowed (TS ignores them); a missing key or a wrong type fails.
 *
 * If you change a payload: change the TS type, this schema and
 * docs/clubs-implementation-contracts.md §5 together.
 */
class MobileContractShapeTest extends CompetitionTestCase
{
    // ------------------------------------------------------------------
    // Schemas (mirror mobile/src/types/club.ts + pack.ts)
    // ------------------------------------------------------------------

    private const PHASES  = 'enum:upcoming|active|ended|finalized|cancelled|voided';
    private const ROLES   = 'enum:owner|admin|member';
    private const BLOCKED = '?enum:not_started|ended|limit_reached|already_played|attempt_in_progress|club_unavailable';

    private static function nullable(mixed $spec): array
    {
        return ['__nullable' => $spec];
    }

    private static function listOf(mixed $spec): array
    {
        return ['__list' => $spec];
    }

    private static function optional(mixed $spec): array
    {
        return ['__optional' => $spec];
    }

    private static function sport(): array
    {
        return ['slug' => 'string', 'name' => 'string', 'emoji' => 'string'];
    }

    private static function cursorMeta(): array
    {
        return ['next_cursor' => '?string', 'per_page' => 'int'];
    }

    private static function clubCard(): array
    {
        return [
            'id'           => 'string',
            'name'         => 'string',
            'slug'         => 'string',
            'logo_url'     => '?string',
            'city'         => '?string',
            'country_code' => '?string',
            'club_type'    => 'string',
            'sport'        => self::nullable(self::sport()),
            'role'         => self::ROLES,
            'status'       => 'enum:active|suspended',
            'packs_count'  => 'int',
            'competitions' => ['active' => 'int', 'upcoming' => 'int'],
            'joined_at'    => '?iso',
        ];
    }

    private static function competitionRef(): array
    {
        return ['id' => 'string', 'name' => 'string', 'phase' => self::PHASES];
    }

    private static function packSummary(): array
    {
        return [
            'id'              => 'int',
            'slug'            => 'string',
            'name'            => 'string',
            'description'     => '?string',
            'sport'           => self::nullable(self::sport()),
            'cover_image_url' => '?string',
            'difficulty'      => '?string',
            'challenge_count' => 'int',
            'is_featured'     => 'bool',
        ];
    }

    private static function clubPackCard(): array
    {
        return self::packSummary() + [
            'progress' => self::nullable([
                'status'           => 'enum:active|completed|abandoned',
                'completed_count'  => 'int',
                'total_challenges' => 'int',
                'total_score'      => 'int',
            ]),
            'best_score'               => '?int',
            'reserved_for_competition' => 'bool',
            'competitions'             => self::listOf(self::competitionRef()),
        ];
    }

    private static function challenge(): array
    {
        return [
            'id'               => 'int',
            'title'            => 'string',
            'difficulty'       => 'string',
            'hidden_image_url' => '?string',
            'sport'            => self::nullable(self::sport() + ['object_name' => self::optional('?string')]),
            'category'         => self::nullable(['name' => 'string', 'slug' => 'string']),
        ];
    }

    private static function clubPackDetail(): array
    {
        return self::packSummary() + [
            'challenges'               => self::listOf(self::challenge()),
            'reserved_for_competition' => 'bool',
            'competitions'             => self::listOf(self::competitionRef()),
        ];
    }

    private static function rules(): array
    {
        return [
            'replay_mode'        => 'enum:no_replay|single_attempt|multiple_attempts',
            'max_attempts'       => 'int',
            'score_rule'         => 'enum:first|best',
            'normal_play_policy' => 'enum:blocked|allowed',
        ];
    }

    private static function competitionCard(): array
    {
        return [
            'id'                 => 'string',
            'name'               => 'string',
            'description'        => '?string',
            'phase'              => self::PHASES,
            'starts_at'          => 'iso',
            'ends_at'            => 'iso',
            'timezone'           => 'string',
            'rules'              => self::rules(),
            'packs_count'        => 'int',
            'participants_count' => 'int',
            'me'                 => self::nullable(['rank' => 'int', 'total_score' => 'int', 'packs_completed' => 'int']),
        ];
    }

    private static function entry(): array
    {
        return [
            'rank'            => 'int',
            'user_id'         => 'int',
            'username'        => 'string',
            'avatar_url'      => '?string',
            'total_score'     => 'int',
            'packs_completed' => 'int',
            'attempts_used'   => 'int',
            'is_current_user' => 'bool',
        ];
    }

    private static function leaderboardMeta(): array
    {
        return self::cursorMeta() + [
            'total_players'          => 'int',
            'current_user_rank'      => '?int',
            'current_user_score'     => '?int',
            'better_than_percentage' => '?number',
            'top_users'              => self::listOf(self::entry()),
            'nearby_users'           => self::listOf(self::entry()),
        ];
    }

    private static function clubDetail(): array
    {
        return self::clubCard() + [
            'description'          => '?string',
            'region'               => '?string',
            'members_count'        => 'int',
            'abilities'            => ['view_members' => 'bool', 'leave' => 'bool', 'manage' => 'bool'],
            'packs_preview'        => self::listOf(self::clubPackCard()),
            'competitions_preview' => [
                'active'          => self::listOf(self::competitionCard()),
                'upcoming'        => self::listOf(self::competitionCard()),
                'completed_count' => 'int',
            ],
            'leaderboard_preview'  => [
                'top' => self::listOf(self::entry()),
                'me'  => self::nullable(self::entry()),
            ],
        ];
    }

    private static function memberSchema(): array
    {
        return ['user_id' => 'int', 'username' => 'string', 'avatar_url' => '?string', 'role' => self::ROLES, 'joined_at' => '?iso'];
    }

    private static function packState(): array
    {
        return [
            'attempts_used'       => 'int',
            'attempts_remaining'  => 'int',
            'next_attempt_number' => '?int',
            'can_start'           => 'bool',
            'blocked_reason'      => self::BLOCKED,
            'active_attempt_id'   => '?int',
            'counted_score'       => '?int',
            'best_score'          => '?int',
            'first_score'         => '?int',
        ];
    }

    private static function competitionDetail(): array
    {
        return self::competitionCard() + [
            'packs' => self::listOf([
                'id'              => 'int',
                'slug'            => 'string',
                'name'            => 'string',
                'cover_image_url' => '?string',
                'challenge_count' => 'int',
                'state'           => self::packState(),
            ]),
            'my_entry' => self::nullable([
                'rank'             => 'int',
                'total_score'      => 'int',
                'packs_completed'  => 'int',
                'attempts_used'    => 'int',
                'score_reached_at' => '?iso',
            ]),
            'leaderboard_preview' => self::listOf(self::entry()),
        ];
    }

    private static function competitionAttempt(): array
    {
        return [
            'id'               => 'int',
            'competition_id'   => 'string',
            'pack_slug'        => 'string',
            'attempt_number'   => 'int',
            'status'           => 'enum:active|completed|expired|voided',
            'current_index'    => 'int',
            'completed_count'  => 'int',
            'total_challenges' => 'int',
            'total_score'      => 'int',
            'is_rewarded_run'  => 'bool',
            'started_at'       => '?iso',
            'completed_at'     => '?iso',
        ];
    }

    private static function packAttemptState(): array
    {
        return [
            'id'               => 'int',
            'status'           => 'enum:active|completed|abandoned',
            'current_index'    => 'int',
            'total_score'      => 'int',
            'completed_count'  => 'int',
            'total_challenges' => 'int',
            'started_at'       => self::optional('?iso'),
            'completed_at'     => self::optional('?iso'),
        ];
    }

    /** PackGuessResult minus `progress` (shared by the normal and competition guess). */
    private static function guessBase(): array
    {
        return [
            'result' => [
                'score'            => 'number',
                'distance'         => 'number',
                'guessed_x'        => 'number',
                'guessed_y'        => 'number',
                'ball_x_ratio'     => 'number',
                'ball_y_ratio'     => 'number',
                'reveal_image_url' => '?string',
                'sport'            => self::optional(self::nullable(self::sport())),
            ],
            'next_challenge' => self::nullable(self::challenge()),
            'rank_progress'  => ['xp_gained' => 'number', 'rank' => 'object'],
            'rank_up'        => '?object',
            'new_badges'     => 'list',
            'pack_completed' => 'bool',
            'final_score'    => '?number',
            'completion_xp'  => '?number',
        ];
    }

    private static function clubFinish(): array
    {
        return [
            'competition'   => ['id' => 'string', 'name' => 'string'],
            'club'          => ['id' => 'string', 'name' => 'string', 'logo_url' => '?string'],
            'placement'     => 'int',
            'total_score'   => 'int',
            'total_players' => 'int',
            'finalized_at'  => '?iso',
        ];
    }

    private static function page(array $item, array $meta): array
    {
        return ['data' => self::listOf($item), 'meta' => $meta];
    }

    // ------------------------------------------------------------------
    // Shape assertion
    // ------------------------------------------------------------------

    private function assertShape(mixed $value, mixed $spec, string $path = '$'): void
    {
        if (is_string($spec)) {
            $this->assertScalar($value, $spec, $path);

            return;
        }
        if (array_key_exists('__optional', $spec)) {
            $this->assertShape($value, $spec['__optional'], $path);

            return;
        }
        if (array_key_exists('__nullable', $spec)) {
            if ($value !== null) {
                $this->assertShape($value, $spec['__nullable'], $path);
            }

            return;
        }
        if (array_key_exists('__list', $spec)) {
            $this->assertIsArray($value, "{$path} must be an array");
            $this->assertTrue(array_is_list($value), "{$path} must be a JSON array, not an object");
            foreach ($value as $i => $item) {
                $this->assertShape($item, $spec['__list'], "{$path}[{$i}]");
            }

            return;
        }

        $this->assertIsArray($value, "{$path} must be an object");
        foreach ($spec as $key => $sub) {
            $optional = is_array($sub) && array_key_exists('__optional', $sub);
            if (!array_key_exists($key, $value)) {
                $this->assertTrue($optional, "{$path}.{$key} is missing (the app reads it)");

                continue;
            }
            $this->assertShape($value[$key], $sub, "{$path}.{$key}");
        }
    }

    private function assertScalar(mixed $value, string $type, string $path): void
    {
        if (str_starts_with($type, '?')) {
            if ($value === null) {
                return;
            }
            $type = substr($type, 1);
        }
        $this->assertNotNull($value, "{$path} must not be null");

        if (str_starts_with($type, 'enum:')) {
            $allowed = explode('|', substr($type, 5));
            $this->assertContains($value, $allowed, "{$path} = " . json_encode($value) . ' is not one of ' . implode('|', $allowed));

            return;
        }

        match ($type) {
            'string' => $this->assertIsString($value, "{$path} must be a string"),
            'int'    => $this->assertIsInt($value, "{$path} must be an integer"),
            'number' => $this->assertTrue(is_int($value) || is_float($value), "{$path} must be a number"),
            'bool'   => $this->assertIsBool($value, "{$path} must be a boolean"),
            'list'   => $this->assertTrue(is_array($value) && array_is_list($value), "{$path} must be a JSON array"),
            'object' => $this->assertTrue(is_array($value) && ($value === [] || !array_is_list($value)), "{$path} must be an object"),
            // Date.parse() in the app: ISO 8601 with an explicit UTC offset.
            'iso'    => $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', (string) $value, "{$path} must be an ISO 8601 date"),
            default  => $this->fail("Unknown schema type {$type} at {$path}"),
        };
    }

    private function assertResponseShape(TestResponse $response, array $spec): array
    {
        $json = $response->json();
        $this->assertShape($json, $spec);

        return $json;
    }

    /** `{ message, code }` — the body the app's clubErrors.ts reads. */
    private function assertClubError(TestResponse $response, int $status, string $code): TestResponse
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', trim((string) $response->json('message')));

        return $response;
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function richClub(): void
    {
        $this->club->forceFill([
            'sport_id'    => $this->clubSport()->id,
            'description' => 'The club of the town',
            'region'      => 'Oost-Vlaanderen',
            'settings'    => ['member_list_visibility' => 'members'],
        ])->save();
    }

    private function startNormal(User $user, ChallengePack $pack): TestResponse
    {
        return $this->api($user)->postJson($this->packPath($this->club, $pack, '/start'));
    }

    private function guessNormal(User $user, int $attemptId): TestResponse
    {
        $attempt = PackAttempt::findOrFail($attemptId);

        return $this->api($user)->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $attempt->challengeIds()[$attempt->current_index],
            'guessed_x'    => 0.6,
            'guessed_y'    => 0.5,
        ]);
    }

    /**
     * Walk a cursor list the way usePaginatedSearch does (pass meta.next_cursor
     * back as `cursor` until it is null) and return every item.
     */
    private function walk(User $user, string $path, string $key, int $limit): array
    {
        $items  = [];
        $cursor = null;
        $pages  = 0;
        do {
            $sep = str_contains($path, '?') ? '&' : '?';
            $url = $path . $sep . 'limit=' . $limit . ($cursor !== null ? '&cursor=' . urlencode($cursor) : '');
            $res = $this->api($user)->getJson($url)->assertOk();
            $this->assertShape($res->json('meta'), self::cursorMeta(), "{$path}.meta");
            $this->assertSame($limit, $res->json('meta.per_page'));
            $this->assertLessThanOrEqual($limit, count($res->json('data')));
            foreach ($res->json('data') as $row) {
                $items[] = $row;
            }
            $cursor = $res->json('meta.next_cursor');
            $this->assertLessThan(20, ++$pages, 'the cursor chain must end');
        } while ($cursor !== null);

        $keys = array_column($items, $key);
        $this->assertSame($keys, array_values(array_unique($keys)), "{$path}: a row appeared on two pages");

        return $items;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_config_exposes_the_clubs_feature_flag_as_a_boolean(): void
    {
        $this->getJson('/api/config')->assertOk()->assertJsonPath('features.clubs', true);
        $this->enableClubs(false);
        $this->getJson('/api/config')->assertOk()->assertJsonPath('features.clubs', false);
    }

    public function test_member_api_payloads_match_the_mobile_types(): void
    {
        $this->richClub();
        $player = User::factory()->create(['username' => 'player']);
        $rival  = $this->member('rival');
        ClubInviteCode::factory()->create(['club_id' => $this->club->id, 'code' => 'ABCDEFGH']);

        $normal   = $this->makeClubPack($this->club, 2, ['description' => 'Warm-up', 'difficulty' => 'mixed', 'is_featured' => true]);
        $reserved = $this->makeClubPack($this->club, 2);
        $live     = $this->liveCompetition([$reserved], ['name' => 'Autumn Cup', 'description' => 'Derby week'] + $this->multiple(2, 'best'));
        $this->liveCompetition([$reserved], ['name' => 'Winter Cup', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(9)]);

        // POST /api/clubs/join — the app sends the formatted "ABCD-EFGH".
        $join = $this->assertResponseShape(
            $this->api($player)->postJson('/api/clubs/join', ['code' => 'abcd-efgh'])->assertOk(),
            ['data' => self::clubCard(), 'joined' => 'bool'],
        );
        $this->assertTrue($join['joined']);
        $this->assertSame($this->club->public_id, $join['data']['id']);
        $this->assertSame(['active' => 1, 'upcoming' => 1], $join['data']['competitions']);
        $this->assertFalse($this->api($player)->postJson('/api/clubs/join', ['code' => 'ABCDEFGH'])->assertOk()->json('joined'));

        // Normal track on a club pack (start → attempt → guess on /pack-attempts).
        $start = $this->assertResponseShape($this->startNormal($player, $normal)->assertOk(), [
            'attempt' => self::packAttemptState(), 'challenge' => self::nullable(self::challenge()),
        ]);
        $this->assertNotNull($start['challenge']);
        $this->assertResponseShape($this->api($player)->getJson($this->packPath($this->club, $normal, '/attempt'))->assertOk(), [
            'attempt' => self::nullable(self::packAttemptState()), 'challenge' => self::nullable(self::challenge()),
            'completion' => self::optional('?object'),
        ]);
        $this->assertResponseShape($this->guessNormal($player, $start['attempt']['id'])->assertOk(), self::guessBase() + ['progress' => self::packAttemptState()]);
        $last = $this->assertResponseShape($this->guessNormal($player, $start['attempt']['id'])->assertOk(), self::guessBase() + ['progress' => self::packAttemptState()]);
        $this->assertTrue($last['pack_completed']);

        // Competition track: start (201) → GET attempt → guess → idempotent retry.
        $started = $this->assertResponseShape(
            $this->api($player)->postJson($this->competitionPath($live, '/packs/' . $reserved->slug . '/attempts'), ['attempt_number' => 1])->assertCreated(),
            ['attempt' => self::competitionAttempt(), 'challenge' => self::nullable(self::challenge())],
        );
        $attemptId = $started['attempt']['id'];
        $this->assertSame(1, $started['attempt']['attempt_number']);
        $this->assertSame(
            $started['attempt'],
            $this->api($player)->postJson($this->competitionPath($live, '/packs/' . $reserved->slug . '/attempts'), ['attempt_number' => 1])->assertOk()->json('attempt'),
            'same attempt_number again = the same attempt (200)',
        );
        $this->assertResponseShape($this->api($player)->getJson("/api/club-competition-attempts/{$attemptId}")->assertOk(), [
            'attempt' => self::competitionAttempt(), 'challenge' => self::nullable(self::challenge()),
        ]);

        $competitionGuess = self::guessBase() + [
            'progress'        => self::competitionAttempt(),
            'replayed'        => 'bool',
            'is_rewarded_run' => 'bool',
            'already_completed' => 'bool',
            'competition'     => ['counted_score' => '?int', 'total_score' => '?int', 'rank' => '?int', 'attempts_remaining' => '?int'],
        ];
        $challengeId = ClubCompetitionAttempt::findOrFail($attemptId)->challengeIds()[0];
        $body        = ['challenge_id' => $challengeId, 'guessed_x' => 0.58, 'guessed_y' => 0.5];
        $first       = $this->assertResponseShape($this->api($player)->postJson("/api/club-competition-attempts/{$attemptId}/guess", $body)->assertOk(), $competitionGuess);
        $this->assertFalse($first['replayed']);
        $this->assertSame(1, $first['competition']['rank']);
        $retry = $this->assertResponseShape($this->api($player)->postJson("/api/club-competition-attempts/{$attemptId}/guess", $body)->assertOk(), $competitionGuess);
        $this->assertTrue($retry['replayed']);
        // The replay is read back from the stored row (distance is rounded by the column).
        $this->assertEqualsWithDelta($first['result']['distance'], $retry['result']['distance'], 0.0001);
        unset($first['result']['distance'], $retry['result']['distance']);
        $this->assertSame($first['result'], $retry['result']);
        $this->tick();
        $this->play($rival, $live, $reserved, [100, 100]);

        // GET /api/clubs (My Clubs) with the params clubApi.list sends.
        $this->assertResponseShape(
            $this->api($player)->getJson('/api/clubs?q=' . urlencode(mb_substr($this->club->name, 0, 6)) . '&sort=name&limit=10')->assertOk(),
            self::page(self::clubCard(), self::cursorMeta() + ['total_joined' => 'int', 'query' => '?string']),
        );

        // GET /api/clubs/{club}
        $detail = $this->assertResponseShape($this->api($player)->getJson($this->clubPath($this->club))->assertOk(), ['data' => self::clubDetail()])['data'];
        $this->assertSame(['slug' => 'football', 'name' => 'Football', 'emoji' => '⚽'], $detail['sport']);
        $this->assertSame(['view_members' => true, 'leave' => true, 'manage' => false], $detail['abilities']);
        $this->assertCount(1, $detail['competitions_preview']['active']);
        $this->assertNotNull($detail['competitions_preview']['active'][0]['me']);
        $this->assertCount(1, $detail['competitions_preview']['upcoming']);
        $this->assertNotNull($detail['leaderboard_preview']['me']);
        $packCards = collect($detail['packs_preview'])->keyBy('slug');
        $this->assertSame('completed', $packCards[$normal->slug]['progress']['status']);
        $this->assertIsInt($packCards[$normal->slug]['best_score']);
        $this->assertTrue($packCards[$reserved->slug]['reserved_for_competition']);
        $this->assertSame(['active', 'upcoming'], array_column($packCards[$reserved->slug]['competitions'], 'phase'));

        // Members, packs, pack detail.
        $this->assertResponseShape(
            $this->api($player)->getJson($this->clubPath($this->club, '/members?q=pla&limit=5'))->assertOk(),
            self::page(self::memberSchema(), self::cursorMeta() + ['total' => 'int']),
        );
        $this->assertResponseShape($this->api($player)->getJson($this->clubPath($this->club, '/packs?limit=5'))->assertOk(), self::page(self::clubPackCard(), self::cursorMeta()));
        $packDetail = $this->assertResponseShape($this->api($player)->getJson($this->packPath($this->club, $normal))->assertOk(), ['data' => self::clubPackDetail()])['data'];
        $this->assertCount(2, $packDetail['challenges']);
        $reservedDetail = $this->assertResponseShape($this->api($player)->getJson($this->packPath($this->club, $reserved))->assertOk(), ['data' => self::clubPackDetail()])['data'];
        $this->assertSame([], $reservedDetail['challenges'], 'no photo preview while reserved');

        // Club leaderboard.
        $board = $this->assertResponseShape(
            $this->api($player)->getJson($this->clubPath($this->club, '/leaderboard?limit=10'))->assertOk(),
            self::page(self::entry(), self::leaderboardMeta()),
        );
        $this->assertSame(2, $board['meta']['total_players']);
        $this->assertIsInt($board['meta']['current_user_rank']);

        // Competitions: list per phase (the three values the app sends), detail, leaderboard, my attempts.
        foreach (['active', 'upcoming', 'completed'] as $phase) {
            $this->assertResponseShape(
                $this->api($player)->getJson($this->clubPath($this->club, "/competitions?phase={$phase}&limit=3"))->assertOk(),
                self::page(self::competitionCard(), self::cursorMeta()),
            );
        }
        $compDetail = $this->assertResponseShape($this->api($player)->getJson($this->competitionPath($live))->assertOk(), ['data' => self::competitionDetail()])['data'];
        $state = $compDetail['packs'][0]['state'];
        $this->assertSame($attemptId, $state['active_attempt_id']);
        $this->assertSame('attempt_in_progress', $state['blocked_reason']);
        $this->assertNotNull($compDetail['my_entry']);

        $this->assertResponseShape(
            $this->api($player)->getJson($this->competitionPath($live, '/leaderboard?limit=10'))->assertOk(),
            self::page(self::entry(), self::leaderboardMeta() + ['rules' => self::rules(), 'phase' => self::PHASES]),
        );
        $mine = $this->assertResponseShape(
            $this->api($player)->getJson($this->competitionPath($live, '/me/attempts'))->assertOk(),
            ['data' => self::listOf(self::competitionAttempt())],
        );
        $this->assertSame([$attemptId], array_column($mine['data'], 'id'));

        // POST /api/clubs/{club}/leave
        $this->assertResponseShape($this->api($rival)->postJson($this->clubPath($this->club, '/leave'))->assertOk(), ['left' => 'bool']);
    }

    public function test_club_finishes_match_the_trophy_room_type(): void
    {
        $pack   = $this->makeClubPack($this->club, 1);
        $cup    = $this->liveCompetition([$pack], ['name' => 'Short Cup', 'ends_at' => now()->addHour()]);
        $player = $this->member('player');
        $this->play($player, $cup, $pack, [70]);
        $this->assertResponseShape($this->api($player)->getJson('/api/me/club-finishes')->assertOk(), ['data' => self::listOf(self::clubFinish())]);

        Carbon::setTestNow(now()->addHours(2));
        app(ClubCompetitionCloseService::class)->finalize($cup);

        $finishes = $this->assertResponseShape($this->api($player)->getJson('/api/me/club-finishes')->assertOk(), ['data' => self::listOf(self::clubFinish())]);
        $this->assertCount(1, $finishes['data']);
        $this->assertSame(1, $finishes['data'][0]['placement']);

        // A finalized competition is listed under phase=completed with phase "finalized".
        $completed = $this->api($player)->getJson($this->clubPath($this->club, '/competitions?phase=completed'))->assertOk();
        $this->assertSame('finalized', $completed->json('data.0.phase'));

        // Clubs switched off: the Trophy Room call fails with 404 (the app hides the section).
        $this->enableClubs(false);
        $this->assertClubError($this->api($player)->getJson('/api/me/club-finishes'), 404, 'feature_disabled');
    }

    public function test_error_bodies_carry_the_codes_the_app_maps(): void
    {
        $member   = $this->member('member');
        $outsider = User::factory()->create();
        $pack     = $this->makeClubPack($this->club, 2);
        $free     = $this->makeClubPack($this->club, 1);
        $live     = $this->liveCompetition([$pack]);
        $soon     = $this->liveCompetition([$free], ['starts_at' => now()->addDay(), 'normal_play_policy' => 'allowed']);

        // 404 club_not_found: outsider, unknown id, unknown pack slug.
        $this->assertClubError($this->api($outsider)->getJson($this->clubPath($this->club)), 404, 'club_not_found');
        $this->assertClubError($this->api($member)->getJson('/api/clubs/01JZZZZZZZZZZZZZZZZZZZZZZZ'), 404, 'club_not_found');
        $this->assertClubError($this->api($member)->getJson($this->clubPath($this->club, '/packs/no-such-pack')), 404, 'club_not_found');

        // 403 club_forbidden: the member list is hidden by default (D9).
        $this->assertClubError($this->api($member)->getJson($this->clubPath($this->club, '/members')), 403, 'club_forbidden');

        // 409 pack_reserved_for_competition (normal start) — a 409 WITH a code, so
        // isPackAlreadyCompleted() in the app does not read it as the replay 409.
        $this->assertClubError($this->startNormal($member, $pack), 409, 'pack_reserved_for_competition');

        // 422 competition_not_started.
        $this->assertClubError(
            $this->api($member)->postJson($this->competitionPath($soon, '/packs/' . $free->slug . '/attempts'), ['attempt_number' => 1]),
            422, 'competition_not_started',
        );

        // Attempt flow errors: unexpected_challenge, attempt_not_active, competition_attempt_limit.
        $id = $this->play($member, $live, $pack, [90, 90]);
        $attempt = ClubCompetitionAttempt::findOrFail($id);
        $this->assertClubError($this->api($member)->postJson("/api/club-competition-attempts/{$id}/guess", [
            'challenge_id' => $attempt->challengeIds()[0] + 100000, 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ]), 409, 'attempt_not_active');
        $this->assertClubError(
            $this->api($member)->postJson($this->competitionPath($live, '/packs/' . $pack->slug . '/attempts'), ['attempt_number' => 2]),
            422, 'competition_attempt_limit',
        );
        $other = $this->member('other');
        $otherId = (int) $this->startAttempt($other, $live, $pack)->assertCreated()->json('attempt.id');
        $this->assertClubError($this->api($other)->postJson("/api/club-competition-attempts/{$otherId}/guess", [
            'challenge_id' => ClubCompetitionAttempt::findOrFail($otherId)->challengeIds()[1], 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ]), 422, 'unexpected_challenge');
        // Someone else's attempt: 404, never 403.
        $this->assertClubError($this->api($member)->getJson("/api/club-competition-attempts/{$otherId}"), 404, 'club_not_found');

        // 423 club_unavailable (suspended club).
        $this->club->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();
        $this->assertClubError($this->startNormal($member, $free), 423, 'club_unavailable');

        // 404 feature_disabled.
        $this->enableClubs(false);
        $this->assertClubError($this->api($member)->getJson('/api/clubs'), 404, 'feature_disabled');
        $this->assertClubError($this->api($member)->getJson($this->clubPath($this->club)), 404, 'feature_disabled');
    }

    public function test_stale_attempt_number_is_a_field_error_the_app_can_detect(): void
    {
        $pack   = $this->makeClubPack($this->club, 1);
        $live   = $this->liveCompetition([$pack], $this->multiple(3, 'best'));
        $player = $this->member('player');
        $this->play($player, $live, $pack, [50]);

        // The app still shows "Start attempt 1"'s successor from a stale screen and asks for 3.
        $this->api($player)->postJson($this->competitionPath($live, '/packs/' . $pack->slug . '/attempts'), ['attempt_number' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('attempt_number');
    }

    public function test_join_throttles_answer_429_with_a_code_and_retry_after(): void
    {
        config(['ballspot.clubs.join.per_user_per_minute' => 2, 'ballspot.clubs.join.failures_per_hour' => 1]);
        $user = User::factory()->create();

        $this->assertClubError($this->api($user)->postJson('/api/clubs/join', ['code' => 'ZZZZ-ZZZZ']), 422, 'club_code_invalid');
        $locked = $this->assertClubError($this->api($user)->postJson('/api/clubs/join', ['code' => 'ZZZZ-ZZZZ']), 429, 'club_join_locked');
        $this->assertIsInt($locked->json('retry_after'));
        $this->assertNotNull($locked->headers->get('Retry-After'));

        $limited = $this->api($user)->postJson('/api/clubs/join', ['code' => 'ZZZZ-ZZZZ'])->assertStatus(429)
            ->assertJsonPath('code', 'club_join_rate_limited');
        $this->assertNotNull($limited->headers->get('Retry-After'), 'the app reads Retry-After for "try again in N seconds"');
    }

    public function test_cursor_pagination_end_to_end_as_the_app_walks_it(): void
    {
        $this->richClub();
        $player = $this->member('player');

        // My Clubs: 5 clubs, both sorts, with and without q.
        $names = [];
        for ($i = 1; $i <= 4; $i++) {
            $club = $this->makeClub(['name' => "Paging Club {$i}", 'city' => $i % 2 ? 'Gent' : 'Brugge']);
            $this->addMember($club, $player, ClubRole::Member);
            $names[] = $club->name;
        }
        $names[] = $this->club->name;
        foreach (['recent', 'name'] as $sort) {
            $all = $this->walk($player, "/api/clubs?sort={$sort}", 'id', 2);
            $this->assertCount(5, $all, "My Clubs sort={$sort}");
            if ($sort === 'name') {
                $sorted = array_column($all, 'name');
                $expected = $sorted;
                usort($expected, fn ($a, $b) => strcasecmp($a, $b));
                $this->assertSame($expected, $sorted);
            }
        }
        $this->assertCount(2, $this->walk($player, '/api/clubs?q=Gent&sort=name', 'id', 1));
        $this->assertCount(4, $this->walk($player, '/api/clubs?q=' . urlencode('Paging Club'), 'id', 3));

        // Members (+ q), packs, club leaderboard, competition list + leaderboard.
        $packs = [];
        for ($i = 0; $i < 5; $i++) {
            $packs[] = $this->makeClubPack($this->club, 1);
        }
        $live = $this->liveCompetition([$packs[0]], ['normal_play_policy' => 'allowed']);
        for ($i = 0; $i < 5; $i++) {
            $u = $this->member('pager' . $i);
            $attemptId = (int) $this->startNormal($u, $packs[1])->assertOk()->json('attempt.id');
            $this->tick();
            $this->guessNormal($u, $attemptId)->assertOk();
            $this->play($u, $live, $packs[0], [10 * ($i + 1)]);
        }
        $this->assertCount(7, $this->walk($player, $this->clubPath($this->club, '/members'), 'user_id', 3));
        $this->assertCount(5, $this->walk($player, $this->clubPath($this->club, '/members?q=pager'), 'user_id', 2));
        $this->assertCount(5, $this->walk($player, $this->clubPath($this->club, '/packs'), 'id', 2));
        $board = $this->walk($player, $this->clubPath($this->club, '/leaderboard'), 'user_id', 2);
        $this->assertCount(5, $board);
        $this->assertSame(array_column($board, 'rank'), collect($board)->pluck('rank')->sort()->values()->all());
        $compBoard = $this->walk($player, $this->competitionPath($live, '/leaderboard'), 'user_id', 2);
        $this->assertSame([50, 40, 30, 20, 10], array_column($compBoard, 'total_score'));
        $this->liveCompetition([$packs[2]], ['normal_play_policy' => 'allowed']);
        $this->liveCompetition([$packs[3]], ['normal_play_policy' => 'allowed']);
        $this->assertCount(3, $this->walk($player, $this->clubPath($this->club, '/competitions?phase=active'), 'id', 2));

        // `limit` is clamped, not rejected; no limit = the default page size.
        $this->api($player)->getJson('/api/clubs?limit=500')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->api($player)->getJson('/api/clubs')->assertOk()->assertJsonPath('meta.per_page', 20);

        // A broken cursor is a 422 on `cursor` (the app shows its retry row).
        foreach (['/api/clubs', $this->clubPath($this->club, '/leaderboard'), $this->clubPath($this->club, '/packs')] as $path) {
            $this->api($player)->getJson($path . '?cursor=not-a-cursor')->assertStatus(422)->assertJsonValidationErrors('cursor');
        }
    }

    /**
     * Every club code a member can receive must have copy in the app
     * (mobile/src/utils/clubErrors.ts CLUB_ERROR_CODES). club_join_rate_limited
     * is deliberately left to the client's generic 429 copy ("try again in N
     * seconds", from Retry-After).
     */
    public function test_every_member_facing_error_code_is_mapped_by_the_app(): void
    {
        $file = base_path('../mobile/src/utils/clubErrors.ts');
        if (!is_file($file)) {
            $this->markTestSkipped('mobile/ is not checked out next to backend/');
        }
        preg_match('/CLUB_ERROR_CODES\s*=\s*\[(.*?)\]\s*as const/s', (string) file_get_contents($file), $m);
        $this->assertNotEmpty($m, 'CLUB_ERROR_CODES not found in clubErrors.ts');
        preg_match_all("/'([a-z_]+)'/", $m[1], $codes);

        $memberFacing = [
            'feature_disabled', 'club_not_found', 'club_forbidden', 'club_code_invalid', 'club_join_locked', 'club_full',
            'club_membership_limit', 'club_unavailable', 'club_owner_must_transfer', 'club_last_owner',
            'pack_reserved_for_competition', 'competition_not_started', 'competition_ended', 'competition_attempt_limit',
            'competition_pack_already_played', 'competition_attempt_in_progress', 'competition_invalid_pack',
            'unexpected_challenge', 'attempt_not_active',
        ];
        $this->assertSame([], array_values(array_diff($memberFacing, $codes[1])), 'codes the app has no copy for');
    }
}
