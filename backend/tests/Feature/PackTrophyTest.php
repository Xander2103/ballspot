<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Sport;
use App\Models\User;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackTrophyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

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
        $pack = ChallengePack::create(array_merge([
            'name'       => 'Beginner Football Pack',
            'slug'       => 'beginner-football-pack',
            'status'     => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC,
            'difficulty' => 'easy',
            'sport_id'   => $this->sport()->id,
        ], $attrs));

        $pack->challenges()->attach($this->readyChallenge()->id, ['sort_order' => 0]);

        return $pack;
    }

    private function updatePayload(ChallengePack $pack, array $extra = []): array
    {
        return array_merge([
            'name'       => $pack->name,
            'status'     => $pack->status,
            'visibility' => $pack->visibility,
            'difficulty' => $pack->difficulty,
        ], $extra);
    }

    /** Play the pack to completion for the given token. Returns the final guess response. */
    private function completePack(string $token, ChallengePack $pack)
    {
        $start = $this->actingWithToken($token)->postJson("/api/packs/{$pack->slug}/start")->json();
        $attemptId = $start['attempt']['id'];
        $challengeId = $start['challenge']['id'];

        $res = null;
        do {
            $res = $this->actingWithToken($token)->postJson("/api/pack-attempts/{$attemptId}/guess", [
                'challenge_id' => $challengeId,
                'guessed_x'    => 0.5,
                'guessed_y'    => 0.5,
            ]);
            $res->assertOk();
            $challengeId = $res->json('next_challenge.id');
        } while (!$res->json('pack_completed'));

        return $res;
    }

    public function test_admin_can_enable_pack_trophy_and_badge_is_created(): void
    {
        $pack = $this->pack();

        $this->actingAs($this->admin())
            ->put("/admin/packs/{$pack->id}", $this->updatePayload($pack, ['award_completion_trophy' => '1']))
            ->assertRedirect();

        $pack->refresh();
        $this->assertNotNull($pack->completion_badge_id);

        $badge = Badge::find($pack->completion_badge_id);
        $this->assertSame("pack_{$pack->id}_completed", $badge->code);
        $this->assertSame('Beginner Football Pack Completed', $badge->name);
        $this->assertSame('pack', $badge->category);
    }

    public function test_repeated_edits_do_not_duplicate_the_trophy(): void
    {
        $pack = $this->pack();
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/packs/{$pack->id}", $this->updatePayload($pack, ['award_completion_trophy' => '1']));
        $firstBadgeId = $pack->fresh()->completion_badge_id;

        $this->actingAs($admin)->put("/admin/packs/{$pack->id}", $this->updatePayload($pack, ['award_completion_trophy' => '1']));

        $this->assertSame($firstBadgeId, $pack->fresh()->completion_badge_id);
        $this->assertSame(1, Badge::where('code', "pack_{$pack->id}_completed")->count());
    }

    public function test_completing_pack_awards_trophy_once(): void
    {
        $pack = $this->pack();
        $this->actingAs($this->admin())
            ->put("/admin/packs/{$pack->id}", $this->updatePayload($pack, ['award_completion_trophy' => '1']));

        [$user, $token] = $this->auth();
        $res = $this->completePack($token, $pack->fresh());

        $badge = Badge::where('code', "pack_{$pack->id}_completed")->first();
        $this->assertContains($badge->code, array_column($res->json('new_badges') ?? [], 'code'));
        $this->assertSame(1, $user->badges()->where('badges.id', $badge->id)->count());
    }

    public function test_replaying_pack_does_not_award_duplicate_trophy(): void
    {
        $pack = $this->pack();
        $this->actingAs($this->admin())
            ->put("/admin/packs/{$pack->id}", $this->updatePayload($pack, ['award_completion_trophy' => '1']));

        [$user, $token] = $this->auth();
        $this->completePack($token, $pack->fresh());
        $res = $this->completePack($token, $pack->fresh()); // replay

        $badge = Badge::where('code', "pack_{$pack->id}_completed")->first();
        $this->assertSame(1, $user->badges()->where('badges.id', $badge->id)->count());
        $this->assertNotContains($badge->code, array_column($res->json('new_badges') ?? [], 'code'));
    }

    public function test_disabling_trophy_prevents_future_awards(): void
    {
        $pack = $this->pack();
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/packs/{$pack->id}", $this->updatePayload($pack, ['award_completion_trophy' => '1']));
        $this->assertNotNull($pack->fresh()->completion_badge_id);

        // Checkbox left unchecked on a later edit -> trophy disabled.
        $this->actingAs($admin)->put("/admin/packs/{$pack->id}", $this->updatePayload($pack));
        $this->assertNull($pack->fresh()->completion_badge_id);

        [$user, $token] = $this->auth();
        $this->completePack($token, $pack->fresh());

        $badge = Badge::where('code', "pack_{$pack->id}_completed")->first();
        $this->assertSame(0, $user->badges()->where('badges.id', $badge->id)->count());
    }

    public function test_pack_without_trophy_still_completes_normally(): void
    {
        $pack = $this->pack();
        [, $token] = $this->auth();

        $res = $this->completePack($token, $pack);

        $this->assertTrue((bool) $res->json('pack_completed'));
        // Generic pack badge still works; no pack-specific badge exists.
        $this->assertContains('first_pack_completed', array_column($res->json('new_badges') ?? [], 'code'));
        $this->assertSame(0, Badge::where('code', "pack_{$pack->id}_completed")->count());
    }
}
