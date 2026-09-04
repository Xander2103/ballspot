<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\LoginVerificationCode;
use App\Models\NotificationSetting;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Account deletion = anonymization in place.
 *
 * The users row is kept (gameplay history, XP and finishes stay referentially
 * intact as "Deleted User") but every personal identifier is rewritten, every
 * session/token revoked and every user-owned side table cleared. Because the
 * row is never deleted, ON DELETE CASCADE never fires — each user-referencing
 * table must be torn down here explicitly (see AccountDeletionTest).
 *
 * Everything runs in one transaction: either the account is fully anonymized
 * or nothing changed and the caller reports a retryable failure. The avatar
 * file is removed after the commit (a file system is not transactional; an
 * orphaned file is a smaller wrong than a half-anonymized account).
 */
class AccountDeletionService
{
    public function delete(User $user): void
    {
        $id         = $user->id;
        $avatarPath = $user->avatar_path;

        DB::transaction(function () use ($user, $id) {
            // Revoke all API tokens first so the current token is immediately invalid.
            $user->tokens()->delete();

            // Device/notification data and pending verification codes: a
            // deleted account must not keep receiving pushes, and the code
            // tables hold email/IP/user-agent we no longer need.
            PushToken::where('user_id', $id)->delete();
            NotificationSetting::where('user_id', $id)->delete();
            EmailVerificationCode::where('user_id', $id)->delete();
            LoginVerificationCode::where('user_id', $id)->delete();

            // Social graph — cascades never fire (see class doc).
            Friendship::where('user_id', $id)->orWhere('friend_id', $id)->delete();
            FriendRequest::where('requester_id', $id)->orWhere('recipient_id', $id)->delete();

            // Anonymize personal fields. The deleted-{id} identifiers are unique
            // per row, so the original email/username become free to register
            // again immediately.
            $user->update([
                'name'     => 'Deleted User',
                'email'    => "deleted-{$id}@ballspot.deleted",
                'username' => "deleted-{$id}",
                'password' => Hash::make(Str::random(32)),
            ]);

            // Set outside update(): these are deliberately not fillable, so a
            // mass assignment would silently drop them.
            //   avatar_path — nulled so the row no longer references the file.
            //   friend_code — nulled so the code stops resolving.
            //   is_admin — an admin who deletes their account loses panel access.
            //   email_verified_at — the anonymized address was never verified.
            //   anonymized_at — canonical "this account was deleted" marker.
            $user->avatar_path       = null;
            $user->friend_code       = null;
            $user->is_admin          = false;
            $user->email_verified_at = null;
            $user->anonymized_at     = now();
            $user->save();

            // API tokens are revoked above, but the admin panel runs on database
            // sessions, which survive independently of Sanctum.
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $id)->delete();
            }
        });

        // The avatar must not stay publicly reachable after the account is gone.
        if ($avatarPath) {
            try {
                Storage::disk('public')->delete($avatarPath);
            } catch (\Throwable) {
                // Best effort — the DB no longer references it either way.
            }
        }
    }
}
