<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\LoginVerificationCode;
use App\Models\NotificationSetting;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_deletion_removes_avatar_file_and_clears_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/me.jpg', 'fake-image-bytes');

        $user = $this->makeUser();
        $user->update(['avatar_path' => 'avatars/me.jpg']);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/account')
            ->assertStatus(200);

        Storage::disk('public')->assertMissing('avatars/me.jpg');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_deletion_removes_push_tokens_settings_and_verification_codes(): void
    {
        $user = $this->makeUser();
        PushToken::create([
            'user_id'      => $user->id,
            'token'        => 'ExponentPushToken[abc123]',
            'platform'     => 'android',
            'last_seen_at' => now(),
        ]);
        NotificationSetting::create(array_merge(
            ['user_id' => $user->id],
            NotificationSetting::defaults(),
        ));
        EmailVerificationCode::create([
            'user_id'      => $user->id,
            'code_hash'    => Hash::make('123456'),
            'code_sent_at' => now(),
            'expires_at'   => now()->addHour(),
        ]);
        LoginVerificationCode::create([
            'verification_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id'         => $user->id,
            'email'           => $user->email,
            'code_hash'       => Hash::make('654321'),
            'code_sent_at'    => now(),
            'expires_at'      => now()->addMinutes(10),
        ]);

        $token = $user->createToken('mobile')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/account')
            ->assertStatus(200);

        $this->assertDatabaseMissing('push_tokens', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('notification_settings', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('email_verification_codes', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('login_verification_codes', ['user_id' => $user->id]);
    }

    public function test_deletion_retains_gameplay_history(): void
    {
        $user  = $this->makeUser();
        \App\Models\XpEvent::create([
            'user_id'     => $user->id,
            'source_type' => \App\Models\XpEvent::SOURCE_DAILY_GUESS,
            'source_id'   => 1,
            'amount'      => 10,
            'reason'      => 'Daily challenge guess',
        ]);

        $token = $user->createToken('mobile')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/account')
            ->assertStatus(200);

        // Score/XP history survives (anonymized via the user row).
        $this->assertDatabaseHas('xp_events', ['user_id' => $user->id]);
    }
}
