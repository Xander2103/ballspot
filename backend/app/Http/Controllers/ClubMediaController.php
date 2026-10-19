<?php

namespace App\Http\Controllers;

use App\Services\Clubs\ClubMediaService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /media/clubs/{path} (route `clubs.media`, `signed` middleware) —
 * streams one private club image (contracts §3.6).
 *
 * The signature (expiry included) is checked by the middleware before this
 * runs: a missing, tampered or expired signature is a 403. URLs are minted
 * only by ClubMediaService::signedUrl() for callers allowed to see the file.
 * Paths with traversal or unexpected characters and missing files are 404.
 */
class ClubMediaController extends Controller
{
    public function __invoke(string $path, ClubMediaService $media): Response
    {
        if (!ClubMediaService::isSafePath($path)) {
            abort(404);
        }

        $disk = Storage::disk($media->disk());
        if (!$disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
