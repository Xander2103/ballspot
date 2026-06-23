<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Challenge extends Model
{
    protected $fillable = [
        'sport_id', 'challenge_category_id',
        'title', 'hidden_image_path', 'original_image_path',
        'ball_x_ratio', 'ball_y_ratio', 'difficulty', 'status',
    ];

    protected function casts(): array
    {
        return [
            'ball_x_ratio' => 'float',
            'ball_y_ratio' => 'float',
        ];
    }

    public function sport(): BelongsTo { return $this->belongsTo(Sport::class); }
    public function category(): BelongsTo { return $this->belongsTo(ChallengeCategory::class, 'challenge_category_id'); }
}
