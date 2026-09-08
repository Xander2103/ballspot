<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        $isSelf = $request->user()?->id === $this->id;

        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'username'       => $this->username,
            'email'          => $this->when($isSelf, $this->email),
            // When verification is switched off, every account counts as
            // verified — the app must never route a user to a code screen for
            // a code that will not be sent.
            'email_verified' => $this->when($isSelf, fn () => $this->hasVerifiedEmail()
                || !config('ballspot.auth.require_email_verification', true)),
            // Preferences — only meaningful for the authenticated user themselves.
            'selected_theme' => $this->when($isSelf, $this->selected_theme),
            // nl|en|fr|de|es — stored per user, used as the notification locale.
            'preferred_language' => $this->when($isSelf, fn () => $this->preferredLocale()),
            // Optional email login code. Default false; toggled via /me/preferences.
            'two_factor_enabled' => $this->when($isSelf, fn () => (bool) $this->two_factor_enabled),
            'avatar_url'     => $this->avatarUrl(),
            'preferred_sport' => $this->when(
                $isSelf,
                fn () => $this->preferredSport
                    ? new SportResource($this->preferredSport)
                    : null
            ),
        ];
    }
}
