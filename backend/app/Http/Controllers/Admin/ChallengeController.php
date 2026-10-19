<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\ChallengeSubcategory;
use App\Models\Club;
use App\Models\ClubCompetitionAttemptGuess;
use App\Models\DailyChallenge;
use App\Models\LeagueRound;
use App\Models\PackAttemptGuess;
use App\Models\Sport;
use App\Models\Tag;
use App\Services\Clubs\ClubContentScope;
use App\Services\Clubs\ClubMediaService;
use App\Support\AppLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChallengeController extends Controller
{
    /**
     * Ball coordinates are 0..1 ratios. Three decimals is about one pixel on a
     * 1000px image — precise enough for scoring, short enough to hand-edit.
     */
    private const BALL_RATIO_DECIMALS = 3;

    /** Warn when a sport has fewer unique tournament photos than the longest tournament (7 days). */
    private const LOW_TOURNAMENT_POOL = 7;

    /** List sort options (query `sort`); anything else falls back to newest. */
    public const SORTS = ['newest', 'oldest', 'title'];

    public function __construct(private ClubContentScope $scope, private ClubMediaService $clubMedia) {}

    public function index(Request $request)
    {
        $sort = in_array($request->sort, self::SORTS, true) ? $request->sort : 'newest';

        // Club scope (G6): '' = BallPicker content (default), 'all' = every
        // club, '<id>' = one club. Club photos never mix into the default list.
        $scope = $this->scope->normalize($request->query('club'));

        // Shared filter set. Both sections (available + used-daily) apply the
        // same filters; they differ only by the daily-used split (v1.9.1).
        $base = fn () => $this->scope->apply(Challenge::query(), $scope)
            ->with(['sport', 'category', 'tags', 'subcategories', 'club:id,name'])
            ->withExists(['dailyChallenges as used_as_daily'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->difficulty, fn ($q, $v) => $q->where('difficulty', $v))
            ->when($request->category, fn ($q, $v) => $q->where('challenge_category_id', $v))
            ->when($request->sport, fn ($q, $v) => $q->where('sport_id', $v))
            ->when($request->tag, fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $v)))
            ->when($request->has_reveal === 'yes', fn ($q) => $q->whereNotNull('original_image_path'))
            ->when($request->has_reveal === 'no',  fn ($q) => $q->whereNull('original_image_path'))
            ->when($request->usage_pool && in_array($request->usage_pool, Challenge::POOLS, true), fn ($q) => $q->where('usage_pool', $request->usage_pool))
            ->when($request->tournament === 'eligible', fn ($q) => $q->tournamentEligibleStrict())
            ->when($request->tournament === 'blocked',  fn ($q) => $q->tournamentBlocked())
            ->when($request->search, fn ($q, $v) => $q->where('title', 'like', '%' . $v . '%'))
            // Presentation-only ordering (v1.9.14): newest (default), oldest, title A–Z.
            ->when($sort === 'oldest', fn ($q) => $q->oldest(), fn ($q) => $q->latest())
            ->when($sort === 'title', fn ($q) => $q->reorder('title')->orderBy('id'));

        $usedFilter = $request->used_as_daily; // null, 'yes' or 'no'

        // Main section: challenges never used as a Daily. Skipped entirely when
        // the admin explicitly filters for "Used" only.
        $query = $usedFilter === 'yes'
            ? $base()->whereRaw('1 = 0')->paginate(25)->withQueryString()
            : $base()->notDailyUsed()->paginate(25)->withQueryString();

        // Collapsed section: Daily-used challenges (permanently excluded from
        // tournaments). Separate page name so the two paginators don't clash.
        $usedDaily = $usedFilter === 'no'
            ? null
            : $base()->dailyUsed()->paginate(25, ['*'], 'used_page')->withQueryString();

        // Auto-open when filtering for used items, when paging inside the
        // panel, or when the current filters matched only used items.
        $usedDailyOpen = $usedDaily !== null && (
            $usedFilter === 'yes'
            || $request->has('used_page')
            || ($query->isEmpty() && $usedDaily->isNotEmpty())
        );

        $categories = ChallengeCategory::orderBy('sort_order')->orderBy('name')->get();
        $sports     = Sport::orderBy('sort_order')->get();
        $tags       = Tag::orderBy('name')->get();

        // Simple low-pool warning: how many unique photos a NEW tournament can
        // draw from, per active sport. A 7-day tournament needs 7.
        $tournamentPool = Challenge::tournamentEligible()
            ->get()
            ->filter->isReady()
            ->groupBy('sport_id')
            ->map->count();
        $lowTournamentPools = $sports
            ->filter(fn ($s) => $s->status === Sport::STATUS_ACTIVE)
            ->map(fn ($s) => ['sport' => $s, 'count' => (int) ($tournamentPool[$s->id] ?? 0)])
            ->filter(fn ($row) => $row['count'] < self::LOW_TOURNAMENT_POOL)
            ->values();

        // Summary tiles (whole catalogue, independent of the current filter).
        // Pack and General are separate pools (challenges.usage_pool holds
        // exactly one value) and are counted separately — v1.9.14.
        // BallPicker catalogue only: club photos are never part of a pool.
        $tournamentEligibleTotal = $tournamentPool->sum();
        $poolCounts = Challenge::publicContent()->selectRaw('usage_pool, COUNT(*) as c')->groupBy('usage_pool')->pluck('c', 'usage_pool');
        $summary = [
            'total'               => Challenge::publicContent()->count(),
            'daily_pool'          => (int) ($poolCounts[Challenge::POOL_DAILY] ?? 0),
            'tournament_eligible' => $tournamentEligibleTotal,
            'pack'                => (int) ($poolCounts[Challenge::POOL_PACK] ?? 0),
            'general'             => (int) ($poolCounts[Challenge::POOL_GENERAL] ?? 0),
            'used_as_daily'       => Challenge::publicContent()->dailyUsed()->count(),
            'archived'            => Challenge::publicContent()->where('status', 'archived')->count(),
            'low_tournament'      => $tournamentEligibleTotal < self::LOW_TOURNAMENT_POOL,
            'club'                => Challenge::whereNotNull('club_id')->count(),
        ];

        $scopeClub   = $this->scope->club($scope);
        $clubOptions = $this->scope->clubOptions();

        return view('admin.challenges.index', compact('query', 'categories', 'sports', 'tags', 'lowTournamentPools', 'summary', 'usedDaily', 'usedDailyOpen', 'sort', 'scope', 'scopeClub', 'clubOptions'))->with('challenges', $query);
    }

    public function create(Request $request)
    {
        $categories = ChallengeCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $sports     = Sport::orderBy('sort_order')->get();
        $subcategories = $this->subcategoryOptions();
        // ?club=<id> presets the owner (club detail → "New club challenge").
        $presetClub  = $this->scope->club($this->scope->normalize($request->query('club')));
        $clubOptions = $this->scope->clubOptions();
        return view('admin.challenges.create', compact('categories', 'sports', 'subcategories', 'presetClub', 'clubOptions'));
    }

    public function store(Request $request)
    {
        $this->normalizeBallRatios($request);

        $data = $this->validateChallenge($request, [
            'title'                  => ['required', 'string', 'max:255'],
            'difficulty'             => ['required', 'in:easy,medium,hard'],
            'status'                 => ['required', 'in:draft,active,archived'],
            'usage_pool'             => ['sometimes', 'nullable', 'in:' . implode(',', Challenge::POOLS)],
            'sport_id'               => ['nullable', 'exists:sports,id'],
            'challenge_category_id'  => ['nullable', 'exists:challenge_categories,id'],
            'ball_x_ratio'           => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio'           => ['required', 'numeric', 'between:0,1'],
            'hidden_image'           => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'original_image'         => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'tags'                   => ['nullable', 'string', 'max:500'],
            'subcategories'          => ['nullable', 'array'],
            'subcategories.*'        => ['integer', 'exists:challenge_subcategories,id'],
            'club_id'                => ['nullable', 'integer', 'exists:clubs,id'],
        ]);

        // Default to football to preserve existing behaviour.
        $sportId = $data['sport_id'] ?? Sport::where('slug', 'football')->value('id');
        $club    = !empty($data['club_id']) ? Club::find((int) $data['club_id']) : null;

        if ($club) {
            // Club photos (G1/G6): private disk, GD re-encoded (EXIF stripped),
            // usage pool `club` — never Daily, tournaments or public packs.
            $hiddenPath = $this->clubMedia->storeImage($request->file('hidden_image'), $club, ClubMediaService::KIND_CHALLENGE_HIDDEN)['path'];
            try {
                $originalPath = $request->hasFile('original_image')
                    ? $this->clubMedia->storeImage($request->file('original_image'), $club, ClubMediaService::KIND_CHALLENGE_ORIGINAL)['path']
                    : null;
            } catch (\Throwable $e) {
                $this->clubMedia->delete($hiddenPath);
                throw $e;
            }
        } else {
            $hiddenPath = $request->file('hidden_image')->store('challenges/hidden', 'public');
            $originalPath = $request->hasFile('original_image')
                ? $request->file('original_image')->store('challenges/original', 'public')
                : null;
        }

        $challenge = Challenge::create([
            'club_id'                => $club?->id,
            'sport_id'               => $sportId,
            'challenge_category_id'  => $data['challenge_category_id'] ?? null,
            'title'                  => $data['title'],
            'difficulty'             => $data['difficulty'],
            'status'                 => $data['status'],
            'usage_pool'             => $club ? Challenge::POOL_CLUB : ($data['usage_pool'] ?? Challenge::POOL_GENERAL),
            'ball_x_ratio'           => round((float) $data['ball_x_ratio'], self::BALL_RATIO_DECIMALS),
            'ball_y_ratio'           => round((float) $data['ball_y_ratio'], self::BALL_RATIO_DECIMALS),
            'hidden_image_path'      => $hiddenPath,
            'original_image_path'    => $originalPath,
        ]);

        $challenge->tags()->sync($this->resolveTagIds($data['tags'] ?? null));
        $challenge->subcategories()->sync($data['subcategories'] ?? []);

        AppLog::event('admin.challenge_created', [
            'challenge_id' => $challenge->id, 'usage_pool' => $challenge->usage_pool, 'sport_id' => $challenge->sport_id,
            'status' => $challenge->status, 'has_reveal' => $originalPath !== null, 'admin_user_id' => $request->user()->id,
            'club_id' => $challenge->club_id,
        ]);

        return redirect($club ? '/admin/challenges?club=' . $club->id : '/admin/challenges')->with('success', $club ? "Club challenge created for \"{$club->name}\" (private to its members)." : 'Challenge created.');
    }

    public function edit(Challenge $challenge)
    {
        $categories = ChallengeCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $sports     = Sport::orderBy('sort_order')->get();
        $challenge->load('tags', 'subcategories', 'club:id,name');
        $subcategories = $this->subcategoryOptions();
        return view('admin.challenges.edit', compact('challenge', 'categories', 'sports', 'subcategories'));
    }

    public function update(Request $request, Challenge $challenge)
    {
        $this->normalizeBallRatios($request);

        $data = $this->validateChallenge($request, [
            'title'                  => ['required', 'string', 'max:255'],
            'difficulty'             => ['required', 'in:easy,medium,hard'],
            'status'                 => ['required', 'in:draft,active,archived'],
            'usage_pool'             => ['sometimes', 'nullable', 'in:' . implode(',', Challenge::POOLS)],
            'sport_id'               => ['nullable', 'exists:sports,id'],
            'challenge_category_id'  => ['nullable', 'exists:challenge_categories,id'],
            'ball_x_ratio'           => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio'           => ['required', 'numeric', 'between:0,1'],
            'hidden_image'           => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'original_image'         => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'tags'                   => ['nullable', 'string', 'max:500'],
            'subcategories'          => ['nullable', 'array'],
            'subcategories.*'        => ['integer', 'exists:challenge_subcategories,id'],
        ]);

        // Guard: cannot activate a challenge that has no hidden image
        if ($data['status'] === 'active' && !$challenge->hidden_image_path && !$request->hasFile('hidden_image')) {
            return back()->withErrors(['status' => 'Cannot activate a challenge without a hidden image.'])->withInput();
        }

        // Ownership never changes after creation (club photos never become
        // public); a submitted club_id is ignored.
        if ($challenge->club_id !== null) {
            $club = $challenge->club()->firstOrFail();
            if ($request->hasFile('hidden_image')) {
                $data['hidden_image_path'] = $this->clubMedia->storeImage($request->file('hidden_image'), $club, ClubMediaService::KIND_CHALLENGE_HIDDEN)['path'];
            }
            if ($request->hasFile('original_image')) {
                $data['original_image_path'] = $this->clubMedia->storeImage($request->file('original_image'), $club, ClubMediaService::KIND_CHALLENGE_ORIGINAL)['path'];
            }
            foreach (['hidden_image_path', 'original_image_path'] as $column) {
                if (isset($data[$column]) && $challenge->{$column}) {
                    $this->clubMedia->delete($challenge->{$column});
                }
            }
            $data['usage_pool'] = Challenge::POOL_CLUB;
        } else {
            if ($request->hasFile('hidden_image')) {
                if ($challenge->hidden_image_path) Storage::disk('public')->delete($challenge->hidden_image_path);
                $data['hidden_image_path'] = $request->file('hidden_image')->store('challenges/hidden', 'public');
            }
            if ($request->hasFile('original_image')) {
                if ($challenge->original_image_path) Storage::disk('public')->delete($challenge->original_image_path);
                $data['original_image_path'] = $request->file('original_image')->store('challenges/original', 'public');
            }
        }

        $challenge->update([
            'sport_id'               => $data['sport_id'] ?? $challenge->sport_id,
            'challenge_category_id'  => $data['challenge_category_id'] ?? null,
            'title'                  => $data['title'],
            'difficulty'             => $data['difficulty'],
            'status'                 => $data['status'],
            // Omitted (old forms) keeps the stored pool; never silently resets.
            'usage_pool'             => $data['usage_pool'] ?? $challenge->usage_pool,
            'ball_x_ratio'           => round((float) $data['ball_x_ratio'], self::BALL_RATIO_DECIMALS),
            'ball_y_ratio'           => round((float) $data['ball_y_ratio'], self::BALL_RATIO_DECIMALS),
            'hidden_image_path'      => $data['hidden_image_path'] ?? $challenge->hidden_image_path,
            'original_image_path'    => $data['original_image_path'] ?? $challenge->original_image_path,
        ]);

        $challenge->tags()->sync($this->resolveTagIds($data['tags'] ?? null));
        $challenge->subcategories()->sync($data['subcategories'] ?? []);

        AppLog::event('admin.challenge_updated', [
            'challenge_id' => $challenge->id, 'usage_pool' => $challenge->usage_pool, 'sport_id' => $challenge->sport_id,
            'status' => $challenge->status, 'new_hidden_image' => $request->hasFile('hidden_image'),
            'new_reveal_image' => $request->hasFile('original_image'), 'admin_user_id' => $request->user()->id,
            'club_id' => $challenge->club_id,
        ]);

        return redirect($challenge->club_id ? '/admin/challenges?club=' . $challenge->club_id : '/admin/challenges')->with('success', 'Challenge updated.');
    }

    /**
     * Validate the challenge form and log the failing FIELD NAMES (never the
     * values) so "my upload keeps failing" can be diagnosed from the log.
     */
    private function validateChallenge(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $e) {
            $fields = array_keys($e->errors());
            AppLog::warn('admin.challenge_validation_failed', [
                'fields'         => $fields,
                'upload_related' => (bool) array_intersect($fields, ['hidden_image', 'original_image']),
                'admin_user_id'  => $request->user()?->id,
            ]);
            throw $e;
        }
    }

    /**
     * Accept comma decimals ("0,515") from locale-formatted browsers or manual
     * typing. The database and validator both expect dot decimals.
     */
    private function normalizeBallRatios(Request $request): void
    {
        foreach (['ball_x_ratio', 'ball_y_ratio'] as $field) {
            $value = $request->input($field);

            if (is_string($value) && str_contains($value, ',')) {
                $request->merge([$field => str_replace(',', '.', $value)]);
            }
        }
    }

    /**
     * Turn a comma-separated tag string into tag IDs, creating missing tags.
     * Text tags only — no copyrighted logos/imagery.
     *
     * @return int[]
     */
    private function resolveTagIds(?string $tagString): array
    {
        if (empty($tagString)) {
            return [];
        }

        return collect(explode(',', $tagString))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn ($name) => Tag::findOrCreateByName($name)->id)
            ->values()
            ->all();
    }

    /**
     * Active subcategories for the challenge form's multi-select, grouped by a
     * "Sport · Type" heading so the admin can scan them quickly.
     */
    private function subcategoryOptions()
    {
        return ChallengeSubcategory::active()
            ->with('sport')
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($s) => ($s->sport->name ?? 'Global') . ' · ' . $s->type);
    }

    /** Quick status change from the index page. */
    public function updateStatus(Request $request, Challenge $challenge)
    {
        $data = $request->validate(['status' => 'required|in:draft,active,archived']);

        if ($data['status'] === 'active' && !$challenge->hidden_image_path) {
            return back()->with('error', "Cannot activate \"{$challenge->title}\" — no hidden image is set.");
        }

        $challenge->update(['status' => $data['status']]);
        AppLog::event('admin.challenge_updated', [
            'challenge_id' => $challenge->id, 'usage_pool' => $challenge->usage_pool, 'sport_id' => $challenge->sport_id,
            'status' => $challenge->status, 'quick_status' => true, 'admin_user_id' => $request->user()->id,
        ]);
        $label = ucfirst($data['status']);
        return back()->with('success', "\"{$challenge->title}\" is now {$label}.");
    }

    /** Admin preview: shows the challenge as a player would experience it. */
    public function preview(Challenge $challenge)
    {
        return view('admin.challenges.preview', compact('challenge'));
    }

    /** Assign this challenge as the daily challenge for a given date. */
    public function setAsDaily(Request $request, Challenge $challenge)
    {
        $data = $request->validate(['date' => 'required|date']);

        // Club photos are private to the club: never a Daily (G5, AT-ISO-02).
        if ($challenge->club_id !== null) {
            AppLog::warn('admin.set_as_daily_refused', ['challenge_id' => $challenge->id, 'reason' => 'club_content', 'admin_user_id' => $request->user()->id]);

            return back()->with('error', "\"{$challenge->title}\" is a club challenge. Club photos are private to their club and can never be scheduled as a Daily.");
        }

        if (!$challenge->isReadyForDaily()) {
            return back()->with('error', "Challenge \"{$challenge->title}\" is not ready for daily use. It must be active and have a hidden image and ball position.");
        }

        if (!$challenge->isInDailyPool()) {
            return back()->with('error', "Challenge \"{$challenge->title}\" is in the {$challenge->usage_pool} pool. Only daily/general challenges can be scheduled as a daily.");
        }

        // A challenge is a daily at most once, so this shortcut cannot recycle one.
        if (DailyChallenge::where('challenge_id', $challenge->id)->exists()) {
            return back()->with('error', "Challenge \"{$challenge->title}\" was already used as a daily challenge and cannot be scheduled again.");
        }

        $existing = DailyChallenge::whereDate('challenge_date', $data['date'])->first();

        if ($existing && $existing->guesses()->count() > 0) {
            return back()->with('error', "Cannot replace the daily challenge for {$data['date']} — players have already submitted guesses.");
        }

        if ($existing) {
            $existing->update(['challenge_id' => $challenge->id, 'status' => 'active']);
            return back()->with('success', "Daily challenge for {$data['date']} updated to \"{$challenge->title}\".");
        }

        DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $data['date'],
            'status'         => 'active',
        ]);

        return back()->with('success', "Set \"{$challenge->title}\" as daily challenge for {$data['date']}.");
    }

    public function destroy(Request $request, Challenge $challenge)
    {
        // Prefer archiving over deletion. Hard delete is kept for emergencies only.
        // The UI replaces this button with an Archive action.
        //
        // The foreign keys cascade: daily_challenges -> daily_challenge_guesses,
        // league_rounds -> guesses, pack_attempt_guesses. Deleting a photo that
        // was ever played would silently erase other players' history (daily
        // streaks, tournament scores, pack results), so refuse while any
        // reference exists — archiving hides it from every pool just as well.
        $usedIn = array_keys(array_filter([
            'daily'      => $challenge->dailyChallenges()->exists(),
            'tournament' => LeagueRound::where('challenge_id', $challenge->id)->exists(),
            'pack'       => PackAttemptGuess::where('challenge_id', $challenge->id)->exists(),
            'competition' => ClubCompetitionAttemptGuess::where('challenge_id', $challenge->id)->exists(),
        ]));

        if ($usedIn !== []) {
            AppLog::warn('admin.challenge_delete_refused', [
                'challenge_id'  => $challenge->id,
                'used_in'       => $usedIn,
                'admin_user_id' => $request->user()->id,
            ]);

            return redirect('/admin/challenges')->with(
                'error',
                "\"{$challenge->title}\" has been played (" . implode(', ', $usedIn) . ") and cannot be deleted — archive it instead."
            );
        }

        // Club photos live on the private club disk (never /storage).
        $clubId = $challenge->club_id;
        $challenge->delete();
        if ($clubId !== null) {
            foreach ([$challenge->hidden_image_path, $challenge->original_image_path] as $path) {
                if ($path) $this->clubMedia->delete($path);
            }
        } else {
            Storage::disk('public')->delete($challenge->hidden_image_path);
            if ($challenge->original_image_path) Storage::disk('public')->delete($challenge->original_image_path);
        }

        AppLog::warn('admin.challenge_deleted', [
            'challenge_id'  => $challenge->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return redirect('/admin/challenges')->with('success', 'Challenge permanently deleted.');
    }
}
