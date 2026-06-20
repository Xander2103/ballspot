<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class GuessResultResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'distance' => $this->distance,
            'guess_x_ratio' => $this->guess_x_ratio,
            'guess_y_ratio' => $this->guess_y_ratio,
            'ball_x_ratio' => $this->round->challenge->ball_x_ratio,
            'ball_y_ratio' => $this->round->challenge->ball_y_ratio,
        ];
    }
}
