<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChallengePackController extends Controller
{
    public function index()
    {
        $packs = ChallengePack::with('sport')
            ->withCount('challenges')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25);

        return view('admin.packs.index', compact('packs'));
    }

    public function create()
    {
        return view('admin.packs.create', [
            'sports' => Sport::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $pack = ChallengePack::create([
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
                ? $request->file('cover_image')->store('packs/covers', 'public')
                : null,
        ]);

        $this->syncCompletionTrophy($pack, $request->boolean('award_completion_trophy'));

        \App\Support\AppLog::event('admin.pack_created', [
            'pack_id' => $pack->id, 'status' => $pack->status, 'visibility' => $pack->visibility,
            'challenge_count' => 0, 'trophy' => $pack->fresh()->completion_badge_id !== null,
            'admin_user_id' => $request->user()->id,
        ]);

        return redirect('/admin/packs/' . $pack->id . '/edit')
            ->with('success', 'Pack created. Now add challenges below.');
    }

    public function edit(ChallengePack $pack)
    {
        $pack->load('challenges', 'sport');

        // Candidate challenges to add: scoped to the pack's sport when set.
        // Pack-pool content lists first — packs are curated from the Pack pool;
        // other pools stay selectable for explicit admin picks.
        $available = Challenge::with('sport')
            ->when($pack->sport_id, fn ($q) => $q->where('sport_id', $pack->sport_id))
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

        if ($request->hasFile('cover_image')) {
            if ($pack->cover_image_path) {
                Storage::disk('public')->delete($pack->cover_image_path);
            }
            $data['cover_image_path'] = $request->file('cover_image')->store('packs/covers', 'public');
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
        \App\Support\AppLog::event('admin.pack_updated', [
            'pack_id' => $fresh->id, 'status' => $fresh->status, 'visibility' => $fresh->visibility,
            'challenge_count' => $fresh->challenges()->count(), 'trophy' => $fresh->completion_badge_id !== null,
            'admin_user_id' => $request->user()->id,
        ]);

        return redirect('/admin/packs')->with('success', $this->readinessNote($fresh));
    }

    /** Quick status/visibility change from the index. */
    public function status(Request $request, ChallengePack $pack)
    {
        $data = $request->validate([
            'status'     => ['nullable', Rule::in(ChallengePack::STATUSES)],
            'visibility' => ['nullable', Rule::in(ChallengePack::VISIBILITIES)],
        ]);

        $pack->update(array_filter([
            'status'     => $data['status'] ?? null,
            'visibility' => $data['visibility'] ?? null,
        ]));

        $fresh = $pack->fresh();
        \App\Support\AppLog::event('admin.pack_updated', [
            'pack_id' => $fresh->id, 'status' => $fresh->status, 'visibility' => $fresh->visibility,
            'challenge_count' => $fresh->challenges()->count(), 'trophy' => $fresh->completion_badge_id !== null,
            'admin_user_id' => $request->user()->id,
        ]);

        return redirect('/admin/packs')->with('success', $this->readinessNote($fresh));
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
