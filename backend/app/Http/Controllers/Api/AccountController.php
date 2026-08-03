<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\FriendRequest;
use App\Models\Friendship;
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

        // Social graph. The user row is anonymized rather than deleted, so the
        // FK cascade on these tables never fires — they must be cleared here or
        // a "Deleted User" lingers in other people's friends lists forever and
        // their pending requests stay actionable.
        Friendship::where('user_id', $id)->orWhere('friend_id', $id)->delete();
        FriendRequest::where('requester_id', $id)->orWhere('recipient_id', $id)->delete();

        // Anonymize personal fields — preserves row so foreign key refs stay
        // intact (guesses/XP/finishes render as "Deleted User").
        $user->update([
            'name'        => 'Deleted User',
            'email'       => "deleted-{$id}@ballspot.deleted",
            'username'    => "deleted-{$id}",
            'password'    => Hash::make(Str::random(32)),
            'avatar_path' => null,
        ]);

        // Set outside update(): friend_code is deliberately not fillable, so a
        // mass assignment would silently drop it. Nulling it stops the code
        // resolving — a deleted account must not stay addable by anyone still
        // holding it.
        $user->friend_code = null;
        $user->save();

        return response()->json(['message' => 'Your account has been deleted.']);
    }
}
