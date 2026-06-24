<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use Illuminate\Http\Request;

class DailyChallengeAdminController extends Controller
{
    public function index()
    {
        $dailyChallenges = DailyChallenge::with('challenge')
            ->withCount('guesses')
            ->orderBy('challenge_date', 'desc')
            ->paginate(20);
        return view('admin.daily.index', compact('dailyChallenges'));
    }

    public function create()
    {
        $challenges = Challenge::where('status', 'active')->orderBy('title')->get();
        return view('admin.daily.create', compact('challenges'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'challenge_id'   => 'required|exists:challenges,id',
            'challenge_date' => 'required|date|unique:daily_challenges,challenge_date',
            'status'         => 'required|in:scheduled,active,archived',
        ]);
        DailyChallenge::create($data);
        return redirect()->route('admin.daily.index')->with('success', 'Daily challenge created.');
    }

    public function updateStatus(Request $request, DailyChallenge $dailyChallenge)
    {
        $data = $request->validate(['status' => 'required|in:scheduled,active,archived']);
        $dailyChallenge->update($data);
        return redirect()->route('admin.daily.index')->with('success', 'Status updated.');
    }
}
