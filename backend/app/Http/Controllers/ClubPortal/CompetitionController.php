<?php

namespace App\Http\Controllers\ClubPortal;

use App\Enums\CompetitionPhase;
use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Http\Requests\Clubs\Portal\PortalCompetitionActionRequest;
use App\Http\Requests\Clubs\Portal\PortalCompetitionRequest;
use App\Http\Requests\Clubs\Portal\PortalExtendCompetitionRequest;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionPack;
use App\Services\Clubs\ClubCompetitionRulesGuard;
use App\Services\Clubs\ClubCompetitionService;
use App\Services\Clubs\ClubStandingsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Portal Competitions (club owners/admins): list, create and edit drafts /
 * upcoming competitions from the club's active packs, publish, cancel before
 * the start, extend the end of a running one, and view standings.
 *
 * Everything goes through ClubCompetitionService (audit actorKind
 * club_admin) and its ClubCompetitionRulesGuard. On top of the guard the
 * portal is stricter: once the rules are locked (start time reached) the
 * only change is "extend end" — no name/description edits either. Void,
 * rebuild, finalize and attempt voids stay platform-only (no routes here).
 * {competition} is bound by public_id within the club.
 */
class CompetitionController extends PortalController
{
    public const PER_PAGE = 20;
    public const STANDINGS_PER_PAGE = 50;
    public const PHASES = ['draft', 'upcoming', 'active', 'ended', 'finalized', 'cancelled'];

    public function __construct(
        private ClubCompetitionService $competitions,
        private ClubCompetitionRulesGuard $guard,
        private ClubStandingsService $standings,
    ) {}

    public function index(Request $request, Club $club)
    {
        $phase = in_array($request->query('phase'), self::PHASES, true) ? (string) $request->query('phase') : '';
        $now   = now();

        $query = ClubCompetition::query()->where('club_id', $club->id)->withCount('competitionPacks');
        match ($phase) {
            'upcoming' => $query->upcoming($now),
            'active'   => $query->live($now),
            'ended'    => $query->where('state', CompetitionState::Published->value)->where('ends_at', '<=', $now),
            ''         => $query->where('state', '!=', CompetitionState::Voided->value),
            default    => $query->where('state', $phase),
        };

        return view('club-portal.competitions.index', [
            'club'         => $club,
            'competitions' => $query->orderByDesc('starts_at')->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString(),
            'phase'        => $phase,
            'phases'       => self::PHASES,
            'canManage'    => $request->user()->can('manageCompetitions', $club),
        ]);
    }

    public function create(Request $request, Club $club)
    {
        $this->authorize('manageCompetitions', $club);

        $draft = new ClubCompetition([
            'club_id'            => $club->id,
            'starts_at'          => now()->addDay()->startOfHour(),
            'ends_at'            => now()->addDays(8)->startOfHour(),
            'timezone'           => $club->timezone,
            'replay_mode'        => $club->setting('default_replay_mode'),
            'normal_play_policy' => $club->setting('default_normal_play_policy'),
        ]);

        return view('club-portal.competitions.create', ['club' => $club, 'competition' => $draft] + $this->editorData($club, null));
    }

    public function store(PortalCompetitionRequest $request, Club $club)
    {
        $competition = $this->competitions->create($club, $request->competitionData($club), $request->user(), self::ACTOR);
        $this->logPortal($request, 'competition_created', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return redirect()->route('club-portal.competitions.show', [$club, $competition])
            ->with('success', __('club_portal.competitions.flash.created'));
    }

    public function show(Request $request, Club $club, ClubCompetition $competition)
    {
        $tab  = $request->query('tab') === 'standings' ? 'standings' : 'rules';
        $data = [
            'club'        => $club,
            'competition' => $competition,
            'tab'         => $tab,
            'canManage'   => $request->user()->can('manageCompetitions', $club),
            'canEdit'     => $this->portalEditable($competition),
        ] + $this->editorData($club, $competition);

        if ($tab === 'standings') {
            $ranked = $this->standings->ranked($competition);
            $page   = max(1, (int) $request->query('page', 1));
            $data['standings'] = new LengthAwarePaginator(
                $ranked->forPage($page, self::STANDINGS_PER_PAGE)->values(), $ranked->count(), self::STANDINGS_PER_PAGE, $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        }

        return view('club-portal.competitions.show', $data);
    }

    public function update(PortalCompetitionRequest $request, Club $club, ClubCompetition $competition)
    {
        if (!$this->portalEditable($competition)) {
            throw new ClubDomainException(
                $competition->isLocked() ? ClubDomainException::COMPETITION_RULES_LOCKED : ClubDomainException::COMPETITION_NOT_EDITABLE,
                422,
            );
        }

        $this->competitions->update($competition, $request->competitionData($club, $competition), $request->user(), (int) $request->validated('version'), self::ACTOR);
        $this->logPortal($request, 'competition_updated', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return redirect()->route('club-portal.competitions.show', [$club, $competition])->with('success', __('club_portal.competitions.flash.saved'));
    }

    public function publish(PortalCompetitionActionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->competitions->publish($competition, $request->user(), $request->expectedVersion(), self::ACTOR);
        $this->logPortal($request, 'competition_published', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', __('club_portal.competitions.flash.published'));
    }

    public function cancel(PortalCompetitionActionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->competitions->cancel($competition, $request->user(), $request->validated('reason'), $request->expectedVersion(), self::ACTOR);
        $this->logPortal($request, 'competition_cancelled', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', __('club_portal.competitions.flash.cancelled'));
    }

    public function extend(PortalExtendCompetitionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->competitions->extend($competition, $request->endsAt($competition->timezone), $request->user(), (int) $request->validated('version'), self::ACTOR);
        $this->logPortal($request, 'competition_extended', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', __('club_portal.competitions.flash.extended'));
    }

    // ------------------------------------------------------------------

    /** Portal edits: drafts and published competitions before the rules lock. */
    private function portalEditable(ClubCompetition $competition): bool
    {
        return in_array($competition->state, [CompetitionState::Draft, CompetitionState::Published], true)
            && !$competition->isLocked()
            && $competition->phase() !== CompetitionPhase::Ended;
    }

    private function editorData(Club $club, ?ClubCompetition $competition): array
    {
        $packs = PortalCompetitionRequest::selectablePacksQuery($club, $competition)->with('challenges')->orderBy('name')->get();

        $linked = $competition
            ? ClubCompetitionPack::where('club_competition_id', $competition->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('challenge_pack_id')
            : collect();

        $editable = [];
        foreach (['name', 'description', 'starts_at', 'ends_at', 'replay_mode', 'max_attempts', 'score_rule', 'normal_play_policy', 'pack_ids'] as $field) {
            $editable[$field] = $competition === null || ($this->portalEditable($competition) && $this->guard->isEditable($competition, $field));
        }

        return [
            'packs'      => $packs,
            'linked'     => $linked,
            'inProgress' => $this->competitions->inProgressNormalAttempts($club),
            'editable'   => $editable,
            'maxCap'     => max(2, (int) config('ballspot.clubs.competition.max_attempts_cap', 10)),
            'maxDays'    => (int) config('ballspot.clubs.competition.max_duration_days', 120),
        ];
    }
}
