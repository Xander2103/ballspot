<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Exceptions\ClubDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\CursorPageRequest;
use App\Http\Requests\Clubs\Api\RevokeInviteCodeRequest;
use App\Http\Requests\Clubs\Api\StoreInviteCodeRequest;
use App\Http\Resources\Clubs\InviteCodeResource;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubInviteCode;
use App\Services\ClubPortal\ClubPortalMemberRules;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubCursor;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Http\JsonResponse;

/**
 * Club-admin API (Wave F): invite codes. Same rules as the portal: any
 * owner/admin lists, creates member codes and revokes any code; creating an
 * admin-granting code is owner-only (403 club_forbidden), and only callers
 * who may handle a code see it in full. Suspended clubs: list only (423 on
 * writes). Audit actor kind `club_admin` (written by ClubInviteCodeService).
 */
class ClubInviteCodeController extends Controller
{
    public function __construct(private ClubInviteCodeService $codes) {}

    // GET /api/clubs/{club}/invite-codes?cursor=&limit= — usable codes first, then revoked/expired/exhausted, newest first.
    public function index(CursorPageRequest $request, Club $club): JsonResponse
    {
        $actor = ClubAccess::requireManager($club, $request->user());

        $now    = now()->utc()->format('Y-m-d H:i:s');
        $usable = "CASE WHEN club_invite_codes.revoked_at IS NULL"
            . " AND (club_invite_codes.expires_at IS NULL OR club_invite_codes.expires_at > '{$now}')"
            . " AND (club_invite_codes.max_uses IS NULL OR club_invite_codes.uses_count < club_invite_codes.max_uses)"
            . " THEN 0 ELSE 1 END";

        $page = ClubCursor::page(
            ClubInviteCode::query()->where('club_invite_codes.club_id', $club->id)->select('club_invite_codes.*'),
            [[$usable, 'asc'], ['club_invite_codes.id', 'desc']],
            $request->pageCursor(),
            ClubCursor::perPage($request->pageLimit()),
        );

        return response()->json([
            'data' => $page['items']->map(fn (ClubInviteCode $c) => InviteCodeResource::for($c, $actor)->resolve($request))->values(),
            'meta' => [
                'next_cursor'  => $page['next_cursor'],
                'per_page'     => $page['per_page'],
                'active_count' => $this->codes->activeCount($club),
                'limit'        => (int) config('ballspot.clubs.limits.max_active_codes', 20),
            ],
        ]);
    }

    // POST /api/clubs/{club}/invite-codes → 201 { data: InviteCode }
    public function store(StoreInviteCodeRequest $request, Club $club): JsonResponse
    {
        $actor = $request->actor();
        if (!in_array($request->grantsRole()->value, ClubPortalMemberRules::grantableCodeRoles($actor), true)) {
            throw ClubDomainException::forbidden();
        }

        $code = $this->codes->create($club, $request->options(), $request->user(), ClubAuditEvent::ACTOR_CLUB_ADMIN);

        return response()->json(['data' => InviteCodeResource::for($code, $actor)->resolve($request)], 201);
    }

    // POST /api/clubs/{club}/invite-codes/{inviteCode}/revoke → { data: InviteCode } (idempotent)
    public function revoke(RevokeInviteCodeRequest $request, Club $club, ClubInviteCode $inviteCode): JsonResponse
    {
        $this->codes->revoke($inviteCode, $request->user(), ClubAuditEvent::ACTOR_CLUB_ADMIN);

        return response()->json(['data' => InviteCodeResource::for($inviteCode->refresh(), $request->actor())->resolve($request)]);
    }
}
