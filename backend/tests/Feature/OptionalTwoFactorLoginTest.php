<?php

namespace Tests\Feature;

use App\Models\LoginVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\LoginVerificationCodeNotification;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * v1.9.7 — the emailed login code is an OPT-IN per-user setting
 * (users.two_factor_enabled, default false). Registration email verification
 * is a separate mechanism and keeps working for unverified accounts.
 */
class OptionalTwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.auth.force_login_2fa' => false, 'ballspot.auth.require_email_verification' => true]);
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function verifiedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['password' => Hash::make('password123')], $attrs));
    }

    private function enable2fa(User $user): User
    {
        $user->two_factor_enabled = true;
        $user->save();

        return $user->fresh();
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    public function test_default_value_is_false_for_new_and_existing_users(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_enabled'));

        $user = User::factory()->create();
        $this->assertFalse($user->fresh()->two_factor_enabled);

        // A row written without the column (old code path) still reads false.
        $legacy = User::factory()->create();
        \DB::table('users')->where('id', $legacy->id)->update(['two_factor_enabled' => false]);
        $this->assertFalse($legacy->fresh()->two_factor_enabled);
        $this->assertFalse($legacy->fresh()->wantsLoginTwoFactor());
    }

    public function test_verified_user_with_2fa_off_logs_in_directly_without_a_code(): void
    {
        Notification::fake();
        $user = $this->verifiedUser();

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertOk()
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonMissing(['requires_2fa' => true])
            ->assertJsonMissing(['requires_email_verification' => true]);
        $this->assertNotEmpty($res->json('token'));

        Notification::assertNothingSent();
        $this->assertSame(0, LoginVerificationCode::count(), 'no login_verification_codes row when 2FA is off');
        $this->assertNotEmpty($this->logged('login.2fa_skipped'));
        $this->assertEmpty($this->logged('login.2fa_required'));
    }

    public function test_verified_user_with_2fa_on_receives_a_code_and_no_token(): void
    {
        Notification::fake();
        $user = $this->enable2fa($this->verifiedUser());

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertOk()
            ->assertJsonPath('requires_2fa', true)
            ->assertJsonPath('code', 'two_factor_required')
            ->assertJsonMissingPath('token');

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);
        $this->assertSame(1, LoginVerificationCode::where('user_id', $user->id)->count());
        $this->assertSame('user_setting', $this->logged('login.2fa_required')[0]->context['reason']);
    }

    public function test_wrong_2fa_code_fails_with_a_friendly_code(): void
    {
        Notification::fake();
        $user = $this->enable2fa($this->verifiedUser());
        $res  = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $code = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/login/verify', ['verification_id' => $res->json('verification_id'), 'code' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('code', 'two_factor_code_invalid')
            ->assertJsonPath('reason', 'wrong_code')
            ->assertJsonPath('message', 'That code is not correct. Check the newest email and try again.')
            ->assertJsonMissingPath('token');

        $failed = $this->logged('login.2fa_failed');
        $this->assertSame('wrong_code', $failed[0]->context['reason']);
        $this->assertArrayNotHasKey('code', $failed[0]->context);
    }

    public function test_correct_2fa_code_logs_in(): void
    {
        Notification::fake();
        $user = $this->enable2fa($this->verifiedUser());
        $res  = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $code = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });

        $verify = $this->postJson('/api/login/verify', ['verification_id' => $res->json('verification_id'), 'code' => $code]);
        $verify->assertOk()->assertJsonStructure(['user', 'token']);

        $this->withToken($verify->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotEmpty($this->logged('login.2fa_completed'));
    }

    public function test_expired_2fa_code_fails_with_expired_code(): void
    {
        Notification::fake();
        $user = $this->enable2fa($this->verifiedUser());
        $res  = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $this->travel(config('ballspot.auth.login_code_expiry_minutes') + 1)->minutes();

        $this->postJson('/api/login/verify', ['verification_id' => $res->json('verification_id'), 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'two_factor_code_expired');
    }

    public function test_unverified_user_still_requires_email_verification_not_2fa(): void
    {
        Notification::fake();
        $user = $this->enable2fa(User::factory()->unverified()->create(['password' => Hash::make('password123')]));

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonMissing(['requires_2fa' => true]);
        $this->assertNotEmpty($res->json('token'), 'a token is issued so the app can verify the email');

        Notification::assertSentTo($user, EmailVerificationCodeNotification::class);
        Notification::assertNotSentTo($user, LoginVerificationCodeNotification::class);
        $this->assertSame(0, LoginVerificationCode::count());
    }

    public function test_toggling_2fa_via_preferences_works_and_is_logged(): void
    {
        $user  = $this->verifiedUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/me/preferences')
            ->assertOk()->assertJsonPath('two_factor_enabled', false);

        $this->withToken($token)->patchJson('/api/me/preferences', ['two_factor_enabled' => true])
            ->assertOk()->assertJsonPath('two_factor_enabled', true);
        $this->assertTrue($user->fresh()->two_factor_enabled);

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.two_factor_enabled', true);

        $this->withToken($token)->patchJson('/api/me/preferences', ['two_factor_enabled' => false])
            ->assertOk()->assertJsonPath('two_factor_enabled', false);
        $this->assertFalse($user->fresh()->two_factor_enabled);

        $changes = $this->logged('login.2fa_setting_changed');
        $this->assertCount(2, $changes);
        $this->assertTrue($changes[0]->context['enabled']);
        $this->assertFalse($changes[1]->context['enabled']);

        $this->withToken($token)->patchJson('/api/me/preferences', ['two_factor_enabled' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors(['two_factor_enabled']);
    }

    public function test_two_factor_enabled_is_not_mass_assignable_through_register(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Sneaky', 'username' => 'sneaky', 'email' => 'sneaky@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true, 'two_factor_enabled' => true,
        ])->assertStatus(201);

        $this->assertFalse(User::where('username', 'sneaky')->first()->two_factor_enabled);
    }

    public function test_global_force_flag_still_overrides_the_user_setting(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        Notification::fake();
        $user = $this->verifiedUser();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('requires_2fa', true);
        $this->assertSame('forced_by_config', $this->logged('login.2fa_required')[0]->context['reason']);
    }

    public function test_invalid_credentials_carry_a_stable_code_and_send_nothing(): void
    {
        Notification::fake();
        $user = $this->enable2fa($this->verifiedUser());

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonPath('errors.email.0', 'Invalid email or password.');

        Notification::assertNothingSent();
        $this->assertSame(0, LoginVerificationCode::count());
    }
}
