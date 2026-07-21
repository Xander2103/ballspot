<?php
namespace App\Http\Resources;
use App\Models\Guess;
use Illuminate\Http\Resources\Json\JsonResource;

class GuessResultResource extends JsonResource
{
    public function toArray($request): array
    {
        $challenge = $this->round->challenge;

        // Rank / percentile insight within this round (players who have guessed it).
        $roundId    = $this->league_round_id;
        $myScore    = $this->score;
        $total      = Guess::where('league_round_id', $roundId)->count();
        $rank       = Guess::where('league_round_id', $roundId)->where('score', '>', $myScore)->count() + 1;
        $beaten     = Guess::where('league_round_id', $roundId)->where('score', '<', $myScore)->count();
        $betterThan = $total > 1 ? (int) round($beaten / $total * 100) : 0;

        return [
            'id' => $this->id,
            'score' => $this->score,
            'distance' => $this->distance,
            'guess_x_ratio' => $this->guess_x_ratio,
            'guess_y_ratio' => $this->guess_y_ratio,
            'ball_x_ratio' => $challenge->ball_x_ratio,
            'ball_y_ratio' => $challenge->ball_y_ratio,
            'reveal_image_url' => $challenge->original_image_path
                ? asset('storage/' . $challenge->original_image_path)
                : null,
            'rank' => $rank,
            'total_players' => $total,
            'better_than_percentage' => $betterThan,
        ];
    }
}
