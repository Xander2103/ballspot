<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Http\Requests\Clubs\Admin\StoreClubInviteCodeRequest;
use App\Models\Club;
use App\Models\ClubInviteCode;
use App\Services\Clubs\ClubInviteCodeService;
use Illuminate\Http\Request;

/**
 * Club detail → Invite codes (plan §4.4, §7.2): create, revoke, regenerate,
 * printable QR sheet. Full codes are only rendered on these admin pages —
 * never logged, flashed or audited in full (ClubInviteCode::masked()).
 * {inviteCode} is scoped to {club}.
 */
class ClubInviteCodeController extends AdminClubController
{
    public function __construct(private ClubInviteCodeService $codes) {}

    /** GET …/codes → the Invite codes tab. */
    public function index(Club $club)
    {
        $this->authorize('admin.manage', $club);

        return redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'codes']);
    }

    public function store(StoreClubInviteCodeRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $code = $this->codes->create($club, $request->options(), $request->user(), self::ACTOR);
        $this->logAdmin($request, 'code_created', ['club_id' => $club->id, 'code_id' => $code->id]);

        return redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'codes'])
            ->with('success', "Invite code {$code->masked()} created.")
            ->with('highlight_code_id', $code->id);
    }

    public function revoke(Request $request, Club $club, ClubInviteCode $inviteCode)
    {
        $this->authorize('admin.manage', $club);

        $this->codes->revoke($inviteCode, $request->user(), self::ACTOR);
        $this->logAdmin($request, 'code_revoked', ['club_id' => $club->id, 'code_id' => $inviteCode->id]);

        return back()->with('success', "Invite code {$inviteCode->masked()} revoked. It can no longer be used to join.");
    }

    public function regenerate(Request $request, Club $club, ClubInviteCode $inviteCode)
    {
        $this->authorize('admin.manage', $club);

        $new = $this->codes->regenerate($inviteCode, $request->user(), self::ACTOR);
        $this->logAdmin($request, 'code_regenerated', ['club_id' => $club->id, 'code_id' => $new->id, 'replaced_code_id' => $inviteCode->id]);

        return redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'codes'])
            ->with('success', "Code {$inviteCode->masked()} replaced by {$new->masked()} (same label, limits and role).")
            ->with('highlight_code_id', $new->id);
    }

    /** Printable QR sheet (QR payload `ballpicker:club:CODE`, read by the app's scanner). */
    public function print(Club $club, ClubInviteCode $inviteCode)
    {
        $this->authorize('admin.manage', $club);

        return response()
            ->view('admin.clubs.codes.print', [
                'club'      => $club,
                'code'      => $inviteCode,
                'formatted' => $inviteCode->formatted(),
                'payload'   => 'ballpicker:club:' . $inviteCode->code,
                'copies'    => 6,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
