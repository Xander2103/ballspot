<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value gameplay settings editable from the admin panel.
 *
 * Each known key has a config default; a DB row overrides it. Values are
 * stored as strings and cast on read.
 */
class GameplaySetting extends Model
{
    public const TOURNAMENT_CHALLENGE_COOLDOWN_DAYS = 'tournament_challenge_cooldown_days';

    public const COOLDOWN_MIN_DAYS = 0;
    public const COOLDOWN_MAX_DAYS = 365;

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    public static function getInt(string $key, int $default): int
    {
        $row = static::query()->find($key);

        return $row === null ? $default : (int) $row->value;
    }

    public static function put(string $key, string|int $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /**
     * Days within which a photo a tournament member has already guessed is
     * avoided for new tournaments. 0 disables the avoidance. Default: config
     * ballspot.tournaments.challenge_cooldown_days (90).
     */
    public static function tournamentChallengeCooldownDays(): int
    {
        $default = (int) config('ballspot.tournaments.challenge_cooldown_days', 90);
        $days    = static::getInt(self::TOURNAMENT_CHALLENGE_COOLDOWN_DAYS, $default);

        return max(self::COOLDOWN_MIN_DAYS, min(self::COOLDOWN_MAX_DAYS, $days));
    }
}
