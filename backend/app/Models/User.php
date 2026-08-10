<?php
namespace App\Models;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'password',
        'preferred_sport_id', 'selected_theme', 'avatar_path',
    ];

    // New accounts start on Pitch Green. Model-level (not a schema change) so
    // existing rows — including users who deliberately chose another theme —
    // are never rewritten.
    protected $attributes = [
        'selected_theme' => 'pitch_green',
    ];

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
