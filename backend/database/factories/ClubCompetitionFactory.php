<?php

namespace Database\Factories;

use App\Enums\CompetitionState;
use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use App\Enums\ScoreRule;
use App\Models\Club;
use App\Models\ClubCompetition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default: a draft that starts tomorrow and runs a week.
 *
 * @extends Factory<ClubCompetition>
 */
class ClubCompetitionFactory extends Factory
{
    protected $model = ClubCompetition::class;

    public function definition(): array
    {
        return [
            'club_id'            => Club::factory(),
            'name'               => 'Cup ' . fake()->unique()->numberBetween(1, 999999),
            'state'              => CompetitionState::Draft,
            'starts_at'          => now()->addDay(),
            'ends_at'            => now()->addDays(8),
            'timezone'           => 'Europe/Brussels',
            'replay_mode'        => ReplayMode::SingleAttempt,
            'max_attempts'       => 1,
            'score_rule'         => ScoreRule::First,
            'normal_play_policy' => NormalPlayPolicy::Blocked,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['state' => CompetitionState::Published, 'published_at' => now()]);
    }

    /** Published and inside its window. */
    public function live(): static
    {
        return $this->published()->state(fn () => ['starts_at' => now()->subHour(), 'ends_at' => now()->addDays(7)]);
    }

    /** Published, window over, not finalized yet. */
    public function ended(): static
    {
        return $this->published()->state(fn () => ['starts_at' => now()->subDays(8), 'ends_at' => now()->subHour()]);
    }

    public function finalized(): static
    {
        return $this->ended()->state(fn () => ['state' => CompetitionState::Finalized, 'finalized_at' => now()]);
    }

    public function multipleAttempts(int $max = 3, ScoreRule $rule = ScoreRule::Best): static
    {
        return $this->state(fn () => ['replay_mode' => ReplayMode::MultipleAttempts, 'max_attempts' => $max, 'score_rule' => $rule]);
    }
}
