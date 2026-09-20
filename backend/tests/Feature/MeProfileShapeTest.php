<?php

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\User;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * GET /api/me is the app's profile source. Its shape is a contract: every
 * field the mobile app reads must be present with a safe value for every
 * active user (fresh, admin, rows with NULL optional columns), whatever
 * Accept-Language the app sends. A failure inside the resource must never
 * be a raw 500 — it answers the stable `profile_unavailable` code and logs
 * `me.failed_exception` (id + exception class, no personal data).
 */
class MeProfileShapeTest extends TestCase
{
    use RefreshDatabase;

    /** Every key the mobile User type reads from /me. */
    private const REQUIRED_KEYS = [
        'id', 'name', 'username', 'email', 'email_verified', 'selected_theme',
        'preferred_language', 'two_factor_enabled', 'avatar_url', 'preferred_sport',
    ];

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function makeUser(array $overrides = []): User
    {
        $user = User::create(array_merge([
            'name'     => 'Sam Player',
            'username' => 'samplayer',
            'email'    => 'sam@example.com',
            'password' => Hash::make('password123'),
        ], $overrides));
        $user->markEmailAsVerified();

        return $user;
    }

    private function sport(): Sport
    {
        return Sport::create(['name' => 'Football', 'slug' => 'football', 'emoji' => '⚽', 'object_name' => 'ball', 'status' => 'active', 'sort_order' => 1]);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    public function test_me_returns_every_expected_field_for_a_normal_user(): void
    {
        $sport = $this->sport();
        $user  = $this->makeUser(['preferred_sport_id' => $sport->id, 'preferred_language' => 'nl']);
        $user->forceFill(['avatar_path' => 'avatars/sam.jpg'])->save();
        $token = $user->createToken('mobile')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/me')->assertOk();

        $res->assertJsonStructure(['data' => self::REQUIRED_KEYS]);
        $res->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Sam Player')
            ->assertJsonPath('data.username', 'samplayer')
            ->assertJsonPath('data.email', 'sam@example.com')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.selected_theme', 'pitch_green')
            ->assertJsonPath('data.preferred_language', 'nl')
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.preferred_sport.id', $sport->id)
            ->assertJsonPath('data.preferred_sport.slug', 'football');
        $this->assertStringEndsWith('/storage/avatars/sam.jpg', $res->json('data.avatar_url'));
    }

    public function test_me_returns_the_same_fields_for_an_admin_user_without_leaking_is_admin(): void
    {
        $admin = $this->makeUser(['username' => 'boss', 'email' => 'boss@example.com']);
        $admin->forceFill(['is_admin' => true])->save();
        $token = $admin->createToken('mobile')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/me')->assertOk();

        $res->assertJsonStructure(['data' => self::REQUIRED_KEYS])
            ->assertJsonPath('data.username', 'boss')
            ->assertJsonPath('data.preferred_sport', null)
            ->assertJsonMissingPath('data.is_admin')
            ->assertJsonMissingPath('data.friend_code');
    }

    public function test_me_uses_safe_defaults_when_optional_columns_are_null(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        // Straight to the table so model defaults cannot paper over it. Theme,
        // language and 2FA are NOT NULL with a DB default, so only the truly
        // nullable columns can be nulled here; the resource-level nulls (a row
        // read before those columns existed) are covered below.
        DB::table('users')->where('id', $user->id)->update([
            'avatar_path'        => null,
            'preferred_sport_id' => null,
        ]);

        $res = $this->withToken($token)->getJson('/api/me')->assertOk();

        $res->assertJsonStructure(['data' => self::REQUIRED_KEYS])
            ->assertJsonPath('data.selected_theme', 'pitch_green')
            ->assertJsonPath('data.preferred_language', 'en')
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonPath('data.preferred_sport', null);
    }

    public function test_the_resource_itself_defaults_every_optional_attribute_that_is_null_or_absent(): void
    {
        $user = $this->makeUser();
        // What Eloquent hands the resource when the columns do not exist yet
        // (a backend deployed ahead of its migration) or hold NULL.
        $user->setRawAttributes(array_merge($user->getAttributes(), [
            'selected_theme'     => null,
            'preferred_language' => null,
            'two_factor_enabled' => null,
            'avatar_path'        => null,
            'preferred_sport_id' => null,
            'name'               => null,
        ]));

        $request = \Illuminate\Http\Request::create('/api/me');
        $request->setUserResolver(fn () => $user);

        $data = (new \App\Http\Resources\UserResource($user))->toArray($request);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $data, "missing $key");
        }
        $this->assertSame('', $data['name']);
        $this->assertSame('pitch_green', $data['selected_theme']);
        $this->assertSame('en', $data['preferred_language']);
        $this->assertFalse($data['two_factor_enabled']);
        $this->assertNull($data['avatar_url']);
        $this->assertNull($data['preferred_sport']);
        $this->assertNotEmpty(json_encode($data));
    }

    public function test_me_does_not_500_for_any_accept_language_or_stored_language(): void
    {
        $user  = $this->makeUser(['preferred_language' => 'de']);
        $token = $user->createToken('mobile')->plainTextToken;

        foreach (['nl-BE,nl;q=0.9,en;q=0.8', 'fr_FR', '*', 'xx-YY', '', 'de'] as $header) {
            $this->actingWithToken($token)->withHeader('Accept-Language', $header)->getJson('/api/me')
                ->assertOk()
                ->assertJsonPath('data.preferred_language', 'de');
        }

        // actingWithToken: the test container caches the resolved user per
        // guard, so a plain second withToken() would keep the first row's values.
        foreach (['nl', 'en', 'fr', 'de', 'es', 'zz'] as $stored) {
            DB::table('users')->where('id', $user->id)->update(['preferred_language' => $stored, 'two_factor_enabled' => (int) ($stored === 'fr')]);
            $this->actingWithToken($token)->getJson('/api/me')
                ->assertOk()
                ->assertJsonPath('data.preferred_language', in_array($stored, ['nl', 'en', 'fr', 'de', 'es'], true) ? $stored : 'en')
                ->assertJsonPath('data.two_factor_enabled', $stored === 'fr');
        }
    }

    public function test_me_rejects_an_anonymized_user_with_the_stable_code(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        $user->forceFill(['anonymized_at' => now()])->save();

        $this->withToken($token)->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'account_deleted');
    }

    public function test_a_failure_inside_the_profile_answers_profile_unavailable_and_logs_the_category(): void
    {
        $sport = $this->sport();
        $user  = $this->makeUser(['preferred_sport_id' => $sport->id]);
        $token = $user->createToken('mobile')->plainTextToken;
        // Break the relation the resource loads (a query failure is the realistic
        // production shape: missing table/column after a bad deploy).
        Schema::rename('sports', 'sports_broken');

        $res = $this->withToken($token)->getJson('/api/me');

        $res->assertStatus(500)
            ->assertJsonPath('code', 'profile_unavailable')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
        $this->assertStringNotContainsString('sports_broken', $res->getContent());

        $log = $this->logged('me.failed_exception');
        $this->assertNotEmpty($log);
        $this->assertSame($user->id, $log[0]->context['user_id']);
        $this->assertSame('QueryException', $log[0]->context['exception']);
        $this->assertArrayNotHasKey('email', $log[0]->context);
    }

    public function test_a_failure_inside_profile_stats_answers_profile_unavailable_and_logs_it(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        Schema::rename('guesses', 'guesses_broken');

        $this->withToken($token)->getJson('/api/profile/stats')
            ->assertStatus(500)
            ->assertJsonPath('code', 'profile_unavailable')
            ->assertJsonMissingPath('exception');

        $log = $this->logged('profile.load_failed');
        $this->assertNotEmpty($log);
        $this->assertSame($user->id, $log[0]->context['user_id']);
        $this->assertSame('stats', $log[0]->context['section']);
    }
}
