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
            'email_verified' => $this->when($isSelf, fn () => $this->hasVerifiedEmail()),
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
