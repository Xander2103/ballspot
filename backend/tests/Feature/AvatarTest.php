<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_avatar_upload_requires_auth(): void
    {
        $this->postJson('/api/me/avatar', [])->assertUnauthorized();
    }

    public function test_user_can_upload_valid_image(): void
    {
        Storage::fake('public');
        [$user, $token] = $this->auth();

        $res = $this->withToken($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['avatar_url']);
        $this->assertNotNull($res->json('avatar_url'));

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('avatars/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_user_can_upload_valid_png(): void
    {
        Storage::fake('public');
        [$user, $token] = $this->auth();

        $res = $this->withToken($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 200, 200),
        ]);

        $res->assertOk()->assertJsonStructure(['avatar_url']);
        $this->assertNotNull($res->json('avatar_url'));
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);
    }

    public function test_upload_rejects_non_image_file(): void
    {
        Storage::fake('public');
        [, $token] = $this->auth();

        $res = $this->withToken($token)->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('avatar');
    }

    public function test_upload_rejects_svg(): void
    {
        Storage::fake('public');
        [, $token] = $this->auth();

        $res = $this->withToken($token)->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->create('icon.svg', 5, 'image/svg+xml'),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('avatar');
    }

    public function test_upload_rejects_too_large_image(): void
    {
        Storage::fake('public');
        [, $token] = $this->auth();

        // 3 MB > 2 MB limit
        $res = $this->withToken($token)->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('huge.jpg')->size(3000),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('avatar');
    }

    public function test_uploading_new_avatar_replaces_old_file(): void
    {
        Storage::fake('public');
        [$user, $token] = $this->auth();

        $first = $this->withToken($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('one.jpg', 100, 100),
        ]);
        $firstPath = $user->fresh()->avatar_path;

        $this->withToken($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('two.jpg', 100, 100),
        ])->assertOk();

        $secondPath = $user->fresh()->avatar_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_user_can_delete_avatar(): void
    {
        Storage::fake('public');
        [$user, $token] = $this->auth();

        $this->withToken($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('one.jpg', 100, 100),
        ])->assertOk();

        $res = $this->withToken($token)->deleteJson('/api/me/avatar');

        $res->assertOk();
        $res->assertJsonPath('avatar_url', null);
        $this->assertNull($user->fresh()->avatar_path);
    }
}
