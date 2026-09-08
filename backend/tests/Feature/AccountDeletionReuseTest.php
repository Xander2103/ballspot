<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\LoginVerificationCode;
use App\Models\PushToken;
use App\Models\User;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * v1.9.7 — after "Delete account" the original email AND username must be
 * reusable immediately, the deleted account must be unusable, and every
 * credential/device row must be gone.
 */
class AccountDeletionReuseTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.beta_code' => null, 'ballspot.auth.require_email_verification' => true]);
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function makeUser(): User
    {
        $user = User::create([
            'name' => 'Reusable Person', 'username' => 'reusable', 'email' => 'reusable@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Reusable Person', 'username' => 'reusable', 'email' => 'reusable@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ], $overrides);
    }

    private function deleteAs(User $user): string
    {
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->deleteJson('/api/account')->assertOk()->assertJsonPath('deleted', true);

        return $token;
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    public function test_delete_account_frees_the_email(): void
    {
        $user = $this->makeUser();
        $this->deleteAs($user);

        $fresh = $user->fresh();
        $this->assertNotSame('reusable@example.com', $fresh->email);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertNull(User::where('email', 'reusable@example.com')->first());
    }

    public function test_delete_account_frees_the_username(): void
    {
        $user = $this->makeUser();
        $this->deleteAs($user);

        $this->assertNotSame('reusable', $user->fresh()->username);
        $this->assertNull(User::where('username', 'reusable')->first());
        $this->assertSame('Deleted User', $user->fresh()->name);
    }

    public function test_register_with_the_same_email_after_delete_succeeds(): void
    {
        $old = $this->makeUser();
        $this->deleteAs($old);

        $res = $this->postJson('/api/register', $this->registerPayload(['username' => 'anothername']));

        $res->assertStatus(201);
        $this->assertSame('reusable@example.com', User::find($res->json('user.id'))->email);
        $this->assertNotSame($old->id, $res->json('user.id'));
        $this->assertSame(2, User::count(), 'the old row stays (anonymized), a new row is created');
    }

    public function test_register_with_the_same_username_after_delete_succeeds(): void
    {
        $old = $this->makeUser();
        $this->deleteAs($old);

        $res = $this->postJson('/api/register', $this->registerPayload(['email' => 'other@example.com']));

        $res->assertStatus(201)->assertJsonPath('user.username', 'reusable');
        $this->assertNotSame($old->id, $res->json('user.id'));
    }

    public function test_deleted_user_cannot_log_in_anymore(): void
    {
        $user = $this->makeUser();
        $this->deleteAs($user);

        // Original credentials: the email no longer matches any live account.
        $this->postJson('/api/login', ['email' => 'reusable@example.com', 'password' => 'password123'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

        // The anonymized identifier is unusable too: password is random and the
        // row is flagged anonymized (defence in depth even if a hash leaked).
        $this->postJson('/api/login', ['email' => $user->fresh()->email, 'password' => 'password123'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

        // Forgot-password is silent and sends nothing for a deleted account.
        \Illuminate\Support\Facades\Notification::fake();
        $this->postJson('/api/forgot-password', ['email' => $user->fresh()->email])->assertOk();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    public function test_old_tokens_are_revoked_and_device_rows_removed(): void
    {
        $user = $this->makeUser();
        $other = $user->createToken('other-device')->plainTextToken;
        PushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[abc]', 'platform' => 'ios']);
        EmailVerificationCode::create([
            'user_id' => $user->id, 'code_hash' => Hash::make('123456'),
            'code_sent_at' => now(), 'expires_at' => now()->addHour(), 'attempts' => 0,
        ]);
        LoginVerificationCode::create([
            'verification_id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'code_sent_at' => now(), 'expires_at' => now()->addMinutes(10), 'attempts' => 0,
        ]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('reset'), 'created_at' => now()]);

        $current = $this->deleteAs($user);

        $this->assertSame(0, $user->tokens()->count());
        $this->actingWithToken($current)->getJson('/api/me')->assertUnauthorized();
        $this->actingWithToken($other)->getJson('/api/me')->assertUnauthorized();

        $this->assertSame(0, PushToken::where('user_id', $user->id)->count());
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->count());
        $this->assertSame(0, LoginVerificationCode::where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'reusable@example.com')->count(), 'pending reset links for the old address are removed');

        $this->assertNotEmpty($this->logged('account.delete.completed'));
        $this->assertEmpty($this->logged('account.delete.failed'));
    }

    public function test_same_email_and_username_can_be_reused_and_the_new_account_logs_in(): void
    {
        $this->deleteAs($this->makeUser());

        $this->postJson('/api/register', $this->registerPayload())->assertStatus(201);

        $new = User::where('email', 'reusable@example.com')->firstOrFail();
        $new->markEmailAsVerified();

        $this->postJson('/api/login', ['email' => 'reusable@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.id', $new->id);
    }
}
