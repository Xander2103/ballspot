<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChallengeController extends Controller
{
    public function index()
    {
        $challenges = Challenge::with('sport')->latest()->paginate(20);
        return view('admin.challenges.index', compact('challenges'));
    }

    public function create()
    {
        return view('admin.challenges.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
            'status' => ['required', 'in:draft,active,archived'],
            'ball_x_ratio' => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio' => ['required', 'numeric', 'between:0,1'],
            'hidden_image' => ['required', 'image', 'max:5120'],
            'original_image' => ['nullable', 'image', 'max:5120'],
        ]);

        $sport = Sport::where('slug', 'football')->firstOrFail();
        $hiddenPath = $request->file('hidden_image')->store('challenges/hidden', 'public');
        $originalPath = $request->hasFile('original_image')
            ? $request->file('original_image')->store('challenges/original', 'public')
            : null;

        Challenge::create([
            'sport_id' => $sport->id,
            'title' => $data['title'],
            'difficulty' => $data['difficulty'],
            'status' => $data['status'],
            'ball_x_ratio' => $data['ball_x_ratio'],
            'ball_y_ratio' => $data['ball_y_ratio'],
            'hidden_image_path' => $hiddenPath,
            'original_image_path' => $originalPath,
        ]);

        return redirect('/admin/challenges')->with('success', 'Challenge created.');
    }

    public function edit(Challenge $challenge)
    {
        return view('admin.challenges.edit', compact('challenge'));
    }

    public function update(Request $request, Challenge $challenge)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
            'status' => ['required', 'in:draft,active,archived'],
            'ball_x_ratio' => ['required', 'numeric', 'between:0,1'],
            'ball_y_ratio' => ['required', 'numeric', 'between:0,1'],
            'hidden_image' => ['nullable', 'image', 'max:5120'],
            'original_image' => ['nullable', 'image', 'max:5120'],
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
            'title' => $data['title'],
            'difficulty' => $data['difficulty'],
            'status' => $data['status'],
            'ball_x_ratio' => $data['ball_x_ratio'],
            'ball_y_ratio' => $data['ball_y_ratio'],
            'hidden_image_path' => $data['hidden_image_path'] ?? $challenge->hidden_image_path,
            'original_image_path' => $data['original_image_path'] ?? $challenge->original_image_path,
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
