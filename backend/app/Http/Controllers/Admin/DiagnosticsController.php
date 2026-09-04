<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ContentBackupService;
use App\Services\DailyHistoryClearException;
use App\Services\DailyHistoryClearService;
use App\Services\DiagnosticsService;
use App\Support\AppLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/diagnostics — read-only operational status for beta support.
 * Admin-only (route middleware). Never executes shell commands, never
 * mutates data, never renders secrets (see DiagnosticsService).
 *
 * POST /admin/diagnostics/clear-daily-history — the one deliberately
 * destructive pre-launch tool on the page (see DailyHistoryClearService).
 * POST + CSRF + admin + PIN + acknowledgement; backup first; transaction.
 */
class DiagnosticsController extends Controller
{
    public function index(DiagnosticsService $diagnostics)
    {
        return view('admin.diagnostics.index', [
            'd'               => $diagnostics->snapshot(),
            'acknowledgement' => DailyHistoryClearService::ACKNOWLEDGEMENT,
        ]);
    }

    public function clearDailyHistory(
        Request $request,
        DailyHistoryClearService $service,
        ContentBackupService $backup,
    ): RedirectResponse {
        $adminId = (int) $request->user()->id;
        $back    = redirect()->route('admin.diagnostics.index');

        // The PIN is validated by hand so its value never reaches the
        // validator's error bag / old-input session (and never a log line).
        $pin = $request->input('pin');
        $ack = $request->boolean('acknowledge');

        if (!is_string($pin) || trim($pin) === '') {
            AppLog::warn('daily_history_clear.denied', ['admin_id' => $adminId, 'reason' => 'missing_pin']);

            return $back->withErrors(['pin' => 'Enter the confirmation PIN to clear Daily history.']);
        }

        if (!$ack) {
            AppLog::warn('daily_history_clear.denied', ['admin_id' => $adminId, 'reason' => 'missing_acknowledgement']);

            return $back->withErrors(['acknowledge' => 'Tick "' . DailyHistoryClearService::ACKNOWLEDGEMENT . '" to continue.']);
        }

        if (!$service->pinMatches($pin)) {
            AppLog::warn('daily_history_clear.denied', ['admin_id' => $adminId, 'reason' => 'wrong_pin']);

            return $back->with('error', 'The confirmation PIN is not correct. Nothing was changed.');
        }

        try {
            $result = $service->clear($backup, $adminId);
        } catch (DailyHistoryClearException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('success', sprintf(
            'Daily history cleared: %d daily challenge(s) and %d guess(es) removed; %d challenge(s) are no longer "Used as Daily". A backup was written to %s first. Run Daily scheduling again or use Admin → Daily to schedule new dailies.',
            $result['deleted_daily_challenges'],
            $result['deleted_daily_challenge_guesses'],
            $result['affected_challenges'],
            'backups/ballspot-content/' . basename($result['backup_path']),
        ));
    }
}
