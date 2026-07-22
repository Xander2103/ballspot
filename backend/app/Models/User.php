<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
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

    protected $hidden = ['password', 'remember_token'];

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

    /** Public URL for the user's avatar, or null when none is set. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/' . $this->avatar_path) : null;
    }
}
