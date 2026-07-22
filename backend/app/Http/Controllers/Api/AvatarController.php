<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvatarUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    // POST /api/me/avatar
    public function store(AvatarUploadRequest $request): JsonResponse
    {
        $user = $request->user();
        $disk = config('ballspot.avatar.disk');
        $dir  = config('ballspot.avatar.directory');

        $oldPath = $user->avatar_path;

        // Store under avatars/ with a randomized name (never trusts client name).
        $path = $request->file('avatar')->store($dir, $disk);

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
}
