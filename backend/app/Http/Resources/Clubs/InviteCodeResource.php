<?php

namespace App\Http\Resources\Clubs;

use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Services\ClubPortal\ClubPortalMemberRules;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * InviteCode (contracts §9, club-admin API). The full code and its QR
 * payload are only returned to a caller who may handle the code
 * (ClubPortalMemberRules::canHandleCode — admin-granting codes are
 * owner-only, as on the portal); anyone else gets the masked code
 * `ABCD-••••` and `qr_payload: null`.
 *
 * @property ClubInviteCode $resource
 */
class InviteCodeResource extends JsonResource
{
    public function __construct(ClubInviteCode $resource, private ?ClubMember $viewer = null)
    {
        parent::__construct($resource);
    }

    public static function for(ClubInviteCode $code, ClubMember $viewer): self
    {
        return new self($code, $viewer);
    }

    public function toArray($request): array
    {
        $c      = $this->resource;
        $reveal = $this->viewer !== null && ClubPortalMemberRules::canHandleCode($this->viewer, $c);

        return [
            'id'          => (int) $c->id,
            'code'        => $reveal ? $c->formatted() : $c->masked(),
            'qr_payload'  => $reveal ? 'ballpicker:club:' . $c->code : null,
            'label'       => $c->label,
            'grants_role' => $c->grants_role->value,
            'uses_count'  => (int) $c->uses_count,
            'max_uses'    => $c->max_uses === null ? null : (int) $c->max_uses,
            'expires_at'  => $c->expires_at?->toISOString(),
            'revoked_at'  => $c->revoked_at?->toISOString(),
            'is_usable'   => $c->isUsable(),
            'created_at'  => $c->created_at?->toISOString(),
        ];
    }
}
