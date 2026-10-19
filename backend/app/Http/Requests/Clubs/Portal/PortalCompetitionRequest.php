<?php

namespace App\Http\Requests\Clubs\Portal;

use App\Http\Requests\Clubs\Admin\ClubCompetitionRequest;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionPack;

/**
 * Portal competition editor. Same fields and conversion as the central
 * admin editor (ClubCompetitionRequest::competitionData — window in the club
 * timezone → UTC, only submitted keys), but authorized by the club ability
 * `manageCompetitions` and limited to the club's ACTIVE packs (packs already
 * linked to this competition stay selectable). The lock matrix itself is
 * ClubCompetitionRulesGuard, inside ClubCompetitionService.
 */
class PortalCompetitionRequest extends ClubCompetitionRequest
{
    public function authorize(): bool
    {
        $club = $this->route('club');

        return $club instanceof Club && (bool) $this->user()?->can('manageCompetitions', $club);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['pack_ids.*'][] = function (string $attribute, mixed $value, \Closure $fail) {
            if (!in_array((int) $value, $this->selectablePackIds(), true)) {
                $fail(__('club_portal.competitions.pack_not_active'));
            }
        };

        return $rules;
    }

    public function messages(): array
    {
        return [
            'pack_ids.*.exists'        => __('club_portal.competitions.pack_not_active'),
            'max_attempts.required_if' => __('club_portal.competitions.max_attempts_required'),
        ];
    }

    /** @return list<int> */
    public function selectablePackIds(): array
    {
        $club = $this->route('club');
        $competition = $this->route('competition');

        return self::selectablePacksQuery($club, $competition instanceof ClubCompetition ? $competition : null)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function selectablePacksQuery(Club $club, ?ClubCompetition $competition)
    {
        return ChallengePack::query()
            ->where('club_id', $club->id)
            ->where(fn ($q) => $q->where('status', ChallengePack::STATUS_ACTIVE)
                ->when($competition, fn ($qq) => $qq->orWhereIn('id', ClubCompetitionPack::where('club_competition_id', $competition->id)->select('challenge_pack_id'))));
    }
}
