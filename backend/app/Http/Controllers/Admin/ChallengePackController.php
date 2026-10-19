<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\Sport;
use App\Services\Clubs\ClubAuditLogger;
use App\Services\Clubs\ClubContentScope;
use App\Services\Clubs\ClubMediaService;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin packs. BallPicker Clubs (G6): a pack is either BallPicker (club_id
 * NULL, public catalogue) or a club pack (private to members). The owner is
 * chosen at creation and never changes. Invariants (server-side, see
 * ClubContentScope): public packs hold BallPicker challenges only; club packs
 * hold their own club's challenges plus BallPicker challenges (D4). A club
 * pack's trophy badge carries badges.club_id (never in global catalogues).
 * Club pack changes are written to the club audit trail.
 */
class ChallengePackController extends Controller
{
    public function __construct(
        private ClubContentScope $scope,
        private ClubAuditLogger $audit,
        private ClubMembershipService $memberships,
        private ClubMediaService $media,
    ) {}

    public function index(Request $request)
    {
        $scope = $this->scope->normalize($request->query('club'));

        $packs = $this->scope->apply(ChallengePack::query(), $scope)
            ->with(['sport', 'club:id,name'])
            ->withCount('challenges')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.packs.index', [
            'packs'       => $packs,
            'scope'       => $scope,
            'scopeClub'   => $this->scope->club($scope),
            'clubOptions' => $this->scope->clubOptions(),
            'clubPackCount' => ChallengePack::whereNotNull('club_id')->count(),
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.packs.create', [
            'sports'      => Sport::orderBy('name')->get(),
            // ?club=<id> presets the owner (club detail → "New club pack").
            'presetClub'  => $this->scope->club($this->scope->normalize($request->query('club'))),
            'clubOptions' => $this->scope->clubOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $club = !empty($data['club_id']) ? Club::find((int) $data['club_id']) : null;

        $pack = DB::transaction(function () use ($request, $data, $club) {
            $pack = ChallengePack::create([
                'club_id'          => $club?->id,
                'sport_id'         => $data['sport_id'] ?? null,
                'name'             => $data['name'],
                'slug'             => $this->uniqueSlug($data['slug'] ?? $data['name'], null),
                'description'      => $data['description'] ?? null,
                'status'           => $data['status'],
                'visibility'       => $data['visibility'],
                'difficulty'       => $data['difficulty'] ?? null,
                'sort_order'       => $data['sort_order'] ?? 0,
                'is_featured'      => $request->boolean('is_featured'),
                'cover_image_path' => $request->hasFile('cover_image')
                    ? $this->storeCover($request, $club)
                    : null,
            ]);

            $this->syncCompletionTrophy($pack, $request->boolean('award_completion_trophy'));

            if ($club) {
                $this->audit->log($club, 'pack.created', $request->user(), $pack, [
                    'pack_id' => $pack->id, 'name' => $pack->name, 'status' => $pack->status,
                ]);
                $this->memberships->refreshCounters($club);
            }

            return $pack;
        });

        \App\Support\AppLog::event('admin.pack_created', [
            'pack_id' => $pack->id, 'status' => $pack->status, 'visibility' => $pack->visibility,
            'challenge_count' => 0, 'trophy' => $pack->fresh()->completion_badge_id !== null,
            'admin_user_id' => $request->user()->id, 'club_id' => $pack->club_id,
        ]);

        return redirect('/admin/packs/' . $pack->id . '/edit')
            ->with('success', 'Pack created. Now add challenges below.');
    }

    public function edit(ChallengePack $pack)
    {
        $pack->load('challenges', 'sport', 'club:id,name');

        // Candidate challenges to add: scoped to the pack's sport when set and
        // to the pack's owner (public pack → BallPicker only; club pack → its
        // club's challenges first, then BallPicker). Pack-pool content lists
        // first — packs are curated from the Pack pool; other pools stay
        // selectable for explicit admin picks.
        $available = $this->scope->eligibleChallenges($pack)
            ->with('sport')
            ->when($pack->sport_id, fn ($q) => $q->where('sport_id', $pack->sport_id))
            ->orderByRaw('CASE WHEN club_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw("CASE WHEN usage_pool = 'pack' THEN 0 ELSE 1 END")
            ->orderBy('title')
            ->get();

        return view('admin.packs.edit', [
            'pack'      => $pack,
            'sports'    => Sport::orderBy('name')->get(),
            'available' => $available,
            'selected'  => $pack->challenges->pluck('id'),
        ]);
    }

    public function update(Request $request, ChallengePack $pack)
    {
        $data = $this->validated($request, $pack->id);

        // Invariant first: nothing is written when a challenge is out of scope.
        if ($request->has('challenges')) {
            $this->scope->assertPackChallenges($pack, (array) $request->input('challenges', []));
        }
        $this->assertStaysPlayableForCompetitions($pack, $data['status'], $data['visibility']);
        $wasStatus = $pack->status;

        if ($request->hasFile('cover_image')) {
            // Store first: a refused club cover (photo_invalid) keeps the old one.
            $data['cover_image_path'] = $this->storeCover($request, $pack->club_id !== null ? Club::find($pack->club_id) : null);
            if ($pack->cover_image_path) {
                Storage::disk('public')->delete($pack->cover_image_path);
            }
        }

        $pack->update([
            'sport_id'         => $data['sport_id'] ?? null,
            'name'             => $data['name'],
            'slug'             => $this->uniqueSlug($data['slug'] ?? $data['name'], $pack->id),
            'description'      => $data['description'] ?? null,
            'status'           => $data['status'],
            'visibility'       => $data['visibility'],
            'difficulty'       => $data['difficulty'] ?? null,
            'sort_order'       => $data['sort_order'] ?? 0,
            'is_featured'      => $request->boolean('is_featured'),
            'cover_image_path' => $data['cover_image_path'] ?? $pack->cover_image_path,
        ]);

        $this->syncCompletionTrophy($pack, $request->boolean('award_completion_trophy'));

        // Sync membership. sort_order follows the submitted order so simple
        // reordering works without a drag UI. Detach never deletes challenges.
        if ($request->has('challenges')) {
            $ids = collect($request->input('challenges', []))
                ->filter()
                ->values();
            $sync = $ids->mapWithKeys(fn ($id, $i) => [(int) $id => ['sort_order' => $i]])->all();
            $pack->challenges()->sync($sync);
        }

        $fresh = $pack->fresh();
        $this->auditClubPack($request, $fresh, $wasStatus);
        \App\Support\AppLog::event('admin.pack_updated', [
            'pack_id' => $fresh->id, 'status' => $fresh->status, 'visibility' => $fresh->visibility,
            'challenge_count' => $fresh->challenges()->count(), 'trophy' => $fresh->completion_badge_id !== null,
            'admin_user_id' => $request->user()->id, 'club_id' => $fresh->club_id,
        ]);

        return redirect($this->listUrl($fresh))->with('success', $this->readinessNote($fresh));
    }

    /** Quick status/visibility change from the index. */
    public function status(Request $request, ChallengePack $pack)
    {
        $data = $request->validate([
            'status'     => ['nullable', Rule::in(ChallengePack::STATUSES)],
            'visibility' => ['nullable', Rule::in(ChallengePack::VISIBILITIES)],
        ]);

        $this->assertStaysPlayableForCompetitions($pack, $data['status'] ?? $pack->status, $data['visibility'] ?? $pack->visibility);
        $wasStatus = $pack->status;
        $pack->update(array_filter([
            'status'     => $data['status'] ?? null,
            'visibility' => $data['visibility'] ?? null,
        ]));

        $fresh = $pack->fresh();
        $this->auditClubPack($request, $fresh, $wasStatus);
        \App\Support\AppLog::event('admin.pack_updated', [
            'pack_id' => $fresh->id, 'status' => $fresh->status, 'visibility' => $fresh->visibility,
            'challenge_count' => $fresh->challenges()->count(), 'trophy' => $fresh->completion_badge_id !== null,
            'admin_user_id' => $request->user()->id, 'club_id' => $fresh->club_id,
        ]);

        return redirect($this->listUrl($fresh))->with('success', $this->readinessNote($fresh));
    }

    /**
     * A club pack linked to a published competition that is not finalized,
     * cancelled or voided must stay active + public until it is: members
     * would otherwise lose a pack mid-competition (plan §5.7).
     */
    private function assertStaysPlayableForCompetitions(ChallengePack $pack, string $status, string $visibility): void
    {
        if ($pack->club_id === null
            || ($status === ChallengePack::STATUS_ACTIVE && $visibility === ChallengePack::VISIBILITY_PUBLIC)) {
            return;
        }

        $linked = ClubCompetition::query()
            ->where('club_id', $pack->club_id)
            ->where('state', CompetitionState::Published->value)
            ->whereHas('competitionPacks', fn ($q) => $q->where('challenge_pack_id', $pack->id))
            ->exists();

        if ($linked) {
            throw new ClubDomainException(ClubDomainException::PACK_IN_COMPETITION, 422);
        }
    }

    /** Club packs: audit trail (pack.updated / pack.archived) + denormalised counters. */
    private function auditClubPack(Request $request, ChallengePack $pack, string $wasStatus): void
    {
        if ($pack->club_id === null || !($club = Club::find($pack->club_id))) {
            return;
        }

        DB::transaction(function () use ($request, $pack, $club, $wasStatus) {
            $archived = $pack->status === ChallengePack::STATUS_ARCHIVED && $wasStatus !== ChallengePack::STATUS_ARCHIVED;
            $this->audit->log($club, $archived ? 'pack.archived' : 'pack.updated', $request->user(), $pack, array_filter([
                'pack_id'     => $pack->id,
                'name'        => $pack->name,
                'status'      => $pack->status,
                'from_status' => $wasStatus !== $pack->status ? $wasStatus : null,
                'count'       => $pack->challenges()->count(),
            ], fn ($v) => $v !== null));
            $this->memberships->refreshCounters($club);
        });
    }

    /**
     * Club pack covers are GD re-encoded under a random name (EXIF/GPS
     * dropped, like every club image); BallPicker covers keep the plain
     * upload exactly as before.
     */
    private function storeCover(Request $request, ?Club $club): string
    {
        $file = $request->file('cover_image');

        return $club !== null
            ? $this->media->storePackCover($file, $club)
            : $file->store('packs/covers', 'public');
    }

    private function listUrl(ChallengePack $pack): string
    {
        return $pack->club_id ? '/admin/packs?club=' . $pack->club_id : '/admin/packs';
    }

    private function validated(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'slug'        => ['nullable', 'string', 'max:140'],
            'sport_id'    => ['nullable', 'exists:sports,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status'      => ['required', Rule::in(ChallengePack::STATUSES)],
            'visibility'  => ['required', Rule::in(ChallengePack::VISIBILITIES)],
            'difficulty'  => ['nullable', 'in:easy,medium,hard,mixed'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['boolean'],
            'cover_image' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'award_completion_trophy' => ['boolean'],
            // Owner is chosen at creation only (club packs never become public).
            'club_id'     => $ignoreId === null ? ['nullable', 'integer', 'exists:clubs,id'] : ['prohibited'],
        ]);
    }

    /**
     * Create/link or unlink the per-pack completion trophy. The badge code is
     * keyed on the pack id, so re-saving the pack reuses (and renames) the same
     * badge row instead of creating duplicates. Disabling only unlinks — users
     * who already earned the trophy keep it.
     */
    private function syncCompletionTrophy(ChallengePack $pack, bool $enabled): void
    {
        if (!$enabled) {
            if ($pack->completion_badge_id) {
                $pack->update(['completion_badge_id' => null]);
            }
            return;
        }

        $badge = Badge::updateOrCreate(
            ['code' => "pack_{$pack->id}_completed"],
            [
                'name'        => "{$pack->name} Completed",
                'description' => "Complete the {$pack->name} challenge pack.",
                'icon'        => $pack->sport?->emoji ?? '🏆',
                'category'    => 'pack',
                // Club pack trophies belong to the club: never in /api/badges,
                // /me/badges or public profiles (G3).
                'club_id'     => $pack->club_id,
                'rarity'      => match ($pack->difficulty) {
                    'hard'            => 'epic',
                    'medium', 'mixed' => 'rare',
                    default           => 'common',
                },
            ],
        );

        if ($pack->completion_badge_id !== $badge->id) {
            $pack->update(['completion_badge_id' => $badge->id]);
        }
    }

    private function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: 'pack';
        $slug = $base;
        $i = 2;
        while (
            ChallengePack::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /** Warn (do not block) if a pack is active with no ready challenges. */
    private function readinessNote(ChallengePack $pack): string
    {
        if ($pack->status === ChallengePack::STATUS_ACTIVE && $pack->readyChallenges()->isEmpty()) {
            return "Pack saved. Heads up: \"{$pack->name}\" is active but has no ready challenges yet.";
        }

        return 'Pack saved.';
    }
}
