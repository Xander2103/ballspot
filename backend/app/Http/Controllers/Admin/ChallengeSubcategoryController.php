<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChallengeSubcategory;
use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChallengeSubcategoryController extends Controller
{
    public function index(Request $request)
    {
        $subcategories = ChallengeSubcategory::with('sport')
            ->withCount('challenges')
            ->when($request->sport_id, fn ($q, $v) => $q->where('sport_id', $v))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $sports = Sport::orderBy('name')->get();

        return view('admin.subcategories.index', [
            'subcategories' => $subcategories,
            'sports'        => $sports,
            'types'         => ChallengeSubcategory::TYPES,
            'filterSport'   => $request->sport_id,
            'filterType'    => $request->type,
        ]);
    }

    public function create()
    {
        return view('admin.subcategories.create', [
            'sports' => Sport::orderBy('name')->get(),
            'types'  => ChallengeSubcategory::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        ChallengeSubcategory::create([
            'sport_id'    => $data['sport_id'] ?? null,
            'name'        => $data['name'],
            'slug'        => $this->uniqueSlug($data, null),
            'type'        => $data['type'],
            'description' => $data['description'] ?? null,
            'color'       => $data['color'] ?? null,
            'icon'        => $data['icon'] ?? null,
            'is_active'   => $request->boolean('is_active', true),
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);

        return redirect('/admin/subcategories')->with('success', 'Subcategory created.');
    }

    public function edit(ChallengeSubcategory $subcategory)
    {
        return view('admin.subcategories.edit', [
            'subcategory' => $subcategory,
            'sports'      => Sport::orderBy('name')->get(),
            'types'       => ChallengeSubcategory::TYPES,
        ]);
    }

    public function update(Request $request, ChallengeSubcategory $subcategory)
    {
        $data = $this->validated($request);

        $subcategory->update([
            'sport_id'    => $data['sport_id'] ?? null,
            'name'        => $data['name'],
            'slug'        => $this->uniqueSlug($data, $subcategory->id),
            'type'        => $data['type'],
            'description' => $data['description'] ?? null,
            'color'       => $data['color'] ?? null,
            'icon'        => $data['icon'] ?? null,
            'is_active'   => $request->boolean('is_active', false),
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);

        return redirect('/admin/subcategories')->with('success', 'Subcategory updated.');
    }

    /** Toggle active/inactive. Inactive hides from app filters but keeps history + links. */
    public function status(ChallengeSubcategory $subcategory)
    {
        $subcategory->update(['is_active' => ! $subcategory->is_active]);
        $state = $subcategory->is_active ? 'activated' : 'deactivated';

        return redirect('/admin/subcategories')->with('success', "Subcategory {$state}.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'type'        => ['required', Rule::in(ChallengeSubcategory::TYPES)],
            'sport_id'    => ['nullable', 'exists:sports,id'],
            'slug'        => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'color'       => ['nullable', 'string', 'max:20'],
            'icon'        => ['nullable', 'string', 'max:40'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);
    }

    /**
     * Slug from the provided value (or name), made unique within the
     * (sport_id, type) scope by appending -2, -3, … . Ignores the row being
     * updated so re-saving without a rename keeps the same slug.
     */
    private function uniqueSlug(array $data, ?int $ignoreId): string
    {
        $base = Str::slug($data['slug'] ?? '' ?: $data['name']);
        $base = $base !== '' ? $base : 'subcategory';
        $slug = $base;
        $i = 2;

        while (
            ChallengeSubcategory::where('slug', $slug)
                ->where('type', $data['type'])
                ->where('sport_id', $data['sport_id'] ?? null)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
