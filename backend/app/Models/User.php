<?php
namespace App\Models;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail, HasLocalePreference
{
    use HasApiTokens, HasFactory, Notifiable;

    // avatar_path is deliberately NOT fillable: it is set explicitly by
    // AvatarController (never from a mass-assigned payload), so a future
    // endpoint that fills validated input can never let user A point their
    // avatar_path at user B's file and delete it via DELETE /me/avatar.
    protected $fillable = [
        'name', 'username', 'email', 'password',
        'preferred_sport_id', 'selected_theme', 'preferred_language',
    ];
    // two_factor_enabled is deliberately NOT fillable: it is a security
    // setting, set explicitly by PreferenceController from validated input.

    // New accounts start on Pitch Green. Model-level (not a schema change) so
    // existing rows — including users who deliberately chose another theme —
    // are never rewritten. Language/2FA defaults mirror the column defaults so
    // an unsaved model behaves like a stored one.
    protected $attributes = [
        'selected_theme'     => 'pitch_green',
        'preferred_language' => 'en',
        'two_factor_enabled' => false,
    ];

    /**
     * Notification locale (HasLocalePreference): the user's stored language.
     * Every notification/email sent to this user is rendered under that locale
     * (lang/<locale>/emails.php). Values are validated against
     * config('ballspot.languages') on write; unknown values fall back safely.
     */
    public function preferredLocale(): ?string
    {
        $lang = (string) ($this->preferred_language ?: config('ballspot.default_language', 'en'));

        return in_array($lang, (array) config('ballspot.languages', ['en']), true) ? $lang : (string) config('ballspot.default_language', 'en');
    }

    /** Canonical form of an address for storage and lookups: trimmed, lowercase. */
    public static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    /**
     * Case-insensitive account lookup. Registration stores addresses lowercase
     * (RegisterRequest), but older rows and SQLite's case-sensitive UNIQUE
     * index can hold mixed case — a user who typed "Foo@x.com" at sign-up
     * must still be able to log in and reset their password with "foo@x.com".
     */
    public static function findByEmail(?string $email): ?self
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === '') {
            return null;
        }

        return static::query()->whereRaw('LOWER(email) = ?', [$normalized])->first();
    }

    /**
     * Allowed shape of a NEW username: letters and digits in any script plus
     * . _ - (never first). Excludes spaces, @ and invisible characters, so an
     * email address or a look-alike can no longer become a public identity.
     * Existing accounts are never re-validated against this.
     */
    public const USERNAME_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{M}\p{N}._-]*$/u';

    /** deleted-{id} is what AccountDeletionService writes: registering it would break that later delete. */
    public const RESERVED_USERNAME_PATTERN = '/^deleted-\d+$/i';

    /** Canonical stored form of a username: trimmed, NFC, no leading @. Case is kept for display. */
    public static function normalizeUsername(?string $username): string
    {
        $value = trim((string) $username);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        return trim(ltrim($value, '@'));
    }

    /** Case-insensitive "already taken" check, independent of the database collation. */
    public static function usernameIsTaken(string $username): bool
    {
        return static::query()->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->exists();
    }

    /**
     * THE public identity of a player: every API field another player can see
     * goes through here. The real name and email are deliberately not inputs,
     * so they cannot reach a public surface through this path. A missing or
     * email-shaped legacy username falls back to a neutral label.
     */
    public static function publicNameFor(int|string|null $id, ?string $username, bool $anonymized = false): string
    {
        if ($anonymized) {
            return (string) __('messages.player.deleted');
        }

        $value = trim(ltrim(trim((string) $username), '@'));
        if ($value === '' || preg_match('/[^@\s]@[^@\s]/', $value)) {
            return (string) __('messages.player.fallback', ['id' => (int) $id]);
        }

        return $value;
    }

    public function publicName(): string
    {
        return self::publicNameFor($this->id, $this->username, $this->anonymized_at !== null);
    }

    /**
     * The `name` + `username` pair for another player's API payload. Both keys
     * stay because published apps read `name` first (`name || username`), so it
     * must carry the username rather than disappear.
     *
     * @return array{name: string, username: string}
     */
    public function publicIdentity(): array
    {
        $name = $this->publicName();

        return ['name' => $name, 'username' => $name];
    }

    /** Login codes are sent only when the user opted in (or the global force flag is on). */
    public function wantsLoginTwoFactor(): bool
    {
        return (bool) $this->two_factor_enabled || (bool) config('ballspot.auth.force_login_2fa', false);
    }

    /** Use our API-friendly reset notification instead of the default web-route one. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    // is_admin is hidden so a raw User serialization can never advertise
    // which accounts are worth attacking (admin checks are server-side only).
    // friend_code is a share secret and must never leak through a raw
    // serialization of another user, so it is hidden (and never fillable).
    protected $hidden = ['password', 'remember_token', 'is_admin', 'friend_code'];

    /** Unambiguous alphabet — no O/0, I/1, so codes survive being read aloud. */
    private const FRIEND_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->friend_code)) {
                $user->friend_code = static::generateFriendCode();
            }
        });
    }

    /** A fresh 8-character code that is not already taken. */
    public static function generateFriendCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::FRIEND_CODE_ALPHABET[random_int(0, strlen(self::FRIEND_CODE_ALPHABET) - 1)];
            }
        } while (static::where('friend_code', $code)->exists());

        return $code;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'anonymized_at'     => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'two_factor_enabled' => 'boolean',
        ];
    }

    public function leagues(): BelongsToMany
    {
        return $this->belongsToMany(League::class, 'league_members')->withPivot('joined_at', 'hidden_at');
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'user_badges')
            ->withPivot('earned_at', 'context')
            ->withTimestamps();
    }

    /** The sport/category the user chose to play. Null until they pick one. */
    public function preferredSport(): BelongsTo
    {
        return $this->belongsTo(Sport::class, 'preferred_sport_id');
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    public function packAttempts(): HasMany
    {
        return $this->hasMany(PackAttempt::class);
    }

    public function dailyChallengeGuesses(): HasMany
    {
        return $this->hasMany(DailyChallengeGuess::class);
    }

    /**
     * The user's notification settings, creating a defaults row lazily the
     * first time they're read so callers never deal with a missing record.
     */
    public function notificationSettings(): NotificationSetting
    {
        return $this->notificationSetting()->firstOrCreate([], NotificationSetting::defaults());
    }

    /** Public URL for the user's avatar, or null when none is set. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/' . $this->avatar_path) : null;
    }

    /** Directed friendship rows owned by this user. */
    public function friendships(): HasMany
    {
        return $this->hasMany(Friendship::class, 'user_id');
    }

    /** The users this user is friends with. */
    public function friends(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'friendships', 'user_id', 'friend_id')->withTimestamps();
    }

    /** True when `$other` is already a confirmed friend. */
    public function isFriendsWith(User $other): bool
    {
        return Friendship::where('user_id', $this->id)->where('friend_id', $other->id)->exists();
    }
}
