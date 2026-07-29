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

    /** Use our API-friendly reset notification instead of the default web-route one. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    // is_admin is hidden so a raw User serialization can never advertise
    // which accounts are worth attacking (admin checks are server-side only).
    protected $hidden = ['password', 'remember_token', 'is_admin'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function leagues(): BelongsToMany
    {
        return $this->belongsToMany(League::class, 'league_members')->withPivot('joined_at');
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
}
