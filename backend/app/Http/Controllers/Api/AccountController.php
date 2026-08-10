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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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

        // Set outside update(): these are deliberately not fillable, so a mass
        // assignment would silently drop them.
        //   friend_code — nulled so the code stops resolving; a deleted account
        //     must not stay addable by anyone still holding it.
        //   is_admin — an admin who deletes their account must not keep panel
        //     access; EnsureIsAdmin only checks auth + this flag.
        //   email_verified_at — the anonymized address was never verified, and
        //     leaving it set would carry verification onto a bogus email.
        //   anonymized_at — canonical "this account was deleted" marker; feeds
        //     the aggregate admin metric and excludes the row from suggestions
        //     and public profiles.
        $user->friend_code       = null;
        $user->is_admin          = false;
        $user->email_verified_at = null;
        $user->anonymized_at     = now();
        $user->save();

        // API tokens are revoked above, but the admin panel runs on database
        // sessions, which survive independently of Sanctum.
        $this->purgeWebSessions($id);

        return response()->json(['message' => 'Your account has been deleted.']);
    }

    /**
     * Drop any database-backed web sessions for the user. No-op when the app
     * is configured with a non-database session driver.
     */
    private function purgeWebSessions(int $userId): void
    {
        if (!Schema::hasTable('sessions')) {
            return;
        }

        DB::table('sessions')->where('user_id', $userId)->delete();
    }
}
