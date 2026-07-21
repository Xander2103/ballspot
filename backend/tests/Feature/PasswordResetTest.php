<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name'     => 'Reset Tester',
            'username' => 'resettester',
            'email'    => 'reset@example.com',
            'password' => Hash::make('oldpassword123'),
        ]);
    }

    public function test_forgot_password_returns_generic_success_for_existing_email(): void
    {
        Notification::fake();
        $this->makeUser();

        $response = $this->postJson('/api/forgot-password', ['email' => 'reset@example.com']);

        $response->assertOk()->assertJsonStructure(['message']);
        Notification::assertSentTo(
            User::where('email', 'reset@example.com')->first(),
            ResetPasswordNotification::class
        );
    }

    public function test_forgot_password_does_not_enumerate_unknown_email(): void
    {
        Notification::fake();

        $known = $this->makeUser();
        $knownResponse = $this->postJson('/api/forgot-password', ['email' => 'reset@example.com']);
        $unknownResponse = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

        // Identical generic response regardless of whether the email exists.
        $this->assertSame($knownResponse->json('message'), $unknownResponse->json('message'));
        $knownResponse->assertOk();
        $unknownResponse->assertOk();

        // No email ever sent for the unknown address.
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_reset_with_invalid_token_fails(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/reset-password', [
            'token'                 => 'totally-invalid-token',
            'email'                 => 'reset@example.com',
            'password'              => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ]);

        $response->assertStatus(422);
        // Old password still works.
        $this->assertTrue(Hash::check('oldpassword123', User::first()->password));
    }

    public function test_reset_with_valid_token_changes_password_and_old_password_stops_working(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ]);

        $response->assertOk();

        $user->refresh();
        // New password works, old one does not.
        $this->assertTrue(Hash::check('brandnewpass123', $user->password));
        $this->assertFalse(Hash::check('oldpassword123', $user->password));

        // Login with old password fails.
        $this->postJson('/api/login', [
            'email'    => 'reset@example.com',
            'password' => 'oldpassword123',
        ])->assertStatus(422);

        // Login with new password succeeds.
        $this->postJson('/api/login', [
            'email'    => 'reset@example.com',
            'password' => 'brandnewpass123',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_reset_revokes_existing_api_tokens(): void
    {
        $user = $this->makeUser();
        $user->createToken('mobile');
        $this->assertSame(1, $user->tokens()->count());

        $token = Password::broker()->createToken($user);
        $this->postJson('/api/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_reset_enforces_password_rules(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token'                 => $token,
            'email'                 => 'reset@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
