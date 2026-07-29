<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\LoginVerificationCode;
use App\Models\NotificationSetting;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function delete(Request $request): JsonResponse
    {
        $user = $request->user();
        $id   = $user->id;

        // Revoke all tokens first so the current token is immediately invalid
        $user->tokens()->delete();

        // Remove the avatar file — it must not stay publicly reachable after
        // the account is gone.
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        // Remove device/notification data and pending verification codes:
        // a deleted account must not keep receiving pushes, and the code
        // tables hold email/IP/user-agent we no longer need.
        PushToken::where('user_id', $id)->delete();
        NotificationSetting::where('user_id', $id)->delete();
        EmailVerificationCode::where('user_id', $id)->delete();
        LoginVerificationCode::where('user_id', $id)->delete();

        // Anonymize personal fields — preserves row so foreign key refs stay
        // intact (guesses/XP/finishes render as "Deleted User").
        $user->update([
            'name'        => 'Deleted User',
            'email'       => "deleted-{$id}@ballspot.deleted",
            'username'    => "deleted-{$id}",
            'password'    => Hash::make(Str::random(32)),
            'avatar_path' => null,
        ]);

        return response()->json(['message' => 'Your account has been deleted.']);
    }
}
