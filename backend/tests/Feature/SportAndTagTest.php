<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Sport;
use App\Models\Tag;
use Database\Seeders\SportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportAndTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_sports_are_seeded_with_football_active(): void
    {
        $this->seed(SportSeeder::class);

        foreach (['football', 'golf', 'tennis', 'hockey', 'cricket', 'american_football', 'basketball'] as $slug) {
            $this->assertDatabaseHas('sports', ['slug' => $slug]);
        }

        $this->assertSame(7, Sport::count());
        $this->assertTrue(Sport::where('slug', 'football')->first()->is_active);
        // Football is the only active sport.
        $this->assertSame(1, Sport::active()->count());
    }

    public function test_tags_attach_to_challenge(): void
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Tagged Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        $premier = Tag::findOrCreateByName('Premier League', 'league');
        $england = Tag::findOrCreateByName('England', 'country');
        $challenge->tags()->sync([$premier->id, $england->id]);

        $this->assertCount(2, $challenge->fresh()->tags);
        $this->assertTrue($challenge->tags->pluck('slug')->contains('premier-league'));
    }

    public function test_find_or_create_by_name_is_idempotent(): void
    {
        $a = Tag::findOrCreateByName('Corner Kick', 'moment_type');
        $b = Tag::findOrCreateByName('corner kick');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Tag::count());
    }
}
