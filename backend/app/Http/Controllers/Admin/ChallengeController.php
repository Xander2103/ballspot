<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChallengeController extends Controller
{
    public function index(Request $request)
    {
        $challenges = Challenge::with(['sport', 'category'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->difficulty, fn ($q, $v) => $q->where('difficulty', $v))
            ->when($request->category, fn ($q, $v) => $q->where('challenge_category_id', $v))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $categories = ChallengeCategory::orderBy('sort_order')->orderBy('name')->get();
        return view('admin.challenges.index', compact('challenges', 'categories'));
    }

    public function create()
    {
        $categories = ChallengeCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        return view('admin.challenges.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'difficulty'             => ['required', 'in:easy,medium,hard'],
            'status'                 => ['required', 'in:draft,active,archived'],
            'challenge_category_id'  => ['nullable', 'exists:challenge_categories,id'],
            'ball_x_ratio'           => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio'           => ['required', 'numeric', 'between:0,1'],
            'hidden_image'           => ['required', 'image', 'max:5120'],
            'original_image'         => ['nullable', 'image', 'max:5120'],
        ]);

        $sport      = Sport::where('slug', 'football')->firstOrFail();
        $hiddenPath = $request->file('hidden_image')->store('challenges/hidden', 'public');
        $originalPath = $request->hasFile('original_image')
            ? $request->file('original_image')->store('challenges/original', 'public')
            : null;

        Challenge::create([
            'sport_id'               => $sport->id,
            'challenge_category_id'  => $data['challenge_category_id'] ?? null,
            'title'                  => $data['title'],
            'difficulty'             => $data['difficulty'],
            'status'                 => $data['status'],
            'ball_x_ratio'           => $data['ball_x_ratio'],
            'ball_y_ratio'           => $data['ball_y_ratio'],
            'hidden_image_path'      => $hiddenPath,
            'original_image_path'    => $originalPath,
        ]);

        return redirect('/admin/challenges')->with('success', 'Challenge created.');
    }

    public function edit(Challenge $challenge)
    {
        $categories = ChallengeCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        return view('admin.challenges.edit', compact('challenge', 'categories'));
    }

    public function update(Request $request, Challenge $challenge)
    {
        $data = $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'difficulty'             => ['required', 'in:easy,medium,hard'],
            'status'                 => ['required', 'in:draft,active,archived'],
            'challenge_category_id'  => ['nullable', 'exists:challenge_categories,id'],
            'ball_x_ratio'           => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio'           => ['required', 'numeric', 'between:0,1'],
            'hidden_image'           => ['nullable', 'image', 'max:5120'],
            'original_image'         => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('hidden_image')) {
            Storage::disk('public')->delete($challenge->hidden_image_path);
            $data['hidden_image_path'] = $request->file('hidden_image')->store('challenges/hidden', 'public');
        }
        if ($request->hasFile('original_image')) {
            if ($challenge->original_image_path) Storage::disk('public')->delete($challenge->original_image_path);
            $data['original_image_path'] = $request->file('original_image')->store('challenges/original', 'public');
        }

        $challenge->update([
            'challenge_category_id'  => $data['challenge_category_id'] ?? null,
            'title'                  => $data['title'],
            'difficulty'             => $data['difficulty'],
            'status'                 => $data['status'],
            'ball_x_ratio'           => $data['ball_x_ratio'],
            'ball_y_ratio'           => $data['ball_y_ratio'],
            'hidden_image_path'      => $data['hidden_image_path'] ?? $challenge->hidden_image_path,
            'original_image_path'    => $data['original_image_path'] ?? $challenge->original_image_path,
        ]);

        return redirect('/admin/challenges')->with('success', 'Challenge updated.');
    }

    public function destroy(Challenge $challenge)
    {
        Storage::disk('public')->delete($challenge->hidden_image_path);
        if ($challenge->original_image_path) Storage::disk('public')->delete($challenge->original_image_path);
        $challenge->delete();
        return redirect('/admin/challenges')->with('success', 'Challenge deleted.');
    }
}
