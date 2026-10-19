<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pack linked to a competition. `challenge_ids` is the ordered snapshot
 * taken at rule lock; null until then (attempts copy it at start).
 */
class ClubCompetitionPack extends Model
{
    use HasFactory;

    protected $fillable = ['club_competition_id', 'challenge_pack_id', 'sort_order', 'challenge_ids', 'snapshotted_at'];

    protected function casts(): array
    {
        return [
            'sort_order'     => 'integer',
            'challenge_ids'  => 'array',
            'snapshotted_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(ClubCompetition::class, 'club_competition_id');
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(ChallengePack::class, 'challenge_pack_id');
    }

    /** @return list<int> */
    public function snapshotIds(): array
    {
        return array_values(array_map('intval', $this->challenge_ids ?? []));
    }
}
