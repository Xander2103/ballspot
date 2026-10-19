<?php

namespace App\Support;

use App\Models\Challenge;
use App\Services\Clubs\ClubMediaService;

/**
 * The one place that turns a challenge image path into a URL (Clubs G1).
 *
 *  - BallPicker challenges (club_id NULL) live on the public disk:
 *    asset('storage/'.$path) — byte-identical to every call site it replaced.
 *  - Club challenges live on the private club_private disk: a temporary
 *    signed `clubs.media` URL. Issue these only to callers allowed to see the
 *    image (members; the reveal only after the guess).
 *
 * Both return null when the challenge has no image for that slot.
 */
final class ChallengeImageUrl
{
    /** The ball-removed image shown while guessing. */
    public static function hidden(Challenge $challenge): ?string
    {
        return self::url($challenge, $challenge->hidden_image_path);
    }

    /** The original (reveal) image — only after the guess is scored. */
    public static function original(Challenge $challenge): ?string
    {
        return self::url($challenge, $challenge->original_image_path);
    }

    private static function url(Challenge $challenge, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if ($challenge->club_id === null) {
            return asset('storage/' . $path);
        }

        return app(ClubMediaService::class)->signedUrl($path);
    }
}
