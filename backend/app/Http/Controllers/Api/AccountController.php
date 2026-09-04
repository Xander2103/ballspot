<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use App\Support\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public const DELETE_FAILED_MESSAGE = 'We could not delete your account right now. Please try again in a moment or contact support.';

    /**
     * DELETE /api/account
     *
     * Anonymizes the account (see AccountDeletionService). Always answers with
     * JSON the app can act on: `deleted: true` + a message on success, or a
     * friendly, retryable 500 on failure — never a raw exception message. The
     * failure is logged as `account.delete_failed` (user id + exception class,
     * no personal data) so it shows up on /admin/diagnostics.
     */
    public function delete(Request $request, AccountDeletionService $deletion): JsonResponse
    {
        $user = $request->user();
        $id   = $user->id;

        try {
            $deletion->delete($user);
        } catch (\Throwable $e) {
            AppLog::error('account.delete_failed', [
                'user_id'   => $id,
                'exception' => class_basename($e),
            ]);
            report($e);

            return response()->json([
                'deleted' => false,
                'message' => self::DELETE_FAILED_MESSAGE,
            ], 500);
        }

        AppLog::event('account.deleted', ['user_id' => $id]);
        // Kept for existing dashboards/greps; same event, historical name.
        AppLog::event('account.anonymized', ['user_id' => $id]);

        return response()->json([
            'deleted' => true,
            'message' => 'Your account has been deleted.',
        ]);
    }
}
