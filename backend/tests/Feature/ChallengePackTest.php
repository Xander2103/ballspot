<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengePackTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function readyChallenge(string $title = 'Ready'): Challenge
    {
        return Challenge::create([
            'sport_id'          => $this->sport()->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    private function pack(array $attrs = []): ChallengePack
    {
        return ChallengePack::create(array_merge([
            'name'       => 'Belgium Pack',
            'slug'       => 'belgium-pack',
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'sport_id'   => $this->sport()->id,
        ], $attrs));
    }

    public function test_admin_can_create_a_pack(): void
    {
        $this->actingAs($this->admin())->post('/admin/packs', [
            'name'       => 'Barça Pack',
            'status'     => 'draft',
            'visibility' => 'public',
        ])->assertRedirect(); // -> edit page

        $this->assertDatabaseHas('challenge_packs', ['name' => 'Barça Pack', 'slug' => 'barca-pack']);
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->admin();
        $pack = $this->pack();
        $pack->challenges()->attach($this->readyChallenge()->id);

        $this->actingAs($admin)->get('/admin/packs')->assertOk();
        $this->actingAs($admin)->get('/admin/packs/create')->assertOk();
        $this->actingAs($admin)->get("/admin/packs/{$pack->id}/edit")->assertOk();
        // Competition read-only settings page renders too.
        $this->actingAs($admin)->get('/admin/competition')->assertOk();
    }

    public function test_non_admin_cannot_manage_packs(): void
    {
        $this->get('/admin/packs')->assertRedirect(route('admin.login'));
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/admin/packs')->assertForbidden();
    }

    public function test_pack_can_contain_challenges(): void
    {
        $pack = $this->pack();
        $c1 = $this->readyChallenge('A');
        $c2 = $this->readyChallenge('B');

        $pack->challenges()->sync([$c1->id => ['sort_order' => 0], $c2->id => ['sort_order' => 1]]);

        $this->assertSame(2, $pack->challenges()->count());
        // Detaching never deletes the challenge.
        $pack->challenges()->detach($c1->id);
        $this->assertDatabaseHas('challenges', ['id' => $c1->id]);
    }

    public function test_active_public_packs_appear_in_api(): void
    {
        [, $token] = $this->auth();
        $pack = $this->pack(['is_featured' => true]);
        $pack->challenges()->attach($this->readyChallenge()->id);

        $res = $this->withToken($token)->getJson('/api/packs');

        $res->assertOk();
        $res->assertJsonFragment(['slug' => 'belgium-pack', 'is_featured' => true]);
        $res->assertJsonStructure(['data' => [['id', 'name', 'slug', 'sport', 'challenge_count', 'is_featured']]]);
    }

    public function test_draft_hidden_and_archived_packs_do_not_appear_in_api(): void
    {
        [, $token] = $this->auth();
        $this->pack(['name' => 'Draft', 'slug' => 'draft', 'status' => 'draft']);
        $this->pack(['name' => 'Hidden', 'slug' => 'hidden', 'visibility' => 'hidden']);
        $this->pack(['name' => 'Archived', 'slug' => 'archived', 'status' => 'archived']);

        $res = $this->withToken($token)->getJson('/api/packs');

        $res->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_pack_detail_only_exposes_safe_public_fields(): void
    {
        [, $token] = $this->auth();
        $pack = $this->pack();
        $pack->challenges()->attach($this->readyChallenge('Playable')->id);

        $res = $this->withToken($token)->getJson('/api/packs/belgium-pack');

        $res->assertOk();
        $data = $res->json('data');
        // Admin-only fields must never leak.
        $this->assertArrayNotHasKey('status', $data);
        $this->assertArrayNotHasKey('visibility', $data);
        // Challenge summaries must never leak the ball position.
        $this->assertArrayHasKey('challenges', $data);
        $this->assertArrayNotHasKey('ball_x_ratio', $data['challenges'][0]);
        $this->assertArrayNotHasKey('ball_y_ratio', $data['challenges'][0]);
    }

    public function test_pack_detail_only_lists_ready_challenges(): void
    {
        [, $token] = $this->auth();
        $pack = $this->pack();
        $pack->challenges()->attach($this->readyChallenge('Ready one')->id);
        // Draft challenge (not ready) should be hidden from normal users.
        $draft = Challenge::create([
            'sport_id' => $this->sport()->id, 'title' => 'Draft challenge',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'draft', 'hidden_image_path' => 'x.jpg',
        ]);
        $pack->challenges()->attach($draft->id);

        $res = $this->withToken($token)->getJson('/api/packs/belgium-pack');

        $res->assertOk();
        $this->assertSame(1, $res->json('data.challenge_count'));
        $this->assertCount(1, $res->json('data.challenges'));
    }

    public function test_hidden_pack_detail_returns_404(): void
    {
        [, $token] = $this->auth();
        $this->pack(['slug' => 'secret', 'visibility' => 'hidden']);

        $this->withToken($token)->getJson('/api/packs/secret')->assertNotFound();
    }
}
