<?php

namespace App\Http\Controllers\ClubPortal;

use App\Enums\ClubRole;
use App\Exceptions\ClubDomainException;
use App\Http\Requests\Clubs\Portal\PortalInviteCodeActionRequest;
use App\Http\Requests\Clubs\Portal\PortalStoreInviteCodeRequest;
use App\Models\Club;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Services\ClubPortal\ClubPortalMemberRules;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Http\Request;

/**
 * Portal Invite codes: list, create, revoke, regenerate, copy, printable QR
 * sheet. Admins handle member codes; admin-granting codes are owner-only
 * (create, regenerate, reveal/copy/print) — an admin can still revoke one,
 * which only ever removes access. Full codes are rendered on these pages
 * only, never logged/flashed/audited in full. {inviteCode} is scoped to {club}.
 */
class InviteCodeController extends PortalController
{
    public const PER_PAGE = 25;

    public function __construct(private ClubInviteCodeService $codes) {}

    /** Viewable on a suspended club too (read-only); writes need manageCodes = active club. */
    public function index(Request $request, Club $club)
    {
        $actor = $this->actorMember($request);

        return view('club-portal.codes', [
            'club'        => $club,
            'codes'       => $club->inviteCodes()->with('createdBy:id,username')->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE),
            'activeCodes' => $this->codes->activeCount($club),
            'codeLimit'   => (int) config('ballspot.clubs.limits.max_active_codes', 20),
            'isOwner'     => $actor->role === ClubRole::Owner,
        ]);
    }

    public function store(PortalStoreInviteCodeRequest $request, Club $club)
    {
        $code = $this->codes->create($club, $request->options(), $request->user(), self::ACTOR);
        $this->logPortal($request, 'code_created', ['club_id' => $club->id, 'code_id' => $code->id]);

        return redirect()->route('club-portal.codes.index', $club)
            ->with('success', __('club_portal.codes.flash.created', ['code' => $code->masked()]))
            ->with('highlight_code_id', $code->id);
    }

    public function revoke(PortalInviteCodeActionRequest $request, Club $club, ClubInviteCode $inviteCode)
    {
        $this->codes->revoke($inviteCode, $request->user(), self::ACTOR);
        $this->logPortal($request, 'code_revoked', ['club_id' => $club->id, 'code_id' => $inviteCode->id]);

        return back()->with('success', __('club_portal.codes.flash.revoked', ['code' => $inviteCode->masked()]));
    }

    public function regenerate(PortalInviteCodeActionRequest $request, Club $club, ClubInviteCode $inviteCode)
    {
        $this->assertCanHandle($this->actorMember($request), $inviteCode);

        $new = $this->codes->regenerate($inviteCode, $request->user(), self::ACTOR);
        $this->logPortal($request, 'code_regenerated', ['club_id' => $club->id, 'code_id' => $new->id, 'replaced_code_id' => $inviteCode->id]);

        return redirect()->route('club-portal.codes.index', $club)
            ->with('success', __('club_portal.codes.flash.regenerated', ['old' => $inviteCode->masked(), 'new' => $new->masked()]))
            ->with('highlight_code_id', $new->id);
    }

    /** Printable QR sheet (payload `ballpicker:club:CODE`, read by the app's scanner). */
    public function print(Request $request, Club $club, ClubInviteCode $inviteCode)
    {
        if (!self::canHandle($this->actorMember($request), $inviteCode)) {
            abort(403);
        }

        return response()
            ->view('club-portal.codes-print', [
                'club'      => $club,
                'code'      => $inviteCode,
                'formatted' => $inviteCode->formatted(),
                'payload'   => 'ballpicker:club:' . $inviteCode->code,
                'copies'    => 6,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Admin-granting codes: owners only (ClubPortalMemberRules, shared with the club-admin API). */
    public static function canHandle(ClubMember $actor, ClubInviteCode $code): bool
    {
        return ClubPortalMemberRules::canHandleCode($actor, $code);
    }

    private function assertCanHandle(ClubMember $actor, ClubInviteCode $code): void
    {
        if (!self::canHandle($actor, $code)) {
            throw ClubDomainException::forbidden();
        }
    }
}
