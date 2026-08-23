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
            ['code' => 'podium_finish',      'name' => 'Podium Finish',      'icon' => '🥉', 'category' => 'tournament', 'rarity' => 'rare',      'description' => 'Finish in the top 3 of a tournament.'],

            // --- v1.7.9 challenge pack badges ---------------------------------
            ['code' => 'first_pack_completed', 'name' => 'Pack Finisher',    'icon' => '📦', 'category' => 'pack',       'rarity' => 'common',    'description' => 'Complete your first challenge pack.'],
            ['code' => 'perfect_pack',       'name' => 'Perfect Pack',       'icon' => '💎', 'category' => 'pack',       'rarity' => 'legendary', 'description' => 'Complete a pack with perfect scores on every challenge.'],
            ['code' => 'pack_master',        'name' => 'Pack Master',        'icon' => '🧠', 'category' => 'pack',       'rarity' => 'epic',      'description' => 'Complete 10 challenge packs.'],

            // --- v1.8.0 monthly competition badges (awarded on period close) ---
            ['code' => 'monthly_winner',     'name' => 'Monthly Winner',     'icon' => '🏆', 'category' => 'competition', 'rarity' => 'legendary', 'description' => 'Finish 1st in a monthly competition.'],
            ['code' => 'monthly_podium',     'name' => 'Monthly Podium',     'icon' => '🥉', 'category' => 'competition', 'rarity' => 'epic',      'description' => 'Finish in the top 3 of a monthly competition.'],
            ['code' => 'monthly_top_10',     'name' => 'Monthly Top 10',     'icon' => '🥇', 'category' => 'competition', 'rarity' => 'rare',      'description' => 'Finish in the top 10% of a monthly competition.'],

            // --- v1.8.6 public-beta sprint: social / host / consistency -------
            // "Sharp Scorer" (not the spec's "Sharp Shooter"): the display name
            // Sharp Shooter is already taken by top_10_percent_daily.
            ['code' => 'social_starter',     'name' => 'Social Starter',     'icon' => '🤝', 'category' => 'social',      'rarity' => 'common',    'description' => 'Add your first friend.'],
            ['code' => 'friendly_five',      'name' => 'Friendly Five',      'icon' => '👥', 'category' => 'social',      'rarity' => 'rare',      'description' => 'Have five friends at the same time.'],
            ['code' => 'host_starter',       'name' => 'Host Starter',       'icon' => '🏟️', 'category' => 'tournament',  'rarity' => 'common',    'description' => 'Create your first tournament.'],
            ['code' => 'tournament_regular', 'name' => 'Tournament Regular', 'icon' => '🎽', 'category' => 'tournament',  'rarity' => 'rare',      'description' => 'Complete five tournaments.'],
            ['code' => 'sharp_scorer',       'name' => 'Sharp Scorer',       'icon' => '🎯', 'category' => 'skill',       'rarity' => 'rare',      'description' => 'Score 90 or more on ten guesses.'],
            ['code' => 'pack_explorer',      'name' => 'Pack Explorer',      'icon' => '🧭', 'category' => 'pack',        'rarity' => 'rare',      'description' => 'Complete three different challenge packs.'],
            ['code' => 'daily_loyalist',     'name' => 'Daily Loyalist',     'icon' => '📅', 'category' => 'daily',       'rarity' => 'rare',      'description' => 'Play fourteen daily challenges.'],

            // --- v1.8.8 rank milestones + podium collector ---------------------
            // Rank badges key off PlayerRankService levels: 3 Pro, 5 Legend,
            // 6 Ball Master (see BadgeService::RANK_BADGES).
            ['code' => 'rising_star',        'name' => 'Rising Star',        'icon' => '📈', 'category' => 'rank',        'rarity' => 'rare',      'description' => 'Reach the Pro rank.'],
            ['code' => 'golden_touch',       'name' => 'Golden Touch',       'icon' => '✨', 'category' => 'rank',        'rarity' => 'epic',      'description' => 'Reach the Legend rank.'],
            ['code' => 'legend_status',      'name' => 'Legend Status',      'icon' => '🐐', 'category' => 'rank',        'rarity' => 'legendary', 'description' => 'Reach the Ball Master rank.'],
            ['code' => 'tournament_beast',   'name' => 'Tournament Beast',   'icon' => '🦁', 'category' => 'tournament',  'rarity' => 'epic',      'description' => 'Finish on the podium in three tournaments.'],
        ];

        foreach ($badges as $i => $badge) {
            Badge::updateOrCreate(
                ['code' => $badge['code']],
                array_merge($badge, ['sort_order' => $i + 1]),
            );
        }
    }
}
