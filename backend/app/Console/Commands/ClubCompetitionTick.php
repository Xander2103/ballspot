<?php

namespace App\Console\Commands;

use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionState;
use App\Models\ClubCompetition;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionNotifier;
use App\Services\Clubs\ClubCompetitionRulesGuard;
use App\Support\AppLog;
use Illuminate\Console\Command;

/**
 * Club competition housekeeping (plan §5.11), every minute, idempotent:
 *  1. lock the rules of published competitions whose starts_at passed
 *     (players starting first lock them lazily anyway);
 *  2. expire active attempts of competitions that ended or were voided;
 *  3. finalize ended competitions → club_competition_finishes (no global XP)
 *     — the finalize sends the members' "final standings" push;
 *  4. send the "competition started" push (Wave F) for running competitions
 *     that have not had it yet, whoever locked their rules (at most once).
 * A failure on one competition is logged and never stops the others.
 */
class ClubCompetitionTick extends Command
{
    protected $signature = 'ballspot:clubs:competition-tick';

    protected $description = 'Lock rules at start, expire attempts of ended club competitions and finalize them';

    public function handle(ClubCompetitionRulesGuard $guard, ClubCompetitionCloseService $close, ClubCompetitionNotifier $notifier): int
    {
        $now = now();
        $locked = $expired = $finalized = $failed = $notified = 0;

        $guarded = function (ClubCompetition $competition, string $stage, \Closure $fn) use (&$failed) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failed++;
                AppLog::error('club.competition_tick_failed', ['competition_id' => $competition->id, 'stage' => $stage, 'exception' => class_basename($e)]);
                report($e);
            }
        };

        ClubCompetition::where('state', CompetitionState::Published->value)
            ->whereNull('rules_locked_at')
            ->where('starts_at', '<=', $now)
            ->orderBy('id')
            ->each(function (ClubCompetition $c) use ($guard, $guarded, &$locked) {
                $guarded($c, 'lock', function () use ($guard, $c, &$locked) {
                    $locked += $guard->lock($c) ? 1 : 0;
                });
            });

        ClubCompetition::query()
            ->whereHas('attempts', fn ($q) => $q->where('status', CompetitionAttemptStatus::Active->value))
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('state', CompetitionState::Published->value)->where('ends_at', '<=', $now))
                ->orWhereIn('state', [CompetitionState::Voided->value, CompetitionState::Cancelled->value, CompetitionState::Finalized->value]))
            ->orderBy('id')
            ->each(function (ClubCompetition $c) use ($close, $guarded, &$expired) {
                $guarded($c, 'expire', function () use ($close, $c, &$expired) {
                    $expired += $close->expireAttempts($c);
                });
            });

        ClubCompetition::where('state', CompetitionState::Published->value)
            ->where('ends_at', '<=', $now)
            ->orderBy('id')
            ->each(function (ClubCompetition $c) use ($close, $guarded, &$finalized) {
                $guarded($c, 'finalize', function () use ($close, $c, &$finalized) {
                    $finalized += $close->finalize($c)['finalized'] ? 1 : 0;
                });
            });

        // The notifier never throws (failures are logged and swallowed).
        foreach ($notifier->dueForStart() as $c) {
            $notified += $notifier->started($c)['claimed'] ? 1 : 0;
        }

        $this->line("Locked: {$locked} · expired attempts: {$expired} · finalized: {$finalized} · start pushes: {$notified}" . ($failed ? " · failed: {$failed}" : ''));
        if ($locked + $expired + $finalized + $failed + $notified > 0) {
            AppLog::event('club.competition_tick', compact('locked', 'expired', 'finalized', 'failed', 'notified'));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
