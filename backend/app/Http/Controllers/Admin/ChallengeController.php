<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\ChallengeSubcategory;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChallengeController extends Controller
{
    /**
     * Ball coordinates are 0..1 ratios. Three decimals is about one pixel on a
     * 1000px image — precise enough for scoring, short enough to hand-edit.
     */
    private const BALL_RATIO_DECIMALS = 3;

    public function index(Request $request)
    {
        $query = Challenge::with(['sport', 'category', 'tags', 'subcategories'])
            ->withExists(['dailyChallenges as used_as_daily'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->difficulty, fn ($q, $v) => $q->where('difficulty', $v))
            ->when($request->category, fn ($q, $v) => $q->where('challenge_category_id', $v))
            ->when($request->sport, fn ($q, $v) => $q->where('sport_id', $v))
            ->when($request->tag, fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $v)))
            ->when($request->has_reveal === 'yes', fn ($q) => $q->whereNotNull('original_image_path'))
            ->when($request->has_reveal === 'no',  fn ($q) => $q->whereNull('original_image_path'))
            ->when($request->used_as_daily === 'yes', fn ($q) => $q->whereHas('dailyChallenges'))
            ->when($request->used_as_daily === 'no',  fn ($q) => $q->whereDoesntHave('dailyChallenges'))
            ->when($request->search, fn ($q, $v) => $q->where('title', 'like', '%' . $v . '%'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $categories = ChallengeCategory::orderBy('sort_order')->orderBy('name')->get();
        $sports     = Sport::orderBy('sort_order')->get();
        $tags       = Tag::orderBy('name')->get();
        return view('admin.challenges.index', compact('query', 'categories', 'sports', 'tags'))->with('challenges', $query);
    }

    public function create()
    {
        $categories = ChallengeCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $sports     = Sport::orderBy('sort_order')->get();
        $subcategories = $this->subcategoryOptions();
        return view('admin.challenges.create', compact('categories', 'sports', 'subcategories'));
    }

    public function store(Request $request)
    {
        $this->normalizeBallRatios($request);

        $data = $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'difficulty'             => ['required', 'in:easy,medium,hard'],
            'status'                 => ['required', 'in:draft,active,archived'],
            'sport_id'               => ['nullable', 'exists:sports,id'],
            'challenge_category_id'  => ['nullable', 'exists:challenge_categories,id'],
            'ball_x_ratio'           => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio'           => ['required', 'numeric', 'between:0,1'],
            'hidden_image'           => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'original_image'         => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'tags'                   => ['nullable', 'string', 'max:500'],
            'subcategories'          => ['nullable', 'array'],
            'subcategories.*'        => ['integer', 'exists:challenge_subcategories,id'],
        ]);

        // Default to football to preserve existing behaviour.
        $sportId    = $data['sport_id'] ?? Sport::where('slug', 'football')->value('id');
        $hiddenPath = $request->file('hidden_image')->store('challenges/hidden', 'public');
        $originalPath = $request->hasFile('original_image')
            ? $request->file('original_image')->store('challenges/original', 'public')
            : null;

        $challenge = Challenge::create([
            'sport_id'               => $sportId,
            'challenge_category_id'  => $data['challenge_category_id'] ?? null,
            'title'                  => $data['title'],
            'difficulty'             => $data['difficulty'],
            'status'                 => $data['status'],
            'ball_x_ratio'           => round((float) $data['ball_x_ratio'], self::BALL_RATIO_DECIMALS),
            'ball_y_ratio'           => round((float) $data['ball_y_ratio'], self::BALL_RATIO_DECIMALS),
            'hidden_image_path'      => $hiddenPath,
            'original_image_path'    => $originalPath,
        ]);

        $challenge->tags()->sync($this->resolveTagIds($data['tags'] ?? null));
        $challenge->subcategories()->sync($data['subcategories'] ?? []);

        return redirect('/admin/challenges')->with('success', 'Challenge created.');
    }

    public function edit(Challenge $challenge)
    {
        $categories = ChallengeCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $sports     = Sport::orderBy('sort_order')->get();
        $challenge->load('tags', 'subcategories');
        $subcategories = $this->subcategoryOptions();
        return view('admin.challenges.edit', compact('challenge', 'categories', 'sports', 'subcategories'));
    }

    public function update(Request $request, Challenge $challenge)
    {
        $this->normalizeBallRatios($request);

        $data = $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'difficulty'             => ['required', 'in:easy,medium,hard'],
            'status'                 => ['required', 'in:draft,active,archived'],
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

        if ($request->hasFile('hidden_image')) {
            if ($challenge->hidden_image_path) Storage::disk('public')->delete($challenge->hidden_image_path);
            $data['hidden_image_path'] = $request->file('hidden_image')->store('challenges/hidden', 'public');
        }
        if ($request->hasFile('original_image')) {
            if ($challenge->original_image_path) Storage::disk('public')->delete($challenge->original_image_path);
            $data['original_image_path'] = $request->file('original_image')->store('challenges/original', 'public');
        }

        $challenge->update([
            'sport_id'               => $data['sport_id'] ?? $challenge->sport_id,
            'challenge_category_id'  => $data['challenge_category_id'] ?? null,
            'title'                  => $data['title'],
            'difficulty'             => $data['difficulty'],
            'status'                 => $data['status'],
            'ball_x_ratio'           => round((float) $data['ball_x_ratio'], self::BALL_RATIO_DECIMALS),
            'ball_y_ratio'           => round((float) $data['ball_y_ratio'], self::BALL_RATIO_DECIMALS),
            'hidden_image_path'      => $data['hidden_image_path'] ?? $challenge->hidden_image_path,
            'original_image_path'    => $data['original_image_path'] ?? $challenge->original_image_path,
        ]);

        $challenge->tags()->sync($this->resolveTagIds($data['tags'] ?? null));
        $challenge->subcategories()->sync($data['subcategories'] ?? []);

        return redirect('/admin/challenges')->with('success', 'Challenge updated.');
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

        if (!$challenge->isReadyForDaily()) {
            return back()->with('error', "Challenge \"{$challenge->title}\" is not ready for daily use. It must be active and have a hidden image and ball position.");
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

    public function destroy(Challenge $challenge)
    {
        // Prefer archiving over deletion. Hard delete is kept for emergencies only.
        // The UI replaces this button with an Archive action.
        Storage::disk('public')->delete($challenge->hidden_image_path);
        if ($challenge->original_image_path) Storage::disk('public')->delete($challenge->original_image_path);
        $challenge->delete();
        return redirect('/admin/challenges')->with('success', 'Challenge permanently deleted.');
    }
}
