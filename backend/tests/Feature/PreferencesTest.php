<?php

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function activeSport(string $slug = 'football', string $name = 'Football'): Sport
    {
        return Sport::create([
            'name' => $name, 'slug' => $slug, 'emoji' => '⚽',
            'object_name' => 'ball', 'primary_color' => '#00c853', 'is_active' => true,
        ]);
    }

    public function test_preferences_require_auth(): void
    {
        $this->getJson('/api/me/preferences')->assertUnauthorized();
        $this->patchJson('/api/me/preferences', [])->assertUnauthorized();
    }

    public function test_defaults_to_classic_theme_and_no_sport(): void
    {
        [, $token] = $this->auth();

        $res = $this->withToken($token)->getJson('/api/me/preferences');

        $res->assertOk();
        $res->assertJsonPath('selected_theme', 'classic');
        $res->assertJsonPath('preferred_sport', null);
        $res->assertJsonStructure(['available_themes']);
    }

    public function test_user_can_update_preferred_sport(): void
    {
        [$user, $token] = $this->auth();
        $sport = $this->activeSport();

        $res = $this->withToken($token)->patchJson('/api/me/preferences', [
            'preferred_sport_id' => $sport->id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('preferred_sport.slug', 'football');
        $this->assertSame($sport->id, $user->fresh()->preferred_sport_id);
    }

    public function test_cannot_set_inactive_sport(): void
    {
        [, $token] = $this->auth();
        $inactive = Sport::create([
            'name' => 'Tennis', 'slug' => 'tennis', 'emoji' => '🎾',
            'object_name' => 'ball', 'primary_color' => '#cddc39', 'is_active' => false,
        ]);

        $res = $this->withToken($token)->patchJson('/api/me/preferences', [
            'preferred_sport_id' => $inactive->id,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('preferred_sport_id');
    }

    public function test_user_can_update_theme(): void
    {
        [$user, $token] = $this->auth();

        $res = $this->withToken($token)->patchJson('/api/me/preferences', [
            'selected_theme' => 'tournament_blue',
        ]);

        $res->assertOk();
        $res->assertJsonPath('selected_theme', 'tournament_blue');
        $this->assertSame('tournament_blue', $user->fresh()->selected_theme);
    }

    public function test_invalid_theme_is_rejected(): void
    {
        [, $token] = $this->auth();

        $res = $this->withToken($token)->patchJson('/api/me/preferences', [
            'selected_theme' => 'neon_disco',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('selected_theme');
    }

    public function test_me_endpoint_includes_preferences(): void
    {
        [$user, $token] = $this->auth();
        $sport = $this->activeSport();
        $user->update(['preferred_sport_id' => $sport->id, 'selected_theme' => 'pitch_green']);

        $res = $this->withToken($token)->getJson('/api/me');

        $res->assertOk();
        $res->assertJsonPath('data.selected_theme', 'pitch_green');
        $res->assertJsonPath('data.preferred_sport.slug', 'football');
        $res->assertJsonPath('data.avatar_url', null);
    }
}
