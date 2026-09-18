<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * "Stuck logged-in" hardening: a token must never resolve to a usable
 * session for a deleted (anonymized) or missing account, every rejection
 * carries a stable code the app switches on, and the reasons are logged
 * as categories (never tokens, codes or emails).
 */
class SessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

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
            'name'     => 'Active Player',
            'username' => 'activeplayer',
            'email'    => 'active@example.com',
            'password' => Hash::make('password123'),
        ], $overrides));
        $user->markEmailAsVerified();

        return $user;
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    // --- /me ---------------------------------------------------------------

    public function test_me_returns_the_user_for_a_valid_active_token(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.username', 'activeplayer')
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_me_rejects_an_anonymized_user_with_a_stable_code_and_revokes_the_token(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        // A token that somehow survived deletion (older backend, manual DB edit).
        $user->forceFill(['anonymized_at' => now()])->save();

        $res = $this->withToken($token)->getJson('/api/me');

        $res->assertStatus(401)
            ->assertJsonPath('code', 'account_deleted')
            ->assertJsonPath('message', 'This account has been deleted. You can create a new account with the same email.');
        $this->assertStringNotContainsString('Deleted User', $res->getContent());
        // Defense in depth: the stale token is gone for good.
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $log = $this->logged('me.failed_anonymized');
        $this->assertNotEmpty($log);
        $this->assertSame($user->id, $log[0]->context['user_id']);
        $this->assertArrayNotHasKey('token', $log[0]->context);
        $this->assertArrayNotHasKey('email', $log[0]->context);
    }

    public function test_every_authenticated_endpoint_rejects_an_anonymized_user(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        $user->forceFill(['anonymized_at' => now()])->save();

        $this->withToken($token)->getJson('/api/profile/stats')
            ->assertStatus(401)
            ->assertJsonPath('code', 'account_deleted');
    }

    public function test_me_without_a_token_answers_a_stable_session_invalid_code(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_invalid')
            ->assertJsonPath('message', 'Unauthenticated.');

        $log = $this->logged('me.failed_invalid_token');
        $this->assertNotEmpty($log);
        $this->assertSame('missing_token', $log[0]->context['reason']);
    }

    public function test_me_with_an_unknown_token_logs_invalid_token(): void
    {
        $this->withToken('1|definitely-not-a-real-token')->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_invalid');

        $log = $this->logged('me.failed_invalid_token');
        $this->assertNotEmpty($log);
        $this->assertSame('invalid_token', $log[0]->context['reason']);
        $this->assertStringNotContainsString('definitely-not-a-real-token', json_encode($log[0]->context));
    }

    public function test_me_with_a_token_whose_user_is_gone_logs_missing_user(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        // Hard-deleted row (never done by the app, but the token table has no FK).
        DB::table('users')->where('id', $user->id)->delete();

        $this->withToken($token)->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_invalid');

        $log = $this->logged('me.failed_missing_user');
        $this->assertNotEmpty($log);
        $this->assertSame($user->id, $log[0]->context['user_id']);
    }

    // --- login -------------------------------------------------------------

    public function test_login_rejects_an_anonymized_user_with_a_clear_code(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['anonymized_at' => now()])->save();

        $this->postJson('/api/login', ['email' => 'active@example.com', 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_deleted')
            ->assertJsonMissing(['token']);

        $log = $this->logged('auth.login_failed');
        $this->assertNotEmpty($log);
        $this->assertSame('anonymized_account', $log[0]->context['reason']);
    }

    public function test_login_still_answers_invalid_credentials_for_an_unknown_email(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    // --- account deletion ----------------------------------------------------

    public function test_delete_account_revokes_every_api_token_and_logs_it(): void
    {
        $user = $this->makeUser();
        $user->createToken('tablet');
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')->assertOk()->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $log = $this->logged('account.delete.tokens_revoked');
        $this->assertNotEmpty($log);
        $this->assertSame($user->id, $log[0]->context['user_id']);
        $this->assertSame(2, $log[0]->context['count']);
    }

    public function test_the_old_token_cannot_access_me_after_deletion(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_invalid');
    }

    public function test_an_admin_cannot_delete_their_account_from_the_app(): void
    {
        $admin = $this->makeUser(['username' => 'boss', 'email' => 'boss@example.com']);
        $admin->forceFill(['is_admin' => true])->save();
        $token = $admin->createToken('mobile')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')
            ->assertStatus(403)
            ->assertJsonPath('code', 'admin_account_protected');

        $fresh = $admin->fresh();
        $this->assertNull($fresh->anonymized_at);
        $this->assertSame('boss@example.com', $fresh->email);
        $this->assertTrue($fresh->is_admin);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $admin->id]);
        $this->assertNotEmpty($this->logged('account.delete.rejected_admin'));
    }
}
