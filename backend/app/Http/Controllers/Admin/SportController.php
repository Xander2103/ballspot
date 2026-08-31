<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Sport;
use App\Services\SportReadinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SportController extends Controller
{
    public function __construct(private SportReadinessService $readiness) {}

    public function index()
    {
        $sports = Sport::withCount('challenges')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $readiness = $sports->mapWithKeys(fn (Sport $s) => [$s->id => $this->readiness->for($s)]);

        return view('admin.sports.index', compact('sports', 'readiness'));
    }

    public function create()
    {
        return view('admin.sports.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        Sport::create([
            'name'            => $data['name'],
            'slug'            => $data['slug'] ?: $this->uniqueSlug($data['name'], null),
            'emoji'           => $data['emoji'] ?? '⚽',
            'object_name'     => $data['object_name'] ?: 'ball',
            'primary_color'   => $data['primary_color'] ?: '#00c853',
            'sort_order'      => $data['sort_order'] ?? 0,
            // New sports launch as "coming soon" until content is ready.
            'status'          => $data['status'] ?? Sport::STATUS_COMING_SOON,
        ]);

        return redirect('/admin/sports')->with('success', "\u{201C}{$data['name']}\u{201D} created.");
    }

    public function edit(Sport $sport)
    {
        return view('admin.sports.edit', compact('sport'));
    }

    public function update(Request $request, Sport $sport)
    {
        $data = $this->validated($request, $sport->id);

        $status = $data['status'] ?? $sport->status;
        $slug   = $data['slug'] ?: $this->uniqueSlug($data['name'], $sport->id);

        // Football is the live launch experience — it cannot be renamed away
        // or taken offline from the edit form either.
        if ($sport->slug === 'football') {
            $slug = 'football';
            if ($status !== Sport::STATUS_ACTIVE) {
                $status = Sport::STATUS_ACTIVE;
                session()->flash('error', 'Football must stay active — other changes were saved.');
            }
        }

        $sport->update([
            'name'          => $data['name'],
            'slug'          => $slug,
            'emoji'         => $data['emoji'] ?? $sport->emoji,
            'object_name'   => $data['object_name'] ?: $sport->object_name,
            'primary_color' => $data['primary_color'] ?: $sport->primary_color,
            'sort_order'    => $data['sort_order'] ?? $sport->sort_order,
            'status'        => $status,
        ]);

        return redirect('/admin/sports')->with('success', "\u{201C}{$sport->name}\u{201D} saved.");
    }

    /**
     * Delete a sport only when it has no content. A sport with challenges
     * must be hidden instead — deleting it would orphan or destroy content.
     */
    public function destroy(Sport $sport)
    {
        if ($sport->slug === 'football') {
            return redirect('/admin/sports')->with('error', 'Football cannot be deleted.');
        }

        if ($sport->challenges()->exists()) {
            return redirect('/admin/sports')->with('error',
                "\u{201C}{$sport->name}\u{201D} has challenges and cannot be deleted. Set it to Hidden instead.");
        }

        try {
            $sport->delete();
        } catch (\Illuminate\Database\QueryException) {
            return redirect('/admin/sports')->with('error',
                "\u{201C}{$sport->name}\u{201D} is still referenced by other content. Set it to Hidden instead.");
        }

        return redirect('/admin/sports')->with('success', "\u{201C}{$sport->name}\u{201D} deleted.");
    }

    /** De-dupe an auto-generated slug so it never trips the unique index. */
    private function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: 'sport';
        $slug = $base;
        $i = 2;
        while (
            Sport::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function validated(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:60'],
            'slug'          => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('sports', 'slug')->ignore($ignoreId)],
            'emoji'         => ['nullable', 'string', 'max:8'],
            'object_name'   => ['nullable', 'string', 'max:30'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'    => ['nullable', 'integer', 'min:0', 'max:1000'],
            'status'        => ['nullable', 'in:' . implode(',', Sport::STATUSES)],
        ]);
    }

    /**
     * Set a sport's availability status: active / coming_soon / hidden.
     * Football is protected — it must stay active (the live experience).
     */
    public function updateStatus(Request $request, Sport $sport)
    {
        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', Sport::STATUSES)],
        ]);

        if ($sport->slug === 'football' && $data['status'] !== Sport::STATUS_ACTIVE) {
            return redirect('/admin/sports')->with('error', 'Football must stay active.');
        }

        $sport->update(['status' => $data['status']]);

        return redirect('/admin/sports')->with('success', "\u{201C}{$sport->name}\u{201D} is now {$this->label($data['status'])}.");
    }

    private function label(string $status): string
    {
        return match ($status) {
            Sport::STATUS_ACTIVE      => 'Active',
            Sport::STATUS_COMING_SOON => 'Coming soon',
            Sport::STATUS_HIDDEN      => 'Hidden',
            default                   => $status,
        };
    }
}
