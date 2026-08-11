<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvatarUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AvatarController extends Controller
{
    // POST /api/me/avatar
    public function store(AvatarUploadRequest $request): JsonResponse
    {
        $user = $request->user();
        $disk = config('ballspot.avatar.disk');
        $dir  = config('ballspot.avatar.directory');

        $oldPath = $user->avatar_path;

        // Re-encode through GD to strip ALL embedded metadata (notably EXIF GPS
        // coordinates — avatars are served publicly, so a phone photo could
        // otherwise leak the uploader's home location). Falls back to a raw
        // store only when GD cannot decode the (already image-validated) file.
        // Randomized name — never trusts the client filename.
        $path = $this->storeSanitized($request->file('avatar'), $disk, $dir)
            ?? $request->file('avatar')->store($dir, $disk);

        $user->avatar_path = $path;
        $user->save();

        // Best-effort cleanup of the previous avatar. Only ever deletes a file
        // we ourselves stored under the avatars/ directory — never uploaded
        // challenge images or anything outside this user's own avatar folder.
        if ($oldPath && $oldPath !== $path && str_starts_with($oldPath, $dir . '/')) {
            Storage::disk($disk)->delete($oldPath);
        }

        return response()->json([
            'avatar_url' => $user->avatarUrl(),
        ]);
    }

    // DELETE /api/me/avatar
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $disk = config('ballspot.avatar.disk');
        $dir  = config('ballspot.avatar.directory');

        $oldPath = $user->avatar_path;
        $user->avatar_path = null;
        $user->save();

        if ($oldPath && str_starts_with($oldPath, $dir . '/')) {
            Storage::disk($disk)->delete($oldPath);
        }

        return response()->json(['avatar_url' => null]);
    }

    /**
     * Decode the uploaded image with GD and re-encode it, discarding every
     * metadata segment (EXIF, GPS, colour profiles, comments). Returns the
     * stored path, or null if GD is unavailable or cannot decode the image so
     * the caller can fall back to a plain store.
     */
    private function storeSanitized(UploadedFile $file, string $disk, string $dir): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false) {
            return null;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        $ext = match (strtolower((string) $file->guessExtension())) {
            'png'  => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };

        ob_start();
        $ok = match ($ext) {
            'png'  => function_exists('imagepng')
                && (imagealphablending($image, false) || true)
                && (imagesavealpha($image, true) || true)
                && imagepng($image),
            'webp' => function_exists('imagewebp') && imagewebp($image),
            default => function_exists('imagejpeg') && imagejpeg($image, null, 90),
        };
        $binary = ob_get_clean();
        imagedestroy($image);

        if (! $ok || $binary === false || $binary === '') {
            return null;
        }

        $path = $dir . '/' . Str::random(40) . '.' . $ext;
        Storage::disk($disk)->put($path, $binary);

        return $path;
    }
}
