<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    public function dailyChallenges(): HasMany { return $this->hasMany(DailyChallenge::class); }

    /** Challenge has everything required to be playable. */
    public function isReady(): bool
    {
        return !empty($this->title)
            && !empty($this->hidden_image_path)
            && $this->ball_x_ratio !== null
            && $this->ball_y_ratio !== null
            && $this->sport_id !== null;
    }

    /** Challenge is ready AND active — safe to use as a daily challenge. */
    public function isReadyForDaily(): bool
    {
        return $this->isReady() && $this->status === 'active';
    }

    /** True if the challenge is one of the shipped demo placeholders. */
    public function isDemoContent(): bool
    {
        static $demoTitles = ['Corner Kick', 'Center Field', 'Penalty Spot', 'Crowd Scene', 'Goal Line', 'Kick Off'];
        return in_array($this->title, $demoTitles, true);
    }
}
