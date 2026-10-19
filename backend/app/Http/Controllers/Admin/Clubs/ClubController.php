<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubType;
use App\Enums\NormalPlayPolicy;
use App\Enums\PhotoSubmissionStatus;
use App\Enums\ReplayMode;
use App\Http\Requests\Clubs\Admin\ClubStatusReasonRequest;
use App\Http\Requests\Clubs\Admin\StoreClubRequest;
use App\Http\Requests\Clubs\Admin\UpdateClubRequest;
use App\Http\Requests\Clubs\Admin\UpdateClubSettingsRequest;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubMember;
use App\Models\ClubPhotoSubmission;
use App\Models\Sport;
use App\Services\Clubs\AdminClubAuditQuery;
use App\Services\Clubs\AdminClubIndexQuery;
use App\Services\Clubs\ClubService;
use App\Services\Clubs\ClubStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Central admin → Clubs (plan §7.2, §8.2–8.3): index, create, detail tabs,
 * edit, settings, suspend / archive / reactivate, delete-if-empty.
 */
class ClubController extends AdminClubController
{
    public const TABS = ['overview', 'members', 'codes', 'packs', 'competitions', 'photos', 'settings', 'audit'];

    public function __construct(private ClubService $clubs) {}

    public function index(Request $request, AdminClubIndexQuery $index)
    {
        Gate::authorize('admin.manage');

        $filters = $index->normalize($request->query());

        return view('admin.clubs.index', [
            'clubs'      => $index->paginate($filters),
            'filters'    => $filters,
            'isFiltered' => $index->isFiltered($filters),
            'counts'     => $index->statusCounts(),
            'sports'     => Sport::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'emoji']),
            'countries'  => $index->countryOptions(),
            'types'      => ClubType::values(),
            'sorts'      => ['activity' => 'Last activity', 'name' => 'Name A–Z', 'created' => 'Newest first', 'members' => 'Most members'],
        ]);
    }

    public function create()
    {
        Gate::authorize('admin.manage');

        return view('admin.clubs.create', $this->formOptions() + ['club' => null]);
    }

    public function store(StoreClubRequest $request)
    {
        Gate::authorize('admin.manage');

        $club = DB::transaction(function () use ($request) {
            $club = $this->clubs->create($request->clubData(), $request->user());
            if ($request->hasFile('logo')) {
                $club = $this->clubs->replaceLogo($club, $request->file('logo'), $request->user());
            }

            return $club;
        });

        $this->logAdmin($request, 'created', ['club_id' => $club->id, 'with_owner' => $request->owner() !== null]);

        return redirect()->route('admin.clubs.show', $club)->with('success', "Club \"{$club->name}\" created."
            . ($request->owner() ? '' : ' It has no owner yet — add one on the Members tab.'));
    }

    public function show(Request $request, Club $club, ClubStatsService $stats, AdminClubAuditQuery $audit)
    {
        $this->authorize('admin.manage', $club);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';

        $club->load(['sport:id,name,emoji', 'createdBy:id,username,name', 'owners.user:id,username,name']);

        $counts = [
            'members'      => (int) $club->members_count,
            'codes'        => $club->inviteCodes()->usable()->count(),
            'packs'        => ChallengePack::where('club_id', $club->id)->count(),
            'competitions' => ClubCompetition::where('club_id', $club->id)->count(),
            'photos'       => ClubPhotoSubmission::where('club_id', $club->id)->open()->count(),
        ];

        $data = match ($tab) {
            'overview'     => ['stats' => $stats->overview($club)],
            'members'      => $this->membersTab($request, $club),
            'codes'        => $this->codesTab($club),
            'packs'        => $this->packsTab($club),
            'competitions' => ['competitions' => ClubCompetition::where('club_id', $club->id)
                ->withCount('competitionPacks')
                ->orderByDesc('starts_at')->orderByDesc('id')
                ->paginate(25)->withQueryString()],
            'photos'       => $this->photosTab($request, $club),
            'settings'     => $this->formOptions(),
            'audit'        => [
                'events'        => $audit->paginate($request->query(), $club->id),
                'auditFilters'  => $audit->normalize($request->query()),
                'auditFiltered' => $audit->isFiltered($audit->normalize($request->query())),
            ],
        };

        return view('admin.clubs.show', [
            'club'       => $club,
            'tab'        => $tab,
            'tabCounts'  => $counts,
            'hasHistory' => $this->clubs->hasHistory($club),
        ] + $data);
    }

    public function edit(Club $club)
    {
        $this->authorize('admin.manage', $club);

        return view('admin.clubs.edit', $this->formOptions() + ['club' => $club]);
    }

    public function update(UpdateClubRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        DB::transaction(function () use ($request, $club) {
            $this->clubs->update($club, $request->clubData(), $request->user(), (int) $request->input('version'));

            if ($request->hasFile('logo')) {
                $this->clubs->replaceLogo($club, $request->file('logo'), $request->user());
            } elseif ($request->boolean('remove_logo')) {
                $this->clubs->replaceLogo($club, null, $request->user());
            }
        });

        $this->logAdmin($request, 'updated', ['club_id' => $club->id]);

        return redirect()->route('admin.clubs.show', $club)->with('success', 'Club details saved.');
    }

    public function updateSettings(UpdateClubSettingsRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        try {
            $this->clubs->updateSettings($club, $request->settings(), $request->user(), self::ACTOR, (int) $request->input('version'));
        } catch (\InvalidArgumentException) {
            return back()->withErrors(['settings' => 'One of the settings has an invalid value.'])->withInput();
        }

        $this->logAdmin($request, 'settings_changed', ['club_id' => $club->id]);

        return redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'settings'])->with('success', 'Club settings saved.');
    }

    public function suspend(ClubStatusReasonRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $this->clubs->suspend($club, $request->user(), (string) $request->input('reason'));
        $this->logAdmin($request, 'suspended', ['club_id' => $club->id]);

        return back()->with('success', "\"{$club->name}\" is suspended: members see it as temporarily unavailable and cannot play or join.");
    }

    public function archive(ClubStatusReasonRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $this->clubs->archive($club, $request->user(), (string) $request->input('reason'));
        $this->logAdmin($request, 'archived', ['club_id' => $club->id]);

        return back()->with('success', "\"{$club->name}\" is archived: hidden from My Clubs, all history kept.");
    }

    public function reactivate(Request $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $this->clubs->reactivate($club, $request->user());
        $this->logAdmin($request, 'reactivated', ['club_id' => $club->id]);

        return back()->with('success', "\"{$club->name}\" is active again.");
    }

    public function destroy(Request $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $name = $club->name;
        $id   = $club->id;
        $this->clubs->deleteIfEmpty($club, $request->user());
        $this->logAdmin($request, 'deleted', ['club_id' => $id]);

        return redirect()->route('admin.clubs.index')->with('success', "Club \"{$name}\" deleted.");
    }

    /** "New club pack" → the existing pack form with this club preset. */
    public function createPack(Club $club)
    {
        $this->authorize('admin.manage', $club);

        return redirect('/admin/packs/create?club=' . $club->id);
    }

    // ------------------------------------------------------------------

    private function formOptions(): array
    {
        return [
            'sports'         => Sport::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'emoji']),
            'types'          => ClubType::values(),
            'timezones'      => \DateTimeZone::listIdentifiers(),
            'defaultTz'      => (string) (config('ballspot.competition.timezone') ?: config('app.timezone', 'UTC')),
            'replayModes'    => array_map(fn ($c) => $c->value, ReplayMode::cases()),
            'normalPolicies' => array_map(fn ($c) => $c->value, NormalPlayPolicy::cases()),
        ];
    }

    private function membersTab(Request $request, Club $club): array
    {
        $q      = is_scalar($request->query('q')) ? mb_substr(trim((string) $request->query('q')), 0, 100) : '';
        $role   = in_array($request->query('role'), ClubRole::values(), true) ? $request->query('role') : '';
        $status = $request->query('status');
        $status = $status === 'any' || in_array($status, ClubMemberStatus::values(), true) ? $status : ClubMemberStatus::Active->value;

        $members = ClubMember::query()
            ->where('club_id', $club->id)
            ->with(['user:id,username,name,email,anonymized_at', 'inviteCode:id,code', 'addedBy:id,username'])
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . AdminClubIndexQuery::escapeLike(mb_strtolower($q)) . '%';
                // Correlated EXISTS: bounded by this club's membership rows.
                $query->whereHas('user', fn ($u) => $u->where(fn ($w) => $w
                    ->whereRaw("LOWER(username) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$like])));
            })
            ->when($role !== '', fn ($query) => $query->where('role', $role))
            ->when($status !== 'any', fn ($query) => $query->where('status', $status))
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderByDesc('joined_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $statusCounts = ClubMember::where('club_id', $club->id)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'members'       => $members,
            'memberFilters' => ['q' => $q, 'role' => $role, 'status' => $status],
            'memberCounts'  => $statusCounts,
        ];
    }

    private function codesTab(Club $club): array
    {
        $codes = $club->inviteCodes()
            ->with('createdBy:id,username')
            ->orderByRaw('CASE WHEN revoked_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return [
            'codes'       => $codes,
            'activeCodes' => $club->inviteCodes()->usable()->count(),
            'codeLimit'   => (int) config('ballspot.clubs.limits.max_active_codes', 20),
        ];
    }

    private function packsTab(Club $club): array
    {
        return [
            'packs' => ChallengePack::query()
                ->where('club_id', $club->id)
                ->with('sport:id,name,emoji')
                ->withCount([
                    'challenges',
                    'attempts as completions_count' => fn ($q) => $q->where('status', \App\Models\PackAttempt::STATUS_COMPLETED),
                    'competitions',
                ])
                ->orderBy('sort_order')->orderBy('name')
                ->paginate(25)->withQueryString(),
        ];
    }

    private function photosTab(Request $request, Club $club): array
    {
        $status = in_array($request->query('status'), PhotoSubmissionStatus::values(), true) ? $request->query('status') : '';

        return [
            'photos' => ClubPhotoSubmission::query()
                ->where('club_id', $club->id)
                ->with(['uploadedBy:id,username', 'assignedTo:id,username'])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(25)->withQueryString(),
            'photoStatus' => $status,
        ];
    }
}
