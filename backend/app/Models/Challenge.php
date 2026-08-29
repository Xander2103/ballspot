<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    /** Usage pools - see the 2026_08_29 usage_pool migration for semantics. */
    public const POOL_DAILY      = 'daily';
    public const POOL_TOURNAMENT = 'tournament';
    public const POOL_PACK       = 'pack';
    public const POOL_GENERAL    = 'general';

    public const POOLS = [self::POOL_DAILY, self::POOL_TOURNAMENT, self::POOL_PACK, self::POOL_GENERAL];

    /** Pools whose challenges may be scheduled as a Daily Challenge. */
    public const DAILY_POOLS = [self::POOL_DAILY, self::POOL_GENERAL];

    /** Pools whose challenges may be drawn into tournament rounds. */
    public const TOURNAMENT_POOLS = [self::POOL_TOURNAMENT, self::POOL_GENERAL];

    protected $fillable = [
        'sport_id', 'challenge_category_id',
        'title', 'hidden_image_path', 'original_image_path',
        'ball_x_ratio', 'ball_y_ratio', 'difficulty', 'status', 'usage_pool',
    ];

    protected $attributes = [
        'usage_pool' => self::POOL_GENERAL,
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
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class, 'challenge_tag'); }
    public function subcategories(): BelongsToMany { return $this->belongsToMany(ChallengeSubcategory::class, 'challenge_subcategory')->withTimestamps(); }
    public function packs(): BelongsToMany { return $this->belongsToMany(ChallengePack::class, 'challenge_pack_challenge')->withPivot('sort_order')->withTimestamps(); }

    // ------------------------------------------------------------------
    // Fairness rules (v1.8.9)
    // ------------------------------------------------------------------

    /**
     * Any row in daily_challenges - past, today, scheduled or archived - marks
     * the challenge as permanently Daily-used, regardless of usage_pool.
     */
    public function scopeDailyUsed(Builder $query): Builder
    {
        return $query->whereHas('dailyChallenges');
    }

    public function scopeNotDailyUsed(Builder $query): Builder
    {
        return $query->whereDoesntHave('dailyChallenges');
    }

    /** Pool + status filter for the Daily scheduler. Readiness is checked in PHP. */
    public function scopeDailyPool(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereIn('usage_pool', self::DAILY_POOLS);
    }

    /**
     * Challenges a NEW tournament may draw from: active, tournament-or-general
     * pool, never used as a daily. Readiness is still checked in PHP.
     */
    public function scopeTournamentEligible(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereIn('usage_pool', self::TOURNAMENT_POOLS)
            ->notDailyUsed();
    }

    public function isDailyUsed(): bool
    {
        if (array_key_exists('used_as_daily', $this->attributes)) {
            return (bool) $this->attributes['used_as_daily'];
        }
        return $this->dailyChallenges()->exists();
    }

    public function isInDailyPool(): bool
    {
        return in_array($this->usage_pool, self::DAILY_POOLS, true);
    }

    public function isInTournamentPool(): bool
    {
        return in_array($this->usage_pool, self::TOURNAMENT_POOLS, true);
    }

    /** Challenge has everything required to be playable. */
    public function isReady(): bool
    {
        return !empty($this->title)
            && !empty($this->hidden_image_path)
            && $this->ball_x_ratio !== null
            && $this->ball_y_ratio !== null
            && $this->sport_id !== null;
    }

    /**
     * Challenge is ready AND active. Historically named "for daily", but this
     * is the generic playability check used by dailies, packs and tournaments.
     * Pool and daily-used rules are layered on top by the callers.
     */
    public function isReadyForDaily(): bool
    {
        return $this->isReady() && $this->status === 'active';
    }

    /** Ready, active, in a daily-capable pool. Does NOT check daily-used. */
    public function isDailyEligible(): bool
    {
        return $this->isReadyForDaily() && $this->isInDailyPool();
    }

    /** Ready, active, in a tournament-capable pool, never used as a daily. */
    public function isTournamentEligible(): bool
    {
        return $this->isReadyForDaily() && $this->isInTournamentPool() && !$this->isDailyUsed();
    }

    /**
     * SQL-only approximation of isTournamentEligible() for list filtering.
     * Readiness = hidden image + ball position present (title/sport are NOT NULL columns).
     */
    public function scopeTournamentEligibleStrict(Builder $query): Builder
    {
        return $query->tournamentEligible()
            ->whereNotNull('hidden_image_path')
            ->whereNotNull('ball_x_ratio')
            ->whereNotNull('ball_y_ratio');
    }

    public function scopeTournamentBlocked(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', '!=', 'active')
            ->orWhereNotIn('usage_pool', self::TOURNAMENT_POOLS)
            ->orWhereHas('dailyChallenges')
            ->orWhereNull('hidden_image_path')
            ->orWhereNull('ball_x_ratio')
            ->orWhereNull('ball_y_ratio'));
    }

    /**
     * Admin-facing tournament eligibility, most important reason first.
     *
     * @return array{label: string, class: string, eligible: bool}
     */
    public function tournamentEligibility(): array
    {
        if ($this->isDailyUsed()) {
            return ['label' => 'Blocked: used as Daily', 'class' => 'bg-danger', 'eligible' => false];
        }
        if ($this->usage_pool === self::POOL_DAILY) {
            return ['label' => 'Daily only', 'class' => 'bg-pool-daily', 'eligible' => false];
        }
        if ($this->usage_pool === self::POOL_PACK) {
            return ['label' => 'Pack only', 'class' => 'bg-pool-pack', 'eligible' => false];
        }
        if ($this->status !== 'active') {
            return ['label' => 'Blocked: not active', 'class' => 'bg-secondary', 'eligible' => false];
        }
        if (!$this->isReady()) {
            return ['label' => 'Blocked: incomplete', 'class' => 'bg-warning text-dark', 'eligible' => false];
        }
        return ['label' => 'Tournament eligible', 'class' => 'bg-success', 'eligible' => true];
    }

    /** True if the challenge is one of the shipped demo placeholders. */
    public function isDemoContent(): bool
    {
        static $demoTitles = ['Corner Kick', 'Center Field', 'Penalty Spot', 'Crowd Scene', 'Goal Line', 'Kick Off'];
        return in_array($this->title, $demoTitles, true);
    }
}
