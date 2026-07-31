<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Services\DailyChallengeScheduler;
use Illuminate\Http\Request;

class DailyChallengeAdminController extends Controller
{
    public function index()
    {
        $dailyChallenges = DailyChallenge::with('challenge')
            ->withCount('guesses')
            ->orderBy('challenge_date', 'desc')
            ->paginate(20);

        $upcoming = DailyChallenge::with('challenge')
            ->where('challenge_date', '>=', today()->toDateString())
            ->orderBy('challenge_date')
            ->take(14)
            ->get();

        $readyCount = Challenge::where('status', 'active')
            ->get()
            ->filter->isReadyForDaily()
            ->count();

        $showReadyWarning = $readyCount < 7;

        return view('admin.daily.index', compact(
            'dailyChallenges', 'upcoming', 'readyCount', 'showReadyWarning'
        ));
    }

    public function create(DailyChallengeScheduler $scheduler)
    {
        $challenges = Challenge::where('status', 'active')->orderBy('title')->get();

        $usedIds      = $scheduler->usedChallengeIds();
        $selectable   = $challenges
            ->filter(fn (Challenge $c) => $c->isReadyForDaily() && !in_array($c->id, $usedIds, true))
            ->values();
        $nextFreeDate = $scheduler->nextFreeDate()->toDateString();

        return view('admin.daily.create', compact('challenges', 'selectable', 'usedIds', 'nextFreeDate'));
    }

    /**
     * Schedule one or many challenges onto consecutive free daily dates.
     *
     * A single selection is just a batch of one, so this replaces the old
     * one-challenge-per-submit form without changing the route.
     */
    public function store(Request $request, DailyChallengeScheduler $scheduler)
    {
        $data = $request->validate([
            'challenge_ids'   => ['required', 'array', 'min:1'],
            'challenge_ids.*' => ['integer', 'exists:challenges,id'],
            'selection_order' => ['nullable', 'string', 'max:5000'],
            'start_date'      => ['nullable', 'date'],
            'status'          => ['required', 'in:scheduled,active,archived'],
        ]);

        $ids = $this->applySelectionOrder($data['challenge_ids'], $data['selection_order'] ?? null);

        $result = $scheduler->schedule($ids, $data['start_date'] ?? null, $data['status']);

        return redirect()
            ->route('admin.daily.index')
            ->with($this->flashFor($result));
    }

    public function updateStatus(Request $request, DailyChallenge $dailyChallenge)
    {
        $data = $request->validate(['status' => 'required|in:scheduled,active,archived']);
        $dailyChallenge->update($data);
        return redirect()->route('admin.daily.index')->with('success', 'Status updated.');
    }

    /**
     * Checkboxes always post in DOM order, so the form carries the admin's real
     * click order in a hidden field. Fall back to checkbox order without JS.
     *
     * @param  array<int, int|string>  $checkedIds
     * @return array<int, int>
     */
    private function applySelectionOrder(array $checkedIds, ?string $selectionOrder): array
    {
        $checked = array_map('intval', $checkedIds);

        if ($selectionOrder === null || trim($selectionOrder) === '') {
            return $checked;
        }

        $ordered = array_values(array_filter(
            array_map('intval', explode(',', $selectionOrder)),
            fn (int $id) => in_array($id, $checked, true)
        ));

        // Anything the hidden field missed keeps its checkbox position at the end.
        $remaining = array_values(array_diff($checked, $ordered));

        return array_merge($ordered, $remaining);
    }

    /**
     * Build the created/skipped summary shown after a batch run.
     *
     * @param  array{created: array<int, array{challenge: Challenge, date: string}>, skipped: array<int, array{challenge: ?Challenge, id: int, reason: string}>}  $result
     * @return array<string, string>
     */
    private function flashFor(array $result): array
    {
        $created = $result['created'];
        $skipped = $result['skipped'];

        $flash = [];

        if (count($created) === 1) {
            $flash['success'] = "1 daily challenge scheduled for {$created[0]['date']}.";
        } elseif (count($created) > 1) {
            $first = $created[0]['date'];
            $last  = $created[count($created) - 1]['date'];
            $flash['success'] = count($created) . " daily challenges scheduled ({$first} - {$last}).";
        }

        if ($skipped !== []) {
            $reasons = array_map(function (array $entry) {
                $title = $entry['challenge']?->title ?? "Challenge #{$entry['id']}";

                return match ($entry['reason']) {
                    DailyChallengeScheduler::SKIP_ALREADY_USED => "\"{$title}\" was already used as a daily",
                    DailyChallengeScheduler::SKIP_NOT_READY    => "\"{$title}\" is not active or not ready for daily use",
                    default                                    => "\"{$title}\" could not be found",
                };
            }, $skipped);

            $message = count($skipped) . ' skipped: ' . implode('; ', $reasons) . '.';

            // Nothing scheduled at all is a failure, not a partial success.
            $flash[$created === [] ? 'error' : 'warning'] = $message;
        }

        if ($flash === []) {
            $flash['error'] = 'Nothing was scheduled.';
        }

        return $flash;
    }
}
