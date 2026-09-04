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
