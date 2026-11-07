<?php
namespace App\Models;
use App\Services\ImageVariantService;
use App\Support\AppLog;
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

    /**
     * What a guess was scored against and what its result shows. A guess stores
     * only the player's own position and score, so once anyone has played these
     * can never change: old results and prize rankings would stop matching.
     * Title, category, tags, difficulty, status and pool stay editable.
     */
    public const LOCKED_ONCE_PLAYED = ['sport_id', 'hidden_image_path', 'original_image_path', 'ball_x_ratio', 'ball_y_ratio'];

    protected $fillable = [
        'sport_id', 'challenge_category_id',
        'title', 'hidden_image_path', 'original_image_path',
        'ball_x_ratio', 'ball_y_ratio', 'difficulty', 'status', 'usage_pool',
    ];

    protected $attributes = [
        'usage_pool' => self::POOL_GENERAL,
    ];

    /**
     * Display-copy metadata (ImageVariantService). Never serialised: it holds
     * the path of the REVEAL copy, which must not leak before a guess.
     */
    protected $hidden = ['image_variants'];

    protected function casts(): array
    {
        return [
            'ball_x_ratio' => 'float',
            'ball_y_ratio' => 'float',
            'image_variants' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Last line of defence: the admin form refuses this earlier with a clear message.
        static::updating(function (Challenge $challenge) {
            if ($challenge->isDirty(self::LOCKED_ONCE_PLAYED) && $challenge->hasBeenPlayed()) {
                throw new \LogicException("Challenge {$challenge->id} has been played; its image, ball position and sport are locked.");
            }
        });

        // A new or replaced photo gets its display copy straight away. Never
        // fails the save: without a copy the original is served, as before.
        static::created(fn (Challenge $c) => $c->refreshImageVariantsAfterUpload());
        static::updated(function (Challenge $c) {
            if ($c->wasChanged(['hidden_image_path', 'original_image_path'])) {
                $c->refreshImageVariantsAfterUpload();
            }
        });
        static::deleted(function (Challenge $c) {
            foreach ((array) $c->image_variants as $meta) {
                $c->deleteVariantUnlessShared(is_array($meta) ? $meta : null);
            }
        });
    }

    // ------------------------------------------------------------------
    // Images (display copies, v1.10)
    // ------------------------------------------------------------------

    /** Image the player guesses on (display copy when there is one). */
    public function hiddenImageUrl(): ?string
    {
        return self::storageUrl($this->hidden_image_path, $this->displayVariant('hidden'));
    }

    /** Reveal image. SECURITY: only ever put in a payload AFTER the guess is scored. */
    public function revealImageUrl(): ?string
    {
        return self::storageUrl($this->original_image_path, $this->displayVariant('original'));
    }

    /**
     * hidden_image_url (+ its pixel size when known, so the app can reserve
     * the exact aspect before the download) for pre-guess payloads.
     */
    public function hiddenImagePayload(): array
    {
        $size = $this->hiddenImageSize();

        return [
            'hidden_image_url'    => $this->hiddenImageUrl(),
            'hidden_image_width'  => $size['width'] ?? null,
            'hidden_image_height' => $size['height'] ?? null,
        ];
    }

    /** SECURITY: result payloads only (after the guess is scored). */
    public function revealImagePayload(): array
    {
        $size = $this->revealImageSize();

        return [
            'reveal_image_url'    => $this->revealImageUrl(),
            'reveal_image_width'  => $size['width'] ?? null,
            'reveal_image_height' => $size['height'] ?? null,
        ];
    }

    /** Pixel size of what hiddenImageUrl() serves, when known. @return array{width:int,height:int}|null */
    public function hiddenImageSize(): ?array
    {
        return self::sizeOf($this->displayVariant('hidden'));
    }

    /** @return array{width:int,height:int}|null */
    public function revealImageSize(): ?array
    {
        return self::sizeOf($this->displayVariant('original'));
    }

    /**
     * The stored variant for 'hidden' or 'original', only while it still
     * belongs to the current source file and serving is switched on.
     */
    public function displayVariant(string $kind): ?array
    {
        if (! config('ballspot.images.serve_variants', true)) {
            return null;
        }
        $source = $kind === 'hidden' ? $this->hidden_image_path : $this->original_image_path;
        $meta = ((array) $this->image_variants)[$kind] ?? null;

        return self::variantMatches($meta, $source) ? $meta : null;
    }

    /**
     * (Re)build the display copies whose source changed (or all with $force).
     * Writes only `image_variants`, without events or timestamps, so it is
     * safe on played (locked) challenges. Returns kind => created|original|kept|failed|none.
     *
     * @return array<string,string>
     */
    public function refreshImageVariants(bool $force = false): array
    {
        $service = app(ImageVariantService::class);
        $maxEdge = (int) config('ballspot.images.challenge_max_edge', 2048);
        $old = (array) $this->image_variants;
        $new = [];
        $report = [];

        foreach (['hidden' => $this->hidden_image_path, 'original' => $this->original_image_path] as $kind => $source) {
            $current = $old[$kind] ?? null;
            if (! $source) {
                $this->deleteVariantUnlessShared(is_array($current) ? $current : null);
                $report[$kind] = 'none';
                continue;
            }
            if (! $force && self::variantMatches($current, $source, $maxEdge)) {
                $new[$kind] = $current;
                $report[$kind] = 'kept';
                continue;
            }
            $meta = $service->generate($source, dirname($source) . '/display', $maxEdge);
            if (is_array($current) && ($current['path'] ?? null) !== ($meta['path'] ?? null)) {
                $this->deleteVariantUnlessShared($current);
            }
            if ($meta === null) {
                $report[$kind] = 'failed';
                continue;
            }
            $new[$kind] = $meta;
            $report[$kind] = $meta['path'] ? 'created' : 'original';
        }

        $value = $new === [] ? null : $new;
        static::whereKey($this->getKey())->toBase()->update(['image_variants' => $value === null ? null : json_encode($value)]);
        $this->setAttribute('image_variants', $value);
        $this->syncOriginalAttribute('image_variants');

        return $report;
    }

    /**
     * Copies are named after their source file, so two challenges pointing at
     * the same upload share one copy: never delete it while another challenge
     * still uses that source.
     */
    private function deleteVariantUnlessShared(?array $meta): void
    {
        $source = $meta['source'] ?? null;
        if (! is_array($meta) || ! is_string($source) || $source === '') {
            return;
        }
        $shared = static::query()
            ->whereKeyNot($this->getKey())
            ->where(fn ($q) => $q->where('hidden_image_path', $source)->orWhere('original_image_path', $source))
            ->exists();
        if (! $shared) {
            app(ImageVariantService::class)->deleteVariant($meta);
        }
    }

    private function refreshImageVariantsAfterUpload(): void
    {
        if (! config('ballspot.images.generate_on_upload', true)) {
            return;
        }
        try {
            $this->refreshImageVariants();
        } catch (\Throwable $e) {
            AppLog::warn('images.variant_failed', ['challenge_id' => $this->id, 'error' => class_basename($e)]);
        }
    }

    public static function variantMatches(mixed $meta, ?string $source, ?int $maxEdge = null): bool
    {
        return is_array($meta)
            && $source
            && ($meta['source'] ?? null) === $source
            && (int) ($meta['v'] ?? 0) === ImageVariantService::VERSION
            && (int) ($meta['width'] ?? 0) > 0
            && (int) ($meta['height'] ?? 0) > 0
            && ($maxEdge === null || (int) ($meta['max_edge'] ?? 0) === $maxEdge);
    }

    public static function storageUrl(?string $source, ?array $variant): ?string
    {
        if (! $source) {
            return null;
        }
        $path = is_array($variant) && ! empty($variant['path']) ? $variant['path'] : $source;

        return asset('storage/' . $path);
    }

    private static function sizeOf(?array $variant): ?array
    {
        return $variant ? ['width' => (int) $variant['width'], 'height' => (int) $variant['height']] : null;
    }

    /** True once any player has a daily, tournament or pack guess on this challenge. */
    public function hasBeenPlayed(): bool
    {
        return DailyChallengeGuess::whereIn('daily_challenge_id', DailyChallenge::where('challenge_id', $this->id)->select('id'))->exists()
            || Guess::whereIn('league_round_id', LeagueRound::where('challenge_id', $this->id)->select('id'))->exists()
            || PackAttemptGuess::where('challenge_id', $this->id)->exists();
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
     * SQL-only approximation of isReady(): hidden image + ball position present
     * (title/sport are NOT NULL columns). Used by list filters and diagnostics
     * so counts never load every row into PHP.
     */
    public function scopeReadySql(Builder $query): Builder
    {
        return $query->whereNotNull('hidden_image_path')
            ->where('hidden_image_path', '!=', '')
            ->whereNotNull('ball_x_ratio')
            ->whereNotNull('ball_y_ratio');
    }

    /**
     * SQL-only approximation of isTournamentEligible() for list filtering.
     */
    public function scopeTournamentEligibleStrict(Builder $query): Builder
    {
        return $query->tournamentEligible()->readySql();
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
