<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\CompetitionState;
use App\Http\Requests\Clubs\Admin\ClubCompetitionActionRequest;
use App\Http\Requests\Clubs\Admin\ClubCompetitionRequest;
use App\Http\Requests\Clubs\Admin\ExtendClubCompetitionRequest;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionEntry;
use App\Models\ClubCompetitionPack;
use App\Models\ClubMember;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionRulesGuard;
use App\Services\Clubs\ClubCompetitionService;
use App\Services\Clubs\ClubStandingsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Central admin → club competitions (plan §7.2, §8.4): the cross-club list
 * (admin.club-competitions.index) and the club-scoped editor
 * (admin.clubs.competitions.*): create / edit with lock-aware fields,
 * publish, cancel, extend end, void, rebuild standings (dry-run first),
 * finalize, standings with per-player attempts and attempt void.
 *
 * Rules live in ClubCompetitionService / ClubCompetitionRulesGuard: a locked
 * field that changes is refused there (competition_rules_locked → error
 * flash), never only in the form. Every action writes its audit event in the
 * service, in the same transaction.
 */
class ClubCompetitionController extends AdminClubController
{
    public const PHASES = ['draft', 'upcoming', 'active', 'ended', 'finalized', 'cancelled', 'voided'];
    public const STANDINGS_PER_PAGE = 50;

    public function __construct(
        private ClubCompetitionService $competitions,
        private ClubCompetitionRulesGuard $guard,
        private ClubStandingsService $standings,
    ) {}

    // GET /admin/club-competitions?q=&club=&phase=
    public function index(Request $request)
    {
        Gate::authorize('admin.manage');

        $q     = trim((string) $request->query('q', ''));
        $club  = (string) $request->query('club', '');
        $phase = in_array($request->query('phase'), self::PHASES, true) ? (string) $request->query('phase') : '';
        $now   = now();

        $query = ClubCompetition::query()->with('club:id,name,slug,timezone,status')->withCount('competitionPacks');
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%')->orWhere('public_id', $q)
                ->orWhere('id', ctype_digit($q) ? (int) $q : 0));
        }
        if (ctype_digit($club)) {
            $query->where('club_id', (int) $club);
        }
        match ($phase) {
            'upcoming' => $query->upcoming($now),
            'active'   => $query->live($now),
            'ended'    => $query->where('state', CompetitionState::Published->value)->where('ends_at', '<=', $now),
            ''         => null,
            default    => $query->where('state', $phase),
        };

        return view('admin.clubs.competitions.index', [
            'competitions' => $query->orderByDesc('starts_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters'      => ['q' => $q, 'club' => $club, 'phase' => $phase],
            'isFiltered'   => $q !== '' || $club !== '' || $phase !== '',
            'clubs'        => Club::orderBy('name')->get(['id', 'name']),
            'phases'       => self::PHASES,
            'total'        => ClubCompetition::count(),
        ]);
    }

    // GET /admin/clubs/{club}/competitions → the club's Competitions tab.
    public function clubIndex(Club $club)
    {
        $this->authorize('admin.manage', $club);

        return redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'competitions']);
    }

    public function create(Club $club)
    {
        $this->authorize('admin.manage', $club);

        $draft = new ClubCompetition([
            'club_id'            => $club->id,
            'starts_at'          => now()->addDay()->startOfHour(),
            'ends_at'            => now()->addDays(8)->startOfHour(),
            'timezone'           => $club->timezone,
            'replay_mode'        => (string) ($club->settings['default_replay_mode'] ?? config('ballspot.clubs.competition.default_replay_mode', 'single_attempt')),
            'normal_play_policy' => (string) ($club->settings['default_normal_play_policy'] ?? config('ballspot.clubs.competition.default_normal_play_policy', 'blocked')),
        ]);

        return view('admin.clubs.competitions.create', ['club' => $club, 'competition' => $draft] + $this->editorData($club, null));
    }

    public function store(ClubCompetitionRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $competition = $this->competitions->create($club, $request->competitionData($club), $request->user());
        $this->logAdmin($request, 'competition_created', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return redirect()->route('admin.clubs.competitions.show', [$club, $competition])
            ->with('success', "Competition \"{$competition->name}\" saved as draft. Publish it when the packs are ready.");
    }

    public function show(Request $request, Club $club, ClubCompetition $competition)
    {
        $this->authorize('admin.manage', $club);

        $tab = $request->query('tab') === 'standings' ? 'standings' : 'rules';
        $data = ['club' => $club, 'competition' => $competition, 'tab' => $tab] + $this->editorData($club, $competition);

        if ($tab === 'standings') {
            $data += $this->standingsData($request, $competition);
        }

        return view('admin.clubs.competitions.show', $data);
    }

    public function update(ClubCompetitionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->authorize('admin.manage', $club);

        $this->competitions->update($competition, $request->competitionData($club, $competition), $request->user(), (int) $request->validated('version'));
        $this->logAdmin($request, 'competition_updated', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return redirect()->route('admin.clubs.competitions.show', [$club, $competition])->with('success', 'Competition saved.');
    }

    public function publish(ClubCompetitionActionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->authorize('admin.manage', $club);
        $this->competitions->publish($competition, $request->user(), $request->expectedVersion());
        $this->logAdmin($request, 'competition_published', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', 'Competition published. Members see it now; the rules lock at the start time.');
    }

    public function cancel(ClubCompetitionActionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->authorize('admin.manage', $club);
        $this->competitions->cancel($competition, $request->user(), $request->validated('reason'), $request->expectedVersion());
        $this->logAdmin($request, 'competition_cancelled', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', 'Competition cancelled.');
    }

    public function extend(ExtendClubCompetitionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->authorize('admin.manage', $club);
        $this->competitions->extend($competition, $request->endsAt($competition->timezone), $request->user(), (int) $request->validated('version'));
        $this->logAdmin($request, 'competition_extended', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', 'End time extended.');
    }

    public function void(ClubCompetitionActionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->authorize('admin.manage', $club);
        $this->competitions->void($competition, $request->user(), (string) $request->validated('reason'), $request->expectedVersion());
        $this->logAdmin($request, 'competition_voided', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', 'Competition voided. It is hidden from members\' lists and its club trophies were withdrawn.');
    }

    public function rebuild(ClubCompetitionActionRequest $request, Club $club, ClubCompetition $competition)
    {
        $this->authorize('admin.manage', $club);

        $dryRun = $request->boolean('dry_run');
        $result = $this->competitions->rebuildStandings($competition, $request->user(), $dryRun);
        $count  = count($result['changes']);
        $to     = redirect()->route('admin.clubs.competitions.show', [$club, $competition, 'tab' => 'standings']);

        if ($dryRun) {
            return $to->with('rebuild_preview', ['count' => $count, 'players' => $result['players'], 'changes' => array_slice($result['changes'], 0, 25)]);
        }
        $this->logAdmin($request, 'competition_rebuilt', ['club_id' => $club->id, 'competition_id' => $competition->id, 'changes' => $count]);

        return $to->with('success', "Standings rebuilt: {$count} " . ($count === 1 ? 'difference' : 'differences') . ' corrected'
            . ($competition->fresh()->state === CompetitionState::Finalized ? '; final placements rewritten.' : '.'));
    }

    public function finalize(ClubCompetitionActionRequest $request, Club $club, ClubCompetition $competition, ClubCompetitionCloseService $close)
    {
        $this->authorize('admin.manage', $club);
        $result = $close->finalize($competition, $request->user(), actorKind: self::ACTOR);
        $this->logAdmin($request, 'competition_finalized', ['club_id' => $club->id, 'competition_id' => $competition->id]);

        return back()->with('success', $result['finalized']
            ? "Competition finalized: {$result['placements']} placements written."
            : 'This competition was already finalized.');
    }

    public function voidAttempt(ClubCompetitionActionRequest $request, Club $club, ClubCompetition $competition, ClubCompetitionAttempt $attempt)
    {
        $this->authorize('admin.manage', $club);
        $this->competitions->voidAttempt($attempt, $request->user(), (string) $request->validated('reason'));
        $this->logAdmin($request, 'attempt_voided', ['club_id' => $club->id, 'competition_id' => $competition->id, 'attempt_id' => $attempt->id]);

        return back()->with('success', 'Attempt voided and the player\'s standing recomputed.');
    }

    // ------------------------------------------------------------------

    /** Pack options, warnings and the editability of every field. */
    private function editorData(Club $club, ?ClubCompetition $competition): array
    {
        $packs = ChallengePack::where('club_id', $club->id)
            ->where(fn ($q) => $q->where('status', '!=', ChallengePack::STATUS_ARCHIVED)
                ->when($competition, fn ($qq) => $qq->orWhereIn('id', ClubCompetitionPack::where('club_competition_id', $competition->id)->select('challenge_pack_id'))))
            ->with('challenges')
            ->orderBy('name')
            ->get();

        $linked = $competition
            ? ClubCompetitionPack::where('club_competition_id', $competition->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('challenge_pack_id')
            : collect();

        $fields = ['name', 'description', 'starts_at', 'ends_at', 'replay_mode', 'max_attempts', 'score_rule', 'normal_play_policy', 'pack_ids'];
        $editable = [];
        foreach ($fields as $field) {
            $editable[$field] = $competition === null || !$competition->exists || $this->guard->isEditable($competition, $field);
        }

        return [
            'packs'      => $packs,
            'linked'     => $linked,
            'inProgress' => $this->competitions->inProgressNormalAttempts($club),
            'editable'   => $editable,
            'locked'     => $competition?->exists ? $competition->isLocked() : false,
            'maxCap'     => max(2, (int) config('ballspot.clubs.competition.max_attempts_cap', 10)),
            'maxDays'    => (int) config('ballspot.clubs.competition.max_duration_days', 120),
        ];
    }

    /** Standings tab: ranked visible players, hidden ones (D22) and per-player attempts. */
    private function standingsData(Request $request, ClubCompetition $competition): array
    {
        $ranked  = $this->standings->ranked($competition);
        $visible = $ranked->pluck('user_id')->all();

        $hidden = ClubCompetitionEntry::with('user:id,username')
            ->where('club_competition_id', $competition->id)
            ->whereNotIn('user_id', $visible ?: [0])
            ->get()
            ->map(fn (ClubCompetitionEntry $e) => [
                'rank' => null, 'user_id' => (int) $e->user_id, 'username' => $e->user?->username ?? 'deleted user',
                'total_score' => (int) $e->total_score, 'packs_completed' => (int) $e->packs_completed, 'packs_counted' => (int) $e->packs_counted,
                'attempts_used' => (int) $e->attempts_used, 'score_reached_at' => $e->getRawOriginal('score_reached_at'),
                'member_status' => ClubMember::where('club_id', $competition->club_id)->where('user_id', $e->user_id)->value('status') ?? 'removed',
            ]);

        $rows = $ranked->map(fn ($e) => $e + ['member_status' => ClubMemberStatus::Active->value])->concat($hidden)->values();

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::STANDINGS_PER_PAGE)->values(), $rows->count(), self::STANDINGS_PER_PAGE, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $attempts = ClubCompetitionAttempt::with('pack:id,name')
            ->where('club_competition_id', $competition->id)
            ->whereIn('user_id', $paginator->getCollection()->pluck('user_id')->all() ?: [0])
            ->orderBy('challenge_pack_id')->orderBy('attempt_number')
            ->get()
            ->groupBy('user_id');

        return [
            'standingsRows'  => $paginator,
            'attemptsByUser' => $attempts,
            'rankedCount'    => $ranked->count(),
            'canVoidAttempt' => ($competition->state === CompetitionState::Published && $competition->isLocked()) || $competition->state === CompetitionState::Finalized,
        ];
    }
}
