<?php

namespace App\Models;

use App\Enums\CompetitionPhase;
use App\Enums\CompetitionState;
use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use App\Enums\ScoreRule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A private competition of one club (plan §4.6). Only `state` is stored; the
 * phase shown to players is derived by phase() from state + the window
 * [starts_at, ends_at) so it never depends on the scheduler.
 *
 * Behaviour (rule lock, publish/cancel/void, standings) lives in the
 * competition services — this model only holds data and pure derivations.
 */
class ClubCompetition extends Model
{
    use HasFactory;

    public const TIE_BREAK_EARLIEST_REACHED = 'earliest_reached';

    protected $fillable = [
        'club_id', 'name', 'description', 'state', 'starts_at', 'ends_at',
        'timezone', 'replay_mode', 'max_attempts', 'score_rule', 'tie_break',
        'normal_play_policy', 'rules_locked_at', 'published_at', 'finalized_at',
        'cancelled_at', 'voided_at', 'void_reason', 'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'state'              => 'draft',
        'replay_mode'        => 'single_attempt',
        'max_attempts'       => 1,
        'score_rule'         => 'first',
        'tie_break'          => self::TIE_BREAK_EARLIEST_REACHED,
        'normal_play_policy' => 'blocked',
        'participants_count' => 0,
        'version'            => 1,
    ];

    protected function casts(): array
    {
        return [
            'state'              => CompetitionState::class,
            'replay_mode'        => ReplayMode::class,
            'score_rule'         => ScoreRule::class,
            'normal_play_policy' => NormalPlayPolicy::class,
            'max_attempts'       => 'integer',
            'participants_count' => 'integer',
            'version'            => 'integer',
            'starts_at'          => 'datetime',
            'ends_at'            => 'datetime',
            'rules_locked_at'    => 'datetime',
            'published_at'       => 'datetime',
            'finalized_at'       => 'datetime',
            'cancelled_at'       => 'datetime',
            'voided_at'          => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ClubCompetition $competition) {
            $competition->public_id ??= (string) Str::ulid();
            if ($competition->timezone === null) {
                $competition->timezone = $competition->club?->timezone
                    ?? (string) (config('ballspot.competition.timezone') ?: config('app.timezone', 'UTC'));
            }
        });
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /** Link rows (with the challenge snapshot), in display order. */
    public function competitionPacks(): HasMany
    {
        return $this->hasMany(ClubCompetitionPack::class)->orderBy('sort_order')->orderBy('id');
    }

    public function packs(): BelongsToMany
    {
        return $this->belongsToMany(ChallengePack::class, 'club_competition_packs')
            ->withPivot('sort_order', 'challenge_ids', 'snapshotted_at')
            ->withTimestamps()
            ->orderBy('club_competition_packs.sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ClubCompetitionAttempt::class);
    }

    public function packResults(): HasMany
    {
        return $this->hasMany(ClubCompetitionPackResult::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ClubCompetitionEntry::class);
    }

    public function finishes(): HasMany
    {
        return $this->hasMany(ClubCompetitionFinish::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeInState(Builder $query, CompetitionState ...$states): Builder
    {
        return $query->whereIn('state', array_map(fn (CompetitionState $s) => $s->value, $states));
    }

    /**
     * Competitions that currently block normal play of their linked packs
     * (D15): published (i.e. not draft/finalized/cancelled/voided) with
     * normal_play_policy = blocked.
     */
    public function scopeReservingNormalPlay(Builder $query): Builder
    {
        return $query->where('state', CompetitionState::Published->value)
            ->where('normal_play_policy', NormalPlayPolicy::Blocked->value);
    }

    /** Phase upcoming (published, window not started). */
    public function scopeUpcoming(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->where('state', CompetitionState::Published->value)->where('starts_at', '>', $now ?? now());
    }

    /** Phase active (published, inside the window). */
    public function scopeLive(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->where('state', CompetitionState::Published->value)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now);
    }

    /** Phases ended + finalized ("completed" in the API). */
    public function scopeCompleted(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->where(fn (Builder $q) => $q
            ->where('state', CompetitionState::Finalized->value)
            ->orWhere(fn (Builder $qq) => $qq->where('state', CompetitionState::Published->value)->where('ends_at', '<=', $now)));
    }

    // ------------------------------------------------------------------
    // Derivations
    // ------------------------------------------------------------------

    /**
     * draft → upcoming (published, now < starts_at) → active (starts_at ≤ now
     * < ends_at) → ended (now ≥ ends_at, not finalized) → finalized; plus
     * cancelled / voided.
     */
    public function phase(?CarbonInterface $now = null): CompetitionPhase
    {
        $now ??= now();

        return match ($this->state) {
            CompetitionState::Draft     => CompetitionPhase::Draft,
            CompetitionState::Cancelled => CompetitionPhase::Cancelled,
            CompetitionState::Voided    => CompetitionPhase::Voided,
            CompetitionState::Finalized => CompetitionPhase::Finalized,
            CompetitionState::Published => match (true) {
                $now->lt($this->starts_at) => CompetitionPhase::Upcoming,
                $now->lt($this->ends_at)   => CompetitionPhase::Active,
                default                    => CompetitionPhase::Ended,
            },
        };
    }

    /** Rules are locked from starts_at on, independent of the scheduler (§5.7). */
    public function isLocked(?CarbonInterface $now = null): bool
    {
        return $this->rules_locked_at !== null || ($now ?? now())->gte($this->starts_at);
    }

    /** True while the timestamp lies inside [starts_at, ends_at). */
    public function isWithinWindow(CarbonInterface $at): bool
    {
        return $at->gte($this->starts_at) && $at->lt($this->ends_at);
    }

    public function blocksNormalPlay(): bool
    {
        return $this->state === CompetitionState::Published
            && $this->normal_play_policy === NormalPlayPolicy::Blocked;
    }
}
