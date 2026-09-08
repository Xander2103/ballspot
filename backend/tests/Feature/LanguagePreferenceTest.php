<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * v1.9.7 — users.preferred_language (nl|en|fr|de|es): set at registration,
 * returned on /me, changeable from preferences, used as the notification locale.
 */
class LanguagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.beta_code' => null]);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lang Person', 'username' => 'langperson', 'email' => 'lang@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ], $overrides);
    }

    public function test_register_stores_the_preferred_language_and_returns_it(): void
    {
        $res = $this->postJson('/api/register', $this->registerPayload(['preferred_language' => 'nl']));

        $res->assertStatus(201);
        $this->assertSame('nl', User::where('username', 'langperson')->value('preferred_language'));
        $this->withToken($res->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.preferred_language', 'nl');
    }

    public function test_register_without_a_language_defaults_to_english(): void
    {
        $res = $this->postJson('/api/register', $this->registerPayload())->assertStatus(201);
        $this->assertSame('en', User::find($res->json('user.id'))->preferred_language);
        $this->withToken($res->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.preferred_language', 'en');
    }

    public function test_register_rejects_unsupported_languages(): void
    {
        $this->postJson('/api/register', $this->registerPayload(['preferred_language' => 'xx']))
            ->assertStatus(422)->assertJsonValidationErrors(['preferred_language']);
        $this->postJson('/api/register', $this->registerPayload(['preferred_language' => 'EN']))
            ->assertStatus(422)->assertJsonValidationErrors(['preferred_language']);
    }

    public function test_every_supported_language_is_accepted(): void
    {
        foreach (['nl', 'en', 'fr', 'de', 'es'] as $i => $lang) {
            $this->postJson('/api/register', $this->registerPayload([
                'username' => "user{$lang}", 'email' => "user{$lang}@example.com", 'preferred_language' => $lang,
            ]))->assertStatus(201);
            $this->assertSame($lang, User::where('username', "user{$lang}")->value('preferred_language'));
        }
    }

    public function test_existing_users_get_the_default_value(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'preferred_language'));

        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['preferred_language' => 'en']);

        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.preferred_language', 'en');
    }

    public function test_user_can_change_the_language_from_preferences(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/me/preferences')
            ->assertOk()
            ->assertJsonPath('preferred_language', 'en')
            ->assertJsonPath('available_languages', ['nl', 'en', 'fr', 'de', 'es']);

        $this->withToken($token)->patchJson('/api/me/preferences', ['preferred_language' => 'fr'])
            ->assertOk()->assertJsonPath('preferred_language', 'fr');
        $this->assertSame('fr', $user->fresh()->preferred_language);

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.preferred_language', 'fr');

        $this->withToken($token)->patchJson('/api/me/preferences', ['preferred_language' => 'it'])
            ->assertStatus(422)->assertJsonValidationErrors(['preferred_language']);
        $this->assertSame('fr', $user->fresh()->preferred_language);
    }

    public function test_language_is_the_notification_locale_with_a_safe_fallback(): void
    {
        $user = User::factory()->create(['preferred_language' => 'de']);
        $this->assertInstanceOf(HasLocalePreference::class, $user);
        $this->assertSame('de', $user->preferredLocale());

        // A stale/unsupported stored value never breaks mail rendering.
        DB::table('users')->where('id', $user->id)->update(['preferred_language' => 'zz']);
        $this->assertSame('en', $user->fresh()->preferredLocale());
    }

    public function test_language_is_not_exposed_on_other_users_public_data(): void
    {
        $viewer = User::factory()->create();
        $other  = User::factory()->create(['preferred_language' => 'es']);
        $token  = $viewer->createToken('t')->plainTextToken;

        $res = $this->withToken($token)->getJson("/api/users/{$other->id}/public-profile");
        $res->assertOk();
        $this->assertStringNotContainsString('preferred_language', $res->getContent());
        $this->assertStringNotContainsString('two_factor_enabled', $res->getContent());
    }

    public function test_config_exposes_the_supported_languages(): void
    {
        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('supported_languages', ['nl', 'en', 'fr', 'de', 'es'])
            ->assertJsonPath('default_language', 'en');
    }
}
