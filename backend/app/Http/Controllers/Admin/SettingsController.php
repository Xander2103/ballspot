<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameplaySetting;
use App\Services\AdminSettingsOverviewService;
use Illuminate\Http\Request;

/**
 * Admin settings: one editable gameplay setting (tournament challenge
 * cooldown) plus a read-only overview of the competition, launch, growth and
 * anti-abuse configuration (AdminSettingsOverviewService — never secrets).
 */
class SettingsController extends Controller
{
    public function index(AdminSettingsOverviewService $overview)
    {
        return view('admin.settings.index', [
            'cooldownDays'    => GameplaySetting::tournamentChallengeCooldownDays(),
            'cooldownDefault' => (int) config('ballspot.tournaments.challenge_cooldown_days', 90),
            'cooldownMin'     => GameplaySetting::COOLDOWN_MIN_DAYS,
            'cooldownMax'     => GameplaySetting::COOLDOWN_MAX_DAYS,
            's'               => $overview->overview(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'tournament_challenge_cooldown_days' => [
                'required', 'integer',
                'min:' . GameplaySetting::COOLDOWN_MIN_DAYS,
                'max:' . GameplaySetting::COOLDOWN_MAX_DAYS,
            ],
        ]);

        GameplaySetting::put(
            GameplaySetting::TOURNAMENT_CHALLENGE_COOLDOWN_DAYS,
            (int) $data['tournament_challenge_cooldown_days']
        );

        return redirect()->route('admin.settings.index')->with('success', 'Settings saved.');
    }
}
