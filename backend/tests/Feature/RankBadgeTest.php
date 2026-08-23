<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\XpEvent;
use App\Services\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function giveXp(User $user, int $amount): void
    {
        XpEvent::create([
            'user_id'     => $user->id,
            'source_type' => 'test_grant',
            'source_id'   => $user->id,
            'amount'      => $amount,
            'reason'      => 'test',
        ]);
    }

    public function test_pro_rank_awards_rising_star_only(): void
    {
        $user = User::factory()->create();
        $this->giveXp($user, 10000); // Pro (level 3)

        $awarded = app(BadgeService::class)->evaluateRankBadges($user);

        $this->assertSame(['rising_star'], array_map(fn ($b) => $b->code, $awarded));
        $this->assertTrue($user->badges()->where('code', 'rising_star')->exists());
        $this->assertFalse($user->badges()->where('code', 'golden_touch')->exists());
    }

    public function test_ball_master_awards_all_three_rank_badges(): void
    {
        $user = User::factory()->create();
        $this->giveXp($user, 100000); // Ball Master (level 6)

        app(BadgeService::class)->evaluateRankBadges($user);

        foreach (['rising_star', 'golden_touch', 'legend_status'] as $code) {
            $this->assertTrue($user->badges()->where('code', $code)->exists(), $code);
        }
    }

    public function test_rank_badges_are_not_duplicated(): void
    {
        $user = User::factory()->create();
        $this->giveXp($user, 10000);

        $svc = app(BadgeService::class);
        $svc->evaluateRankBadges($user);
        $second = $svc->evaluateRankBadges($user);

        $this->assertSame([], $second);
        $this->assertSame(1, $user->badges()->where('code', 'rising_star')->count());
    }

    public function test_rookie_gets_no_rank_badges(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], app(BadgeService::class)->evaluateRankBadges($user));
    }
}
