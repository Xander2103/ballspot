<?php

namespace Tests\Feature;

use App\Models\AppNotice;
use App\Models\User;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Admin-managed Home / Daily-card notice: saved from Admin → Notices, read by
 * GET /api/notices/active in the caller's language.
 */
class AppNoticeTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function player(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['is_admin' => false], $overrides));
    }

    private function notice(array $overrides = []): AppNotice
    {
        return AppNotice::create(array_merge([
            'placement'  => AppNotice::PLACEMENT_HOME_DAILY_CARD,
            'enabled'    => true,
            'type'       => AppNotice::TYPE_INFO,
            'message_en' => 'Daily login starts tomorrow',
            'message_nl' => 'Dagelijks inloggen start morgen',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'placement'  => AppNotice::PLACEMENT_HOME_DAILY_CARD,
            'enabled'    => '1',
            'type'       => 'warning',
            'message_en' => 'New Daily Challenge starts soon',
            'message_nl' => 'Nieuwe dagelijkse uitdaging start binnenkort',
            'message_fr' => '',
            'message_de' => '',
            'message_es' => '',
            'starts_at'  => '',
            'ends_at'    => '',
        ], $overrides);
    }

    // --- admin ---------------------------------------------------------------

    public function test_admin_can_open_the_notices_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/notices')
            ->assertOk()
            ->assertSee('In-app notice')
            ->assertSee('Not shown');
    }

    public function test_admin_can_save_the_notice(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/notices', $this->payload())
            ->assertRedirect('/admin/notices')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('app_notices', [
            'placement'  => 'home_daily_card',
            'enabled'    => 1,
            'type'       => 'warning',
            'message_en' => 'New Daily Challenge starts soon',
            'message_nl' => 'Nieuwe dagelijkse uitdaging start binnenkort',
            'message_fr' => null,
        ]);
        $this->assertSame(1, AppNotice::count());

        // Saving again updates the same row (one notice per placement).
        $this->actingAs($this->admin())->put('/admin/notices', $this->payload(['enabled' => '0', 'message_en' => 'Changed']))->assertRedirect();
        $this->assertSame(1, AppNotice::count());
        $this->assertSame('Changed', AppNotice::first()->message_en);
        $this->assertFalse(AppNotice::first()->enabled);

        $saved = array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === 'notice.saved'));
        $this->assertNotEmpty($saved);
        $this->assertStringNotContainsString('New Daily Challenge', json_encode($saved[0]->context));
    }

    public function test_admin_cannot_enable_a_notice_without_any_message(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/notices')
            ->put('/admin/notices', $this->payload(['message_en' => '', 'message_nl' => '']))
            ->assertRedirect('/admin/notices')
            ->assertSessionHasErrors('message_en');

        $this->assertSame(0, AppNotice::count());
    }

    public function test_admin_input_is_validated(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/notices')
            ->put('/admin/notices', $this->payload(['type' => 'danger', 'placement' => 'login_screen', 'starts_at' => '2026-10-02 10:00', 'ends_at' => '2026-10-01 10:00', 'message_en' => str_repeat('x', 301)]))
            ->assertSessionHasErrors(['type', 'placement', 'ends_at', 'message_en']);
    }

    public function test_non_admin_cannot_edit_or_view_the_notice(): void
    {
        $this->actingAs($this->player())->put('/admin/notices', $this->payload())->assertStatus(403);
        $this->actingAs($this->player())->get('/admin/notices')->assertStatus(403);
        // Signed out: never saved, whether the guard answers a redirect or a 403.
        $guest = $this->put('/admin/notices', $this->payload());
        $this->assertContains($guest->status(), [302, 403]);
        $this->assertSame(0, AppNotice::count());
    }

    // --- public API ----------------------------------------------------------

    public function test_public_api_returns_the_active_notice_with_public_fields_only(): void
    {
        $this->notice(['type' => 'success', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $token = $this->player()->createToken('mobile')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/notices/active?placement=home_daily_card')->assertOk();

        $res->assertExactJson(['notice' => [
            'placement' => 'home_daily_card',
            'type'      => 'success',
            'message'   => 'Daily login starts tomorrow',
        ]]);
        $this->assertStringNotContainsString('enabled', $res->getContent());
        $this->assertStringNotContainsString('starts_at', $res->getContent());
        $this->assertStringNotContainsString('message_nl', $res->getContent());
    }

    public function test_public_api_defaults_to_the_home_daily_card_placement_and_requires_auth(): void
    {
        $this->notice();
        $this->getJson('/api/notices/active')->assertStatus(401);

        $token = $this->player()->createToken('mobile')->plainTextToken;
        $this->withToken($token)->getJson('/api/notices/active')->assertOk()->assertJsonPath('notice.message', 'Daily login starts tomorrow');
        $this->withToken($token)->getJson('/api/notices/active?placement=unknown')->assertOk()->assertJsonPath('notice', null);
    }

    public function test_disabled_notice_returns_null(): void
    {
        $this->notice(['enabled' => false]);
        $token = $this->player()->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/notices/active')->assertOk()->assertExactJson(['notice' => null]);
    }

    public function test_future_notice_returns_null(): void
    {
        $this->notice(['starts_at' => now()->addMinutes(5)]);
        $token = $this->player()->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/notices/active')->assertOk()->assertExactJson(['notice' => null]);
    }

    public function test_expired_notice_returns_null(): void
    {
        $this->notice(['starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()]);
        $token = $this->player()->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/notices/active')->assertOk()->assertExactJson(['notice' => null]);
    }

    public function test_enabled_notice_with_empty_messages_returns_null(): void
    {
        $this->notice(['message_en' => '  ', 'message_nl' => null]);
        $token = $this->player()->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/notices/active')->assertOk()->assertExactJson(['notice' => null]);
    }

    public function test_message_follows_the_users_preferred_language_then_accept_language(): void
    {
        $this->notice(['message_fr' => 'La connexion quotidienne commence demain']);

        // Signed-in preference wins over the header.
        $nlUser = $this->player(['preferred_language' => 'nl']);
        $this->actingWithToken($nlUser->createToken('m')->plainTextToken)
            ->withHeader('Accept-Language', 'fr')
            ->getJson('/api/notices/active')
            ->assertJsonPath('notice.message', 'Dagelijks inloggen start morgen');

        // No usable preference → the app's Accept-Language.
        $enUser = $this->player(['preferred_language' => 'en']);
        $this->actingWithToken($enUser->createToken('m')->plainTextToken)
            ->withHeader('Accept-Language', 'fr-BE,fr;q=0.9')
            ->getJson('/api/notices/active')
            ->assertJsonPath('notice.message', 'Daily login starts tomorrow');

        $frUser = $this->player(['preferred_language' => 'fr']);
        $this->actingWithToken($frUser->createToken('m')->plainTextToken)
            ->getJson('/api/notices/active')
            ->assertJsonPath('notice.message', 'La connexion quotidienne commence demain');
    }

    public function test_missing_language_falls_back_to_english_then_to_any_filled_language(): void
    {
        $this->notice(['message_nl' => null]); // only English filled
        $deUser = $this->player(['preferred_language' => 'de']);
        $this->actingWithToken($deUser->createToken('m')->plainTextToken)
            ->getJson('/api/notices/active')
            ->assertJsonPath('notice.message', 'Daily login starts tomorrow');

        // No English either → whichever language the admin did fill in.
        AppNotice::query()->update(['message_en' => null, 'message_es' => 'El inicio diario empieza mañana']);
        $this->actingWithToken($deUser->createToken('m2')->plainTextToken)
            ->getJson('/api/notices/active')
            ->assertJsonPath('notice.message', 'El inicio diario empieza mañana');
    }

    public function test_unknown_type_is_reported_as_info(): void
    {
        $this->notice(['type' => 'info']);
        AppNotice::query()->update(['type' => 'legacy']);
        $token = $this->player()->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/notices/active')->assertJsonPath('notice.type', 'info');
    }
}
