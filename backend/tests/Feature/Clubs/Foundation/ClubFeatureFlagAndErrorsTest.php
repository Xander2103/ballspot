<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Exceptions\ClubDomainException;
use App\Models\User;
use App\Support\AuthAbuseGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Feature flag (features.clubs + `feature:clubs`), the ClubDomainException
 * contract (§3.3) and the `club-join` limiter wiring.
 */
class ClubFeatureFlagAndErrorsTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    private const LANGUAGES = ['en', 'nl', 'fr', 'de', 'es'];

    /** Every code of contracts §3.3 (+ Foundation additions) with its HTTP status. */
    private const CODES = [
        'feature_disabled' => 404, 'club_not_found' => 404, 'club_forbidden' => 403, 'club_code_invalid' => 422,
        'club_join_locked' => 429, 'club_full' => 422, 'club_membership_limit' => 422, 'club_unavailable' => 423,
        'club_owner_must_transfer' => 422, 'club_last_owner' => 422, 'club_has_history' => 422, 'club_code_limit' => 422,
        'club_status_invalid' => 422, 'club_member_not_found' => 404, 'club_member_banned' => 422,
        'stale_version' => 409, 'pack_reserved_for_competition' => 409, 'pack_in_competition' => 422, 'competition_not_started' => 422,
        'competition_ended' => 422, 'competition_attempt_limit' => 422, 'competition_pack_already_played' => 422,
        'competition_attempt_in_progress' => 409, 'competition_rules_locked' => 422, 'competition_invalid_pack' => 422,
        'competition_not_editable' => 422, 'unexpected_challenge' => 422, 'attempt_not_active' => 409,
        'photo_invalid' => 422, 'photo_duplicate' => 422, 'portal_two_factor_required' => 403,
        // Photos (D1).
        'photo_status_invalid' => 422, 'photo_rights_required' => 422, 'photo_pending_limit' => 422, 'photo_pack_invalid' => 422,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'feature:clubs'])->get('/api/test-clubs-probe', fn () => response()->json(['ok' => true]));
        // `_` keeps the probe out of the portal's /club/{club:slug} pattern (D2).
        Route::middleware(['web', 'feature:clubs'])->get('/club/_test-probe', fn () => 'portal');
        Route::middleware('api')->get('/api/test-club-error/{code}/{status}', function (string $code, int $status) {
            throw new ClubDomainException($code, $status, $code === 'club_join_locked' ? ['retry_after' => 30] : []);
        });
        Route::middleware('web')->post('/admin/test-club-error', function () {
            throw new ClubDomainException('club_has_history', 422);
        });
    }

    // ------------------------------------------------------------ flag

    public function test_config_exposes_the_clubs_flag(): void
    {
        $this->enableClubs(false);
        $this->getJson('/api/config')->assertOk()->assertJsonPath('features.clubs', false);

        $this->enableClubs(true);
        $this->getJson('/api/config')->assertOk()->assertJsonPath('features.clubs', true);
    }

    public function test_the_flag_is_off_by_default(): void
    {
        // The shipped default (independent of whatever a local .env sets).
        $this->assertStringContainsString(
            "'enabled'  => (bool) env('BALLSPOT_CLUBS_ENABLED', false)",
            file_get_contents(config_path('ballspot.php')),
        );
    }

    public function test_feature_middleware_answers_404_feature_disabled_when_off(): void
    {
        $this->enableClubs(false);

        $this->getJson('/api/test-clubs-probe')
            ->assertNotFound()
            ->assertExactJson(['message' => __('clubs.errors.feature_disabled'), 'code' => 'feature_disabled']);
        $this->get('/club/_test-probe')->assertNotFound();

        $this->enableClubs(true);
        $this->getJson('/api/test-clubs-probe')->assertOk()->assertJsonPath('ok', true);
        $this->get('/club/_test-probe')->assertOk()->assertSee('portal');
    }

    public function test_the_member_api_group_is_behind_the_flag_inside_the_verified_group(): void
    {
        $user = User::factory()->create();
        $this->enableClubs(false);

        // Registered later by the Member API agent; the group's middleware is what matters here.
        $route = collect(Route::getRoutes())->first(fn ($r) => str_starts_with($r->uri(), 'api/clubs'));
        if ($route !== null) {
            $this->assertContains('feature:clubs', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
        }

        // Public surfaces keep working with the flag off or on (AT-BC-01 smoke).
        $pack = $this->makePublicPack(1);
        foreach ([false, true] as $on) {
            $this->enableClubs($on);
            $this->withToken($this->clubToken($user))->getJson('/api/packs')->assertOk()->assertJsonPath('data.0.slug', $pack->slug);
        }
    }

    // ------------------------------------------------------------ errors

    public function test_every_error_code_has_a_translation_in_every_language(): void
    {
        foreach (self::LANGUAGES as $lang) {
            foreach (array_merge(array_keys(self::CODES), ['unknown']) as $code) {
                $key = 'clubs.errors.' . $code;
                $this->assertTrue(\Illuminate\Support\Facades\Lang::has($key, $lang, false), "lang/{$lang}/clubs.php misses {$key}");
                $this->assertNotSame('', trim((string) __($key, [], $lang)));
            }
        }

        // The constants on the exception class cover exactly this list.
        $constants = array_values((new \ReflectionClass(ClubDomainException::class))->getConstants());
        $this->assertEqualsCanonicalizing(array_keys(self::CODES), $constants);
    }

    public function test_api_rendering_is_message_code_and_extra_with_the_fixed_status(): void
    {
        foreach (self::CODES as $code => $status) {
            $res = $this->getJson("/api/test-club-error/{$code}/{$status}")
                ->assertStatus($status)
                ->assertJsonPath('code', $code)
                ->assertJsonPath('message', __('clubs.errors.' . $code));

            if ($code === 'club_join_locked') {
                $res->assertJsonPath('retry_after', 30)->assertHeader('Retry-After', '30');
            }
        }
    }

    public function test_api_messages_are_translated_per_locale_while_the_code_stays(): void
    {
        foreach (self::LANGUAGES as $lang) {
            $this->withHeaders(['Accept-Language' => $lang])
                ->getJson('/api/test-club-error/club_code_invalid/422')
                ->assertStatus(422)
                ->assertJsonPath('code', 'club_code_invalid')
                ->assertJsonPath('message', __('clubs.errors.club_code_invalid', [], $lang));
        }
        $this->assertNotSame(__('clubs.errors.club_code_invalid', [], 'en'), __('clubs.errors.club_code_invalid', [], 'nl'));
    }

    public function test_web_rendering_redirects_back_with_the_error_flash_and_input(): void
    {
        $this->from('/admin/clubs/1')
            ->post('/admin/test-club-error', ['name' => 'Kept', 'password' => 'never-flashed'])
            ->assertRedirect('/admin/clubs/1')
            ->assertSessionHas('error', __('clubs.errors.club_has_history'))
            ->assertSessionHasInput('name', 'Kept')
            ->assertSessionMissing('_old_input.password');
    }

    // ------------------------------------------------------------ club-join limiter

    public function test_club_join_limiter_is_registered_fail_closed_and_has_a_stable_429_code(): void
    {
        $this->assertContains('club-join', (array) config('ballspot.rate_limiter.fail_closed'));

        $user = User::factory()->create();
        $request = Request::create('/api/clubs/join', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->setUserResolver(fn () => $user);

        $limits = RateLimiter::limiter('club-join')($request);
        $this->assertCount(2, $limits);
        $this->assertContainsOnlyInstancesOf(Limit::class, $limits);
        $this->assertSame([10, 300], array_map(fn (Limit $l) => $l->maxAttempts, $limits));
        $this->assertSame([60, 3600], array_map(fn (Limit $l) => $l->decaySeconds, $limits));
        $this->assertSame('club-join-u|' . $user->id, $limits[0]->key);
        $this->assertSame('club-join-ip|203.0.113.9', $limits[1]->key);

        $context = AuthAbuseGuard::throttleContext($request);
        $this->assertSame('club_join_rate_limited', $context['code']);
        $this->assertSame('club_join.rate_limited', $context['event']);
    }

    public function test_the_signed_media_route_is_registered(): void
    {
        $route = Route::getRoutes()->getByName('clubs.media');

        $this->assertNotNull($route);
        $this->assertSame('media/clubs/{path}', $route->uri());
        $this->assertContains('signed', $route->gatherMiddleware());
    }
}
