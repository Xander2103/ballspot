<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountDeletionService;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Launch hardening for account deletion: the flow must be atomic, logged on
 * failure, and must free the email + username for a fresh registration.
 */
class AccountReRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function makeUser(): User
    {
        $user = User::create([
            'name'     => 'Comeback Kid',
            'username' => 'comeback',
            'email'    => 'comeback@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    private function registerPayload(): array
    {
        return [
            'name' => 'Comeback Kid', 'username' => 'comeback', 'email' => 'comeback@example.com',
            'password' => 'password123', 'terms_accepted' => true, 'age_confirmed' => true,
        ];
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    public function test_delete_success_anonymizes_personal_fields_and_revokes_the_token(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('message', 'Your account has been deleted.');

        $fresh = $user->fresh();
        $this->assertSame('Deleted User', $fresh->name);
        $this->assertSame("deleted-{$user->id}", $fresh->username);
        $this->assertSame("deleted-{$user->id}@ballspot.deleted", $fresh->email);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertNull($fresh->email_verified_at);
        $this->assertFalse(Hash::check('password123', $fresh->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertNotEmpty($this->logged('account.deleted'));
    }

    public function test_the_revoked_token_no_longer_authenticates(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/account')->assertOk();

        // Fresh guard resolution (the test container caches the first user).
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_same_email_can_register_again_after_deletion(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->deleteJson('/api/account')->assertOk();

        $res = $this->postJson('/api/register', array_merge($this->registerPayload(), ['username' => 'someoneelse']));

        $res->assertStatus(201);
        $this->assertNotSame($user->id, $res->json('user.id'));
        $this->assertDatabaseHas('users', ['id' => $res->json('user.id'), 'email' => 'comeback@example.com']);
        $this->assertSame(2, User::count());
    }

    public function test_same_username_can_register_again_after_deletion(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->deleteJson('/api/account')->assertOk();

        $res = $this->postJson('/api/register', array_merge($this->registerPayload(), ['email' => 'other@example.com']));

        $res->assertStatus(201)->assertJsonPath('user.username', 'comeback');
    }

    public function test_same_email_and_username_together_can_register_again_and_log_in(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->deleteJson('/api/account')->assertOk();

        config(['ballspot.auth.require_email_verification' => false]);
        $this->postJson('/api/register', $this->registerPayload())->assertStatus(201);

        $this->postJson('/api/login', ['email' => 'comeback@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_anonymized_identifiers_never_collide_with_real_registrations(): void
    {
        // Anonymized rows carry deleted-{id} identifiers, so the ORIGINAL
        // email/username are free. The synthetic identifiers themselves stay
        // reserved (DB unique index) and are rejected with a clean 422.
        $ghost = $this->makeUser();
        $ghost->anonymized_at = now();
        $ghost->email = 'ghost-' . $ghost->id . '@ballspot.deleted';
        $ghost->username = 'ghost-' . $ghost->id;
        $ghost->save();

        $this->postJson('/api/register', array_merge($this->registerPayload(), [
            'email' => 'ghost-' . $ghost->id . '@ballspot.deleted', 'username' => 'ghost-' . $ghost->id,
        ]))->assertStatus(422); // still rejected — the DB unique index would explode otherwise
        $this->postJson('/api/register', $this->registerPayload())->assertStatus(201);
    }

    public function test_deletion_failure_rolls_back_logs_an_error_and_returns_a_friendly_message(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $service = \Mockery::mock(AccountDeletionService::class);
        $service->shouldReceive('delete')->once()->andThrow(new \RuntimeException('disk on fire at /srv/secret'));
        $this->app->instance(AccountDeletionService::class, $service);

        $res = $this->withToken($token)->deleteJson('/api/account');

        $res->assertStatus(500)
            ->assertJsonPath('deleted', false)
            ->assertJsonPath('message', 'We could not delete your account right now. Please try again in a moment or contact support.');
        $this->assertStringNotContainsString('disk on fire', $res->getContent());

        $failed = $this->logged('account.delete_failed');
        $this->assertNotEmpty($failed);
        $this->assertSame('error', strtolower($failed[0]->level->getName()));
        $this->assertSame($user->id, $failed[0]->context['user_id']);
        $this->assertSame('RuntimeException', $failed[0]->context['exception']);
        $this->assertArrayNotHasKey('email', $failed[0]->context);
    }

    public function test_a_real_mid_way_failure_leaves_the_account_intact(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        // Make the anonymizing UPDATE blow up at the database level. The token
        // revocation and side-table deletes ran BEFORE it inside the same
        // transaction, so they must all roll back.
        \Illuminate\Support\Facades\DB::unprepared(
            "CREATE TRIGGER block_anon BEFORE UPDATE ON users WHEN NEW.name = 'Deleted User' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END;"
        );

        $this->withToken($token)->deleteJson('/api/account')->assertStatus(500);

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);

        $fresh = $user->fresh();
        $this->assertSame('comeback@example.com', $fresh->email);
        $this->assertSame('comeback', $fresh->username);
        $this->assertNull($fresh->anonymized_at);
        $this->assertNotEmpty($this->logged('account.delete_failed'));
    }
}
