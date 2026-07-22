<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    /**
     * Virtual trophies only — no real prizes. Icons are plain emoji so we never
     * ship copyrighted assets. Idempotent via updateOrCreate on `code`.
     */
    public function run(): void
    {
        $badges = [
            ['code' => 'first_guess',        'name' => 'First Guess',        'icon' => '🎯', 'category' => 'general',    'rarity' => 'common',    'description' => 'Made your very first guess.'],
            ['code' => 'first_daily',        'name' => 'First Daily',        'icon' => '📅', 'category' => 'daily',      'rarity' => 'common',    'description' => 'Completed your first daily challenge.'],
            ['code' => 'seven_day_streak',   'name' => 'Seven-Day Streak',   'icon' => '🔥', 'category' => 'streak',     'rarity' => 'rare',      'description' => 'Played the daily challenge 7 days in a row.'],
            ['code' => 'thirty_day_streak',  'name' => 'Unstoppable',        'icon' => '⚡', 'category' => 'streak',     'rarity' => 'epic',      'description' => 'Played the daily challenge 30 days in a row.'],
            ['code' => 'perfect_guess',      'name' => 'Bullseye',           'icon' => '💯', 'category' => 'skill',      'rarity' => 'epic',      'description' => 'Scored a near-perfect guess.'],
            ['code' => 'top_10_percent_daily', 'name' => 'Sharp Shooter',    'icon' => '🎖️', 'category' => 'daily',      'rarity' => 'rare',      'description' => 'Finished in the top 10% of a daily challenge.'],
            ['code' => 'tournament_winner',  'name' => 'Tournament Winner',  'icon' => '🏆', 'category' => 'tournament', 'rarity' => 'epic',      'description' => 'Win a tournament.'],
            ['code' => 'daily_champion',     'name' => 'Daily Champion',     'icon' => '👑', 'category' => 'daily',      'rarity' => 'epic',      'description' => 'Finished #1 in a daily challenge.'],
            ['code' => 'weekly_top_10',      'name' => 'Weekly Top 10',      'icon' => '🌟', 'category' => 'daily',      'rarity' => 'rare',      'description' => 'Finished in the weekly top 10.'],
            ['code' => 'football_rookie',    'name' => 'Football Rookie',    'icon' => '⚽', 'category' => 'sport',      'rarity' => 'common',    'description' => 'Played your first football challenge.'],
            ['code' => 'football_expert',    'name' => 'Football Expert',    'icon' => '🥅', 'category' => 'sport',      'rarity' => 'epic',      'description' => 'Played 25 football challenges.'],

            // --- v1.7.4 canonical badge taxonomy -------------------------------
            // Some of these overlap semantically with legacy codes above (kept for
            // backward compatibility with already-earned badges). See BadgeService.
            ['code' => 'perfect_picker',     'name' => 'Perfect Picker',     'icon' => '🎯', 'category' => 'skill',      'rarity' => 'legendary', 'description' => 'Get a perfect 100% guess.'],
            ['code' => 'almost_perfect',     'name' => 'Almost Perfect',     'icon' => '🔥', 'category' => 'skill',      'rarity' => 'epic',      'description' => 'Score 95% or higher on a guess.'],
            ['code' => 'first_daily_win',    'name' => 'Daily Debut',        'icon' => '🌅', 'category' => 'daily',      'rarity' => 'common',    'description' => 'Complete your first Daily Challenge.'],
            ['code' => 'streak_3',           'name' => 'On a Roll',          'icon' => '⚡', 'category' => 'streak',     'rarity' => 'common',    'description' => 'Reach a 3-day daily streak.'],
            ['code' => 'streak_7',           'name' => 'Week Warrior',       'icon' => '🗓️', 'category' => 'streak',     'rarity' => 'rare',      'description' => 'Reach a 7-day daily streak.'],
            ['code' => 'streak_30',          'name' => 'Monthly Machine',    'icon' => '🚀', 'category' => 'streak',     'rarity' => 'legendary', 'description' => 'Reach a 30-day daily streak.'],
            ['code' => 'top_10_daily',       'name' => 'Top 10%',            'icon' => '🥇', 'category' => 'leaderboard', 'rarity' => 'rare',     'description' => 'Finish in the top 10% of a Daily Challenge.'],
            ['code' => 'multi_sport_starter', 'name' => 'Multi-Sport Starter', 'icon' => '🌍', 'category' => 'sport',    'rarity' => 'rare',      'description' => 'Play your first non-football challenge.'],
        ];

        foreach ($badges as $i => $badge) {
            Badge::updateOrCreate(
                ['code' => $badge['code']],
                array_merge($badge, ['sort_order' => $i + 1]),
            );
        }
    }
}
