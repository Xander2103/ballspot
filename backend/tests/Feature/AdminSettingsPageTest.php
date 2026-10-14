<?php

namespace Tests\Feature;

use App\Models\GameplaySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /admin/settings dashboard (v1.9.14): the one editable gameplay setting
 * keeps working exactly as before, every other section is read-only and the
 * page never renders a secret.
 */
class AdminSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_settings_page_renders_every_section_with_cross_links(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings')
            ->assertOk()
            ->assertSee('Gameplay settings')
            ->assertSee('Competition settings')
            ->assertSee('Launch / feature flags')
            ->assertSee('Growth / review settings')
            ->assertSee('Security / anti-abuse settings')
            ->assertSee('Tournament challenge cooldown')
            ->assertSee('0 disables the cooldown')
            ->assertSee('Reviews are never rewarded with XP/trophies.')
            ->assertSee('href="/admin/competition"', false)
            ->assertSee('href="/admin/diagnostics"', false)
            ->assertSee('href="/admin/growth"', false)
            ->assertSee('value="90"', false);
    }

    public function test_tournament_cooldown_can_still_be_saved_and_shows_as_override(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings', ['tournament_challenge_cooldown_days' => 30])
            ->assertRedirect('/admin/settings')->assertSessionHas('success', 'Settings saved.');
        $this->assertSame(30, GameplaySetting::tournamentChallengeCooldownDays());

        $this->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee('Settings saved.')
            ->assertSee('value="30"', false)
            ->assertSee('30 days (admin override)');

        $this->actingAs($admin)->put('/admin/settings', ['tournament_challenge_cooldown_days' => 0])->assertRedirect('/admin/settings');
        $this->assertSame(0, GameplaySetting::tournamentChallengeCooldownDays());
    }

    public function test_invalid_cooldown_is_rejected_and_the_error_is_displayed(): void
    {
        $admin = $this->admin();

        foreach ([-1, 366, 'abc', null, 1.5] as $bad) {
            $this->actingAs($admin)->from('/admin/settings')
                ->put('/admin/settings', ['tournament_challenge_cooldown_days' => $bad])
                ->assertRedirect('/admin/settings')
                ->assertSessionHasErrors('tournament_challenge_cooldown_days');
        }
        $this->assertSame(90, GameplaySetting::tournamentChallengeCooldownDays());

        $res = $this->actingAs($admin)->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['tournament_challenge_cooldown_days' => ['The value must be between 0 and 365.']]))])
            ->get('/admin/settings');
        $res->assertOk()->assertSee('The value must be between 0 and 365.')->assertSee('is-invalid');
    }

    public function test_read_only_config_values_render_as_badges(): void
    {
        config([
            'ballspot.competition.period'  => 'weekly',
            'ballspot.competition.label'   => 'Weekly Cup',
            'ballspot.daily.auto_schedule' => false,
            'ballspot.daily.auto_activate' => true,
            'ballspot.auth.force_login_2fa' => true,
            'ballspot.auth.login_2fa_opt_in' => false,
            'ballspot.beta_code'           => 'SECRETGATE',
            'ballspot.notifications.push_enabled' => false,
            'ballspot.captcha.enabled'     => true,
            'ballspot.captcha.secret'      => 'captcha-secret-value',
            'ballspot.captcha.site_key'    => '0xSITEKEYVALUE',
            'ballspot.abuse.register.per_ip_hour' => 7,
        ]);

        $res = $this->actingAs($this->admin())->get('/admin/settings')->assertOk();
        $html = $res->getContent();

        $res->assertSee('<code>weekly</code>', false)
            ->assertSee('Weekly Cup')
            ->assertSee('BALLPICKER_AUTO_SCHEDULE_DAILIES')
            ->assertSee('BALLPICKER_AUTO_ACTIVATE_DAILIES')
            ->assertSee('BALLPICKER_FORCE_LOGIN_2FA')
            ->assertSee('BALLPICKER_LOGIN_2FA_OPT_IN')
            ->assertSee('Beta gate')
            ->assertSee('Daily reminder push')
            ->assertSee('7/hour, 10/day')
            ->assertSee('provider <code>turnstile</code>', false)
            ->assertSee('site key set')
            ->assertSee('secret set');
        $this->assertSame(1, preg_match_all('/Auto-schedule dailies<\/td>\s*<td><code>[^<]*<\/code><\/td>\s*<td><span class="badge badge-soft-warn"[^>]*>launch control · off/', $html), 'auto-schedule off renders as launch control');
        $this->assertMatchesRegularExpression('/Force login 2FA for everyone<\/td>\s*<td><code>[^<]*<\/code><\/td>\s*<td><span class="badge bg-danger">on — check before launch/', $html);
        $this->assertMatchesRegularExpression('/Push notifications<\/td>\s*<td><code>[^<]*<\/code><\/td>\s*<td><span class="badge badge-soft">disabled/', $html);
        $this->assertMatchesRegularExpression('/Auto-activate today&#039;s daily<\/td>\s*<td><code>[^<]*<\/code><\/td>\s*<td><span class="badge badge-ok">enabled/', $html);
        $this->assertMatchesRegularExpression('/CAPTCHA \/ Turnstile<\/th><td>\s*<span class="badge badge-ok">enabled/', $html);
    }

    public function test_page_never_exposes_secrets_or_keys(): void
    {
        $appKey = ltrim((string) config('app.key'), 'base64:');
        config([
            'ballspot.beta_code'                   => 'SUPERSECRETBETA2026',
            'ballspot.captcha.enabled'             => true,
            'ballspot.captcha.secret'              => 'captcha-secret-value-77',
            'ballspot.captcha.site_key'            => '0xSITEKEYVALUE88',
            'mail.mailers.smtp.password'           => 'brevo-smtp-password-99',
            'mail.mailers.smtp.username'           => 'brevo-login@example.com',
            'database.connections.sqlite.password' => 'db-secret-password-55',
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->getContent();

        foreach (['SUPERSECRETBETA2026', 'captcha-secret-value-77', '0xSITEKEYVALUE88', 'brevo-smtp-password-99', 'brevo-login@example.com',
                  'db-secret-password-55', $appKey, 'APP_KEY=', 'MAIL_PASSWORD=', 'BALLPICKER_CAPTCHA_SECRET='] as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $html, "Settings leaked: {$secret}");
        }
        $this->assertStringContainsString('values never shown', $html);
    }

    public function test_missing_or_invalid_optional_config_does_not_crash(): void
    {
        config([
            'ballspot.competition.period'   => 'fortnightly',
            'ballspot.competition.label'    => null,
            'ballspot.competition.timezone' => null,
            'ballspot.captcha'              => null,
            'ballspot.share'                => null,
            'ballspot.abuse'                => null,
            'ballspot.beta_code'            => null,
        ]);

        $this->actingAs($this->admin())->get('/admin/settings')
            ->assertOk()
            ->assertSee('invalid — using monthly')
            ->assertSee('(unset)')
            ->assertSee('+25 XP')
            ->assertSee('5/hour, 10/day');
    }

    public function test_settings_page_is_admin_only_and_read_only_sections_have_no_forms(): void
    {
        $this->get('/admin/settings')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/admin/settings')->assertForbidden();

        $html = $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<form method="POST"'), 'only the cooldown form may write');
    }
}
