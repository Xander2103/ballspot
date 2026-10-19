<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Http\Controllers\Controller;
use App\Models\ClubAuditEvent;
use App\Support\AppLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Base of the central-admin club controllers (B2). Every action authorizes
 * through `admin.manage` (contracts §6) even though the `admin` middleware
 * already requires is_admin, so narrower admin roles can hook in later (D21).
 * Domain failures are ClubDomainException → back() with the `error` flash
 * (bootstrap/app.php); audit rows are written by the services.
 */
abstract class AdminClubController extends Controller
{
    use AuthorizesRequests;

    /** Every central-admin action acts as a platform admin in the audit trail. */
    protected const ACTOR = ClubAuditEvent::ACTOR_PLATFORM_ADMIN;

    /** AppLog mirror in the admin.* convention (ids only, never emails or codes). */
    protected function logAdmin(Request $request, string $event, array $context = []): void
    {
        AppLog::event('admin.club_' . $event, $context + ['admin_user_id' => $request->user()?->id]);
    }
}
