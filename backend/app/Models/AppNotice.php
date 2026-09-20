<?php

namespace App\Models;

use App\Support\Locale;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A temporary message the admin shows in the app ("Daily login starts
 * tomorrow"). One row per placement; the admin page edits it in place.
 *
 * Public shape (see toPublicArray): only placement, type and ONE resolved
 * message — never the enabled flag, the window or the other languages.
 */
class AppNotice extends Model
{
    public const PLACEMENT_HOME_DAILY_CARD = 'home_daily_card';
    public const PLACEMENTS = [self::PLACEMENT_HOME_DAILY_CARD];

    public const TYPE_INFO    = 'info';
    public const TYPE_WARNING = 'warning';
    public const TYPE_SUCCESS = 'success';
    public const TYPES = [self::TYPE_INFO, self::TYPE_WARNING, self::TYPE_SUCCESS];

    public const MESSAGE_MAX = 300;

    protected $fillable = [
        'placement', 'enabled', 'type',
        'message_nl', 'message_en', 'message_fr', 'message_de', 'message_es',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled'   => 'boolean',
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
        ];
    }

    /** The single row for a placement (unsaved defaults when none exists yet). */
    public static function forPlacement(string $placement): self
    {
        return static::query()->firstOrNew(['placement' => $placement], [
            'enabled' => false,
            'type'    => self::TYPE_INFO,
        ]);
    }

    /** Enabled and inside its optional start/end window at `$now`. */
    public function scopeActiveAt(Builder $query, CarbonInterface $now): Builder
    {
        return $query
            ->where('enabled', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** @return array<string, string> language => non-empty message */
    public function messages(): array
    {
        $out = [];
        foreach (Locale::supported() as $lang) {
            $value = trim((string) ($this->{'message_' . $lang} ?? ''));
            if ($value !== '') {
                $out[$lang] = $value;
            }
        }

        return $out;
    }

    /**
     * The message for `$locale`, falling back to English, then to whichever
     * language the admin did fill in. Null when every language is empty.
     */
    public function messageFor(string $locale): ?string
    {
        $messages = $this->messages();
        if ($messages === []) {
            return null;
        }

        return $messages[$locale] ?? $messages[Locale::default()] ?? $messages['en'] ?? reset($messages);
    }

    /** Public payload, or null when there is nothing to show. */
    public function toPublicArray(string $locale): ?array
    {
        $message = $this->messageFor($locale);
        if ($message === null) {
            return null;
        }

        return [
            'placement' => $this->placement,
            'type'      => in_array($this->type, self::TYPES, true) ? $this->type : self::TYPE_INFO,
            'message'   => $message,
        ];
    }

    /** The notice to show right now for a placement, in `$locale`, or null. */
    public static function activeFor(string $placement, string $locale, ?CarbonInterface $now = null): ?array
    {
        $row = static::query()
            ->where('placement', $placement)
            ->activeAt($now ?? now())
            ->first();

        return $row?->toPublicArray($locale);
    }
}
