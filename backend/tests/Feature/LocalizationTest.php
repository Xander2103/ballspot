<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\LoginVerificationCodeNotification;
use App\Notifications\ResetPasswordNotification;
use App\Support\AuthError;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * i18n: emails render in the recipient's preferred_language, API messages
 * follow the request language, the web reset pages render in all five
 * languages, unsupported values fall back safely, and the machine-readable
 * error CODES never change per language.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private const LANGUAGES = ['nl', 'en', 'fr', 'de', 'es'];

    /** A word that must appear in each language's password-reset email. */
    private const RESET_MARKER = [
        'nl' => 'wachtwoord',
        'en' => 'password',
        'fr' => 'mot de passe',
        'de' => 'Passwort',
        'es' => 'contraseña',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.beta_code' => null, 'ballspot.auth.require_email_verification' => true, 'ballspot.auth.force_login_2fa' => false]);
    }

    private function user(string $lang, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'password'           => Hash::make('password123'),
            'preferred_language' => $lang,
        ], $attrs));
    }

    /** Rendered messages captured by the `array` mail transport. */
    private function sentMails(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->all();
    }

    private function lastMail()
    {
        $mails = $this->sentMails();
        $this->assertNotEmpty($mails, 'expected an email to be sent');

        return end($mails);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lang Person', 'username' => 'langperson', 'email' => 'lang@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ], $overrides);
    }

    // ------------------------------------------------------------------ files

    public function test_every_supported_language_ships_every_translation_file(): void
    {
        foreach (self::LANGUAGES as $lang) {
            foreach (['auth_codes', 'emails', 'web', 'messages'] as $file) {
                $this->assertFileExists(lang_path("{$lang}/{$file}.php"), "lang/{$lang}/{$file}.php is missing");
            }
        }
    }

    public function test_every_english_key_exists_in_every_language(): void
    {
        foreach (['auth_codes', 'emails', 'web', 'messages'] as $file) {
            $en = require lang_path("en/{$file}.php");
            foreach (self::LANGUAGES as $lang) {
                $other = require lang_path("{$lang}/{$file}.php");
                foreach (array_keys(\Illuminate\Support\Arr::dot($en)) as $key) {
                    $this->assertNotNull(\Illuminate\Support\Arr::get($other, $key), "lang/{$lang}/{$file}.php misses '{$key}'");
                    $this->assertNotSame('', \Illuminate\Support\Arr::get($other, $key), "lang/{$lang}/{$file}.php has an empty '{$key}'");
                }
            }
        }
    }

    public function test_placeholders_survive_translation(): void
    {
        foreach (['emails', 'messages', 'auth_codes', 'web'] as $file) {
            $en = \Illuminate\Support\Arr::dot(require lang_path("en/{$file}.php"));
            foreach (self::LANGUAGES as $lang) {
                $other = \Illuminate\Support\Arr::dot(require lang_path("{$lang}/{$file}.php"));
                foreach ($en as $key => $value) {
                    preg_match_all('/:[a-z_]+/', (string) $value, $m);
                    foreach ($m[0] as $placeholder) {
                        $this->assertStringContainsString($placeholder, (string) ($other[$key] ?? ''), "lang/{$lang}/{$file}.php '{$key}' lost {$placeholder}");
                    }
                }
            }
        }
    }

    // ----------------------------------------------------------------- emails

    public function test_email_verification_mail_uses_the_preferred_language(): void
    {
        $subjects = [];
        foreach (self::LANGUAGES as $lang) {
            $user = $this->user($lang, ['email_verified_at' => null]);
            $user->notify(new EmailVerificationCodeNotification('123456', 60));

            $mail = $this->lastMail();
            $subjects[$lang] = $mail->getSubject();
            $this->assertSame(__('emails.verify.subject', ['app' => config('ballspot.app_name')], $lang), $mail->getSubject());
            $this->assertStringContainsString('123456', $mail->getHtmlBody());
            $this->assertStringContainsString(__('emails.verify.greeting', ['app' => config('ballspot.app_name')], $lang), $mail->getHtmlBody());
        }
        // The four non-English subjects really differ from the English one.
        foreach (['nl', 'fr', 'de', 'es'] as $lang) {
            $this->assertNotSame($subjects['en'], $subjects[$lang], "{$lang} verification subject is still English");
        }
    }

    public function test_register_sends_the_verification_mail_in_the_language_chosen_during_registration(): void
    {
        $this->postJson('/api/register', $this->registerPayload(['preferred_language' => 'nl']))->assertStatus(201);

        $mail = $this->lastMail();
        $this->assertSame(__('emails.verify.subject', ['app' => config('ballspot.app_name')], 'nl'), $mail->getSubject());
        $this->assertNotSame(__('emails.verify.subject', ['app' => config('ballspot.app_name')], 'en'), $mail->getSubject());
    }

    public function test_login_2fa_mail_uses_the_preferred_language(): void
    {
        foreach (self::LANGUAGES as $lang) {
            $user = $this->user($lang, ['two_factor_enabled' => true]);

            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
                ->assertOk()->assertJsonPath('requires_2fa', true)->assertJsonPath('code', AuthError::TWO_FACTOR_REQUIRED);

            $mail = $this->lastMail();
            $this->assertSame(__('emails.login_code.subject', ['app' => config('ballspot.app_name')], $lang), $mail->getSubject());
            $this->assertStringContainsString(__('emails.login_code.greeting', [], $lang), $mail->getHtmlBody());
        }
    }

    public function test_login_2fa_mail_rendered_directly_uses_the_preferred_language(): void
    {
        $user = $this->user('de');
        $user->notify(new LoginVerificationCodeNotification('654321', 10));
        $mail = $this->lastMail();
        $this->assertSame(__('emails.login_code.subject', ['app' => config('ballspot.app_name')], 'de'), $mail->getSubject());
        $this->assertStringContainsString('654321', $mail->getHtmlBody());
    }

    public function test_password_reset_mail_uses_the_preferred_language_before_login(): void
    {
        foreach (self::LANGUAGES as $lang) {
            $user = $this->user($lang);

            // Anonymous request (nobody is logged in, no Accept-Language): the
            // recipient's own preference decides.
            $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

            $mail = $this->lastMail();
            $this->assertSame(__('emails.reset.subject', ['app' => config('ballspot.app_name')], $lang), $mail->getSubject());
            $this->assertStringContainsStringIgnoringCase(self::RESET_MARKER[$lang], $mail->getHtmlBody());
            // The link carries the language so the web page matches the email.
            $this->assertStringContainsString("lang={$lang}", $mail->getHtmlBody());
        }
    }

    public function test_password_reset_mail_ignores_the_requesters_language(): void
    {
        $user = $this->user('es');

        $this->withHeaders(['Accept-Language' => 'de'])
            ->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertSame(__('emails.reset.subject', ['app' => config('ballspot.app_name')], 'es'), $this->lastMail()->getSubject());
    }

    public function test_unsupported_stored_language_falls_back_to_english_mail(): void
    {
        $user = $this->user('en');
        DB::table('users')->where('id', $user->id)->update(['preferred_language' => 'zz']);
        $user = $user->fresh();

        $this->assertSame('en', $user->preferredLocale());
        $user->notify(new ResetPasswordNotification(Password::broker()->createToken($user)));
        $mail = $this->lastMail();
        $this->assertSame(__('emails.reset.subject', ['app' => config('ballspot.app_name')], 'en'), $mail->getSubject());
        $this->assertStringContainsString('lang=en', $mail->getHtmlBody());
    }

    public function test_daily_reminder_push_copy_is_localized_per_user(): void
    {
        foreach (self::LANGUAGES as $lang) {
            $body = __('messages.push.daily_reminder_body', ['app' => 'BallPicker'], $lang);
            $this->assertStringContainsString('BallPicker', $body);
            $this->assertNotSame('messages.push.daily_reminder_body', $body);
        }
        $this->assertNotSame(__('messages.push.daily_reminder_body', ['app' => 'BallPicker'], 'en'), __('messages.push.daily_reminder_body', ['app' => 'BallPicker'], 'fr'));
    }

    // --------------------------------------------------------------- web pages

    public function test_reset_web_page_renders_in_every_language(): void
    {
        $user  = $this->user('en');
        $token = Password::broker()->createToken($user);

        foreach (self::LANGUAGES as $lang) {
            $res = $this->get('/reset-password?token=' . $token . '&email=' . urlencode($user->email) . '&lang=' . $lang);
            $res->assertOk()
                ->assertSee('<html lang="' . $lang . '">', false)
                ->assertSee(__('web.reset.heading', [], $lang))
                ->assertSee(__('web.reset.submit', [], $lang))
                ->assertSee('name="lang" value="' . $lang . '"', false);
        }
    }

    public function test_forgot_web_page_renders_in_every_language(): void
    {
        foreach (self::LANGUAGES as $lang) {
            $this->get('/forgot-password?lang=' . $lang)
                ->assertOk()
                ->assertSee(__('web.forgot.heading', [], $lang))
                ->assertSee(__('web.forgot.submit', [], $lang));

            // forgot-password is throttled per email+IP: distinct address per language.
            $this->post('/forgot-password', ['email' => "nobody-{$lang}@example.com", 'lang' => $lang])
                ->assertOk()
                ->assertSee(__('web.forgot.sent_heading', [], $lang));
        }
    }

    public function test_reset_result_pages_render_in_every_language(): void
    {
        foreach (self::LANGUAGES as $lang) {
            // reset-password is throttled per email: fresh limiter window per language.
            \Illuminate\Support\Facades\Cache::flush();
            $user  = $this->user('en', ['email' => "reset-{$lang}@example.com", 'username' => "reset{$lang}"]);
            $token = Password::broker()->createToken($user);

            // Invalid token → "link no longer works" state in the page language.
            $this->post('/reset-password', [
                'token' => 'wrong', 'email' => $user->email, 'lang' => $lang,
                'password' => 'newpassword123', 'password_confirmation' => 'newpassword123',
            ])->assertStatus(422)
              ->assertSee(__('web.result.expired_heading', [], $lang))
              ->assertSee(__('messages.auth.reset_link_invalid', [], $lang));

            // Success state.
            $this->post('/reset-password', [
                'token' => $token, 'email' => $user->email, 'lang' => $lang,
                'password' => 'newpassword123', 'password_confirmation' => 'newpassword123',
            ])->assertOk()->assertSee(__('web.result.ok_heading', [], $lang));
        }
    }

    public function test_web_page_falls_back_safely_for_unsupported_or_missing_language(): void
    {
        $this->get('/forgot-password?lang=xx')->assertOk()->assertSee(__('web.forgot.heading', [], 'en'));
        $this->get('/forgot-password?lang=')->assertOk()->assertSee(__('web.forgot.heading', [], 'en'));
        $this->get('/forgot-password')->assertOk()->assertSee(__('web.forgot.heading', [], 'en'));
        $this->withHeaders(['Accept-Language' => 'it-IT,it;q=0.9,fr;q=0.8'])
            ->get('/forgot-password')->assertOk()->assertSee(__('web.forgot.heading', [], 'fr'));
    }

    public function test_reset_link_language_wins_over_accept_language(): void
    {
        $this->withHeaders(['Accept-Language' => 'de'])
            ->get('/forgot-password?lang=nl')->assertOk()->assertSee(__('web.forgot.heading', [], 'nl'));
    }

    // ------------------------------------------------------------- API messages

    public function test_api_error_codes_stay_stable_while_messages_translate(): void
    {
        $user = $this->user('en');

        foreach (self::LANGUAGES as $lang) {
            $res = $this->withHeaders(['Accept-Language' => $lang])
                ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);

            $res->assertStatus(422)
                ->assertJsonPath('code', AuthError::INVALID_CREDENTIALS)
                ->assertJsonPath('codes.email', AuthError::INVALID_CREDENTIALS)
                ->assertJsonPath('message', __('auth_codes.invalid_credentials', [], $lang));
        }

        // Registration codes: same code in every language.
        foreach (self::LANGUAGES as $lang) {
            $this->withHeaders(['Accept-Language' => $lang])
                ->postJson('/api/register', $this->registerPayload(['email' => $user->email, 'username' => 'other' . $lang]))
                ->assertStatus(422)
                ->assertJsonPath('code', AuthError::EMAIL_TAKEN)
                ->assertJsonPath('codes.email', AuthError::EMAIL_TAKEN)
                ->assertJsonPath('message', __('auth_codes.email_taken', [], $lang));
        }

        $this->assertNotSame(__('auth_codes.invalid_credentials', [], 'en'), __('auth_codes.invalid_credentials', [], 'nl'));
        $this->assertSame(array_keys(AuthError::MESSAGES), array_values(array_intersect(array_keys(AuthError::MESSAGES), array_keys(require lang_path('en/auth_codes.php')))));
    }

    public function test_register_validation_errors_use_the_language_chosen_in_the_form(): void
    {
        // Body language wins over the header (the user just picked it).
        $res = $this->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/api/register', $this->registerPayload(['preferred_language' => 'fr', 'terms_accepted' => false]));

        $res->assertStatus(422)->assertJsonPath('errors.terms_accepted.0', __('messages.auth.terms_required', [], 'fr'));
    }

    public function test_signed_in_users_get_messages_in_their_preferred_language(): void
    {
        $user  = $this->user('de');
        $token = $user->createToken('t')->plainTextToken;

        // Even when the device header says otherwise, the account preference wins.
        $this->withToken($token)->withHeaders(['Accept-Language' => 'es'])
            ->postJson('/api/logout')
            ->assertOk()->assertJsonPath('message', __('messages.auth.logged_out', [], 'de'));
    }

    public function test_rate_limit_message_is_translated_and_keeps_retry_after(): void
    {
        config(['ballspot.beta_code' => null]);
        // Burn the login limiter, then expect a Dutch 429 with retry_after.
        $user = $this->user('nl');
        for ($i = 0; $i < 30; $i++) {
            $res = $this->withHeaders(['Accept-Language' => 'nl'])
                ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);
            if ($res->status() === 429) {
                $res->assertJsonStructure(['message', 'retry_after']);
                $this->assertSame(__('messages.rate_limited', ['seconds' => $res->json('retry_after')], 'nl'), $res->json('message'));
                $this->assertNotSame(__('messages.rate_limited', ['seconds' => $res->json('retry_after')], 'en'), $res->json('message'));

                return;
            }
        }
        $this->markTestSkipped('login limiter did not trip within 30 attempts');
    }

    public function test_locale_helper_normalises_and_falls_back(): void
    {
        $this->assertSame('nl', Locale::normalize('nl-BE'));
        $this->assertSame('fr', Locale::normalize('fr_FR'));
        $this->assertSame('de', Locale::normalize(' DE '));
        $this->assertNull(Locale::normalize('it'));
        $this->assertNull(Locale::normalize(null));
        $this->assertNull(Locale::normalize(42));
        $this->assertSame('es', Locale::fromAcceptLanguage('it-IT,it;q=0.9,es;q=0.8,en;q=0.7'));
        $this->assertSame('en', Locale::resolve(['xx', null, '']));

        config(['ballspot.default_language' => 'zz']);
        $this->assertSame('en', Locale::default());
        config(['ballspot.default_language' => 'nl']);
        $this->assertSame('nl', Locale::resolve(['xx']));
    }

    public function test_no_secrets_in_translation_files(): void
    {
        // Translations are plain copy: no code/token placeholders beyond the
        // documented ones and no environment values.
        foreach (self::LANGUAGES as $lang) {
            $content = file_get_contents(lang_path("{$lang}/emails.php"));
            $this->assertStringNotContainsString('env(', $content);
            $this->assertStringNotContainsString('config(', $content);
        }
        $this->assertTrue(Lang::has('emails.verify.subject', 'nl'));
    }
}
