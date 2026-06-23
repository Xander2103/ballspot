<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\ChallengeCategory;
use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChallengeCategoryController extends Controller
{
    public function index()
    {
        $categories = ChallengeCategory::with('sport')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.categories.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);

        $sport = Sport::where('slug', 'football')->firstOrFail();

        ChallengeCategory::create([
            'sport_id'    => $sport->id,
            'name'        => $data['name'],
            'slug'        => Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'sort_order'  => $data['sort_order'] ?? 0,
            'is_active'   => $request->boolean('is_active', true),
        ]);

        return redirect('/admin/categories')->with('success', 'Category created.');
    }

    public function edit(ChallengeCategory $category)
    {
        return view('admin.categories.edit', compact('category'));
    }

    public function update(Request $request, ChallengeCategory $category)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $category->update([
            'name'        => $data['name'],
            'slug'        => Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'sort_order'  => $data['sort_order'] ?? 0,
            'is_active'   => $request->boolean('is_active', false),
        ]);

        return redirect('/admin/categories')->with('success', 'Category updated.');
    }

    public function toggle(ChallengeCategory $category)
    {
        $category->update(['is_active' => !$category->is_active]);
        $state = $category->is_active ? 'activated' : 'deactivated';
        return redirect('/admin/categories')->with('success', "Category {$state}.");
    }

    public function destroy(ChallengeCategory $category)
    {
        $category->challenges()->update(['challenge_category_id' => null]);
        $category->delete();
        return redirect('/admin/categories')->with('success', 'Category deleted. Challenges have been uncategorised.');
    }
}
