<?php

namespace Tests\Feature;

use App\Models\LoginVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\LoginVerificationCodeNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Launch policy for login 2FA: off by default, never forced, and no new
 * opt-ins while BALLPICKER_LOGIN_2FA_OPT_IN is false. Email verification,
 * password reset and the admin login must be unaffected, and accounts that
 * opted in before launch must keep working.
 */
class LaunchTwoFactorPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['password' => Hash::make('password123')], $attrs));
    }

    private function login(User $user)
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);
    }

    // ------------------------------------------------------------------
    // Defaults
    // ------------------------------------------------------------------

    public function test_force_flag_and_opt_in_flag_both_default_to_false(): void
    {
        $this->assertFalse((bool) config('ballspot.auth.force_login_2fa'));
        $this->assertFalse((bool) config('ballspot.auth.login_2fa_opt_in'));
    }

    public function test_config_endpoint_reports_the_opt_in_flag(): void
    {
        $this->getJson('/api/config')->assertOk()->assertJsonPath('login_two_factor_opt_in', false);

        config(['ballspot.auth.login_2fa_opt_in' => true]);
        $this->getJson('/api/config')->assertOk()->assertJsonPath('login_two_factor_opt_in', true);
    }

    // ------------------------------------------------------------------
    // Login flows
    // ------------------------------------------------------------------

    public function test_normal_verified_user_logs_in_without_2fa_and_without_any_email(): void
    {
        Notification::fake();
        $user = $this->verifiedUser();

        $res = $this->login($user);

        $res->assertOk()
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonMissing(['requires_2fa' => true])
            ->assertJsonMissing(['requires_email_verification' => true]);

        Notification::assertNothingSent();
        $this->assertSame(0, LoginVerificationCode::count());
    }

    public function test_2fa_is_not_forced_when_the_force_flag_is_false(): void
    {
        config(['ballspot.auth.force_login_2fa' => false]);
        Notification::fake();
        $user = $this->verifiedUser();

        $this->login($user)->assertOk()->assertJsonMissingPath('requires_2fa')->assertJsonStructure(['token']);

        Notification::assertNotSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_unverified_user_still_goes_to_email_verification_not_2fa(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['password' => Hash::make('password123')]);

        $res = $this->login($user);

        $res->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonMissing(['requires_2fa' => true]);
        $this->assertNotEmpty($res->json('token'));

        // Exactly one email: the verification code, never a login code.
        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        Notification::assertNotSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_a_user_who_opted_in_before_launch_still_completes_the_code_flow(): void
    {
        config(['ballspot.auth.login_2fa_opt_in' => false]); // launch state
        Notification::fake();
        $user = $this->verifiedUser(['two_factor_enabled' => true]);

        $res = $this->login($user);
        $res->assertOk()->assertJsonPath('requires_2fa', true)->assertJsonMissingPath('token');

        $code = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->postJson('/api/login/verify', ['verification_id' => $res->json('verification_id'), 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_global_force_flag_true_requires_the_code_for_everyone(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        Notification::fake();
        $user = $this->verifiedUser(['two_factor_enabled' => false]);

        $this->login($user)->assertOk()->assertJsonPath('requires_2fa', true)->assertJsonMissingPath('token');

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);
    }

    // ------------------------------------------------------------------
    // Opt-in gate (PATCH /me/preferences)
    // ------------------------------------------------------------------

    public function test_enabling_2fa_is_refused_while_the_opt_in_flag_is_off(): void
    {
        config(['ballspot.auth.login_2fa_opt_in' => false]);
        $user  = $this->verifiedUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->patchJson('/api/me/preferences', ['two_factor_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['two_factor_enabled'])
            ->assertJsonPath('errors.two_factor_enabled.0', __('messages.preferences.two_factor_unavailable'));

        $this->assertFalse($user->fresh()->two_factor_enabled);
        $this->withToken($token)->getJson('/api/me/preferences')->assertOk()->assertJsonPath('two_factor_enabled', false);
    }

    public function test_disabling_2fa_is_always_allowed(): void
    {
        config(['ballspot.auth.login_2fa_opt_in' => false]);
        $user  = $this->verifiedUser(['two_factor_enabled' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->patchJson('/api/me/preferences', ['two_factor_enabled' => false])
            ->assertOk()->assertJsonPath('two_factor_enabled', false);

        $this->assertFalse($user->fresh()->two_factor_enabled);
    }

    public function test_sending_true_for_an_account_that_already_has_it_on_is_a_no_op_not_an_error(): void
    {
        config(['ballspot.auth.login_2fa_opt_in' => false]);
        $user  = $this->verifiedUser(['two_factor_enabled' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->patchJson('/api/me/preferences', ['two_factor_enabled' => true])
            ->assertOk()->assertJsonPath('two_factor_enabled', true);
    }

    public function test_enabling_2fa_works_once_the_opt_in_flag_is_on(): void
    {
        config(['ballspot.auth.login_2fa_opt_in' => true]);
        $user  = $this->verifiedUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->patchJson('/api/me/preferences', ['two_factor_enabled' => true])
            ->assertOk()->assertJsonPath('two_factor_enabled', true);

        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    public function test_other_preferences_still_save_while_the_opt_in_flag_is_off(): void
    {
        config(['ballspot.auth.login_2fa_opt_in' => false]);
        $user  = $this->verifiedUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->patchJson('/api/me/preferences', ['preferred_language' => 'nl'])
            ->assertOk()->assertJsonPath('preferred_language', 'nl');
    }

    // ------------------------------------------------------------------
    // Unaffected flows
    // ------------------------------------------------------------------

    public function test_admin_web_login_ignores_2fa_settings(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true, 'two_factor_enabled' => true, 'password' => Hash::make('password123')]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect('/admin/challenges');

        $this->assertAuthenticatedAs($admin);
        Notification::assertNothingSent();
    }

    public function test_password_reset_still_sends_exactly_one_reset_email(): void
    {
        Notification::fake();
        $user = $this->verifiedUser();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        Notification::assertNotSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_readiness_check_warns_when_2fa_is_forced(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('BALLPICKER_FORCE_LOGIN_2FA is true')
            ->assertExitCode(0);

        config(['ballspot.auth.force_login_2fa' => false]);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('BALLPICKER_FORCE_LOGIN_2FA=false')
            ->assertExitCode(0);
    }
}
