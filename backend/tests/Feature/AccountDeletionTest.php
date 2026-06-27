<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $suffix = ''): User
    {
        return User::create([
            'name'     => 'Test User',
            'username' => 'testuser' . $suffix,
            'email'    => 'test' . $suffix . '@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_unauthenticated_cannot_delete_account(): void
    {
        $this->deleteJson('/api/account')->assertStatus(401);
    }

    public function test_authenticated_user_can_delete_account(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/account')
            ->assertStatus(200)
            ->assertJson(['message' => 'Your account has been deleted.']);
    }

    public function test_all_tokens_are_deleted_after_deletion(): void
    {
        $user = $this->makeUser();
        $user->createToken('mobile');
        $user->createToken('tablet');

        $this->assertEquals(2, $user->tokens()->count());

        $freshToken = $user->createToken('session')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$freshToken}")
            ->deleteJson('/api/account');

        // All personal access tokens for this user must be gone
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_user_data_is_anonymized_after_deletion(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/account');

        $fresh = User::find($user->id);
        $this->assertEquals('Deleted User', $fresh->name);
        $this->assertStringContainsString('@ballspot.deleted', $fresh->email);
        $this->assertStringStartsWith('deleted-', $fresh->username);
        $this->assertFalse(Hash::check('password', $fresh->password));
    }

    public function test_user_row_is_not_hard_deleted_after_anonymization(): void
    {
        $user  = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;
        $id    = $user->id;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/account')
            ->assertStatus(200);

        // Row still exists — anonymized, not hard-deleted (preserves foreign key refs)
        $this->assertDatabaseHas('users', ['id' => $id]);
        $this->assertDatabaseMissing('users', ['id' => $id, 'email' => 'test@example.com']);
    }
}
