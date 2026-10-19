<?php

namespace App\Http\Requests\Clubs\Api;

use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubMember;
use App\Services\Clubs\ClubAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the club-admin API writes (contracts §9). authorize() runs BEFORE
 * validation, so the uniform answers come first and a probe never learns
 * anything from field errors:
 *   non-member → 404 club_not_found · plain member → 403 club_forbidden ·
 *   suspended club → 423 club_unavailable · policy ability missing → 403.
 * The ability names are the portal's (ClubPolicy); the who-may-touch-whom
 * rules are ClubPortalMemberRules, applied by the controllers.
 */
abstract class ClubAdminRequest extends FormRequest
{
    /** ClubPolicy ability the write needs. */
    protected string $ability = 'manageMembers';

    private ?ClubMember $actorMember = null;

    public function authorize(): bool
    {
        $club = $this->route('club');
        if (!$club instanceof Club) {
            throw ClubDomainException::notFound();
        }

        $this->actorMember = ClubAccess::requireManagerWrite($club, $this->user(), $this->ability);

        return true;
    }

    public function club(): Club
    {
        return $this->route('club');
    }

    /** The caller's active owner/admin membership of {club}. */
    public function actor(): ClubMember
    {
        return $this->actorMember ??= ClubAccess::requireManager($this->club(), $this->user());
    }

    protected function cleanString(string $key): ?string
    {
        $value = $this->input($key);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
