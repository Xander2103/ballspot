<?php

namespace App\Console\Commands;

use App\Enums\CompetitionState;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Services\Clubs\ClubCompetitionService;
use Illuminate\Console\Command;

/**
 * Recompute attempt totals → pack results → entries of club competitions
 * from the persisted attempts and guesses (window + void filters applied)
 * and print the diff (plan §5.10).
 *
 *   php artisan ballspot:clubs:rebuild-standings --dry-run
 *   php artisan ballspot:clubs:rebuild-standings 01JB…ULID
 *   php artisan ballspot:clubs:rebuild-standings 42 --refinalize
 *
 * A finalized competition is only rebuilt with --refinalize, which also
 * rewrites its finishes and writes an audit event. Drafts and cancelled
 * competitions have no standings and are skipped.
 */
class ClubRebuildStandings extends Command
{
    protected $signature = 'ballspot:clubs:rebuild-standings
        {competition? : Competition id or public id (default: every published / finalized / voided competition)}
        {--dry-run : Report the diff only — write nothing}
        {--refinalize : Also rebuild finalized competitions and rewrite their finishes}';

    protected $description = 'Rebuild club competition standings from attempts and guesses (prints a diff)';

    public function handle(ClubCompetitionService $service): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $refinalize = (bool) $this->option('refinalize');
        $arg        = $this->argument('competition');

        $query = ClubCompetition::query()->orderBy('id');
        if ($arg !== null && $arg !== '') {
            $query->where(fn ($q) => ctype_digit((string) $arg) ? $q->whereKey((int) $arg) : $q->where('public_id', $arg));
        } else {
            $query->whereIn('state', [CompetitionState::Published->value, CompetitionState::Finalized->value, CompetitionState::Voided->value]);
        }
        $competitions = $query->get();
        if ($competitions->isEmpty()) {
            $this->error($arg ? "Competition [{$arg}] not found." : 'No competitions to rebuild.');

            return $arg ? self::FAILURE : self::SUCCESS;
        }

        $total = 0;
        foreach ($competitions as $competition) {
            $label = "#{$competition->id} {$competition->name} ({$competition->phase()->value})";
            if (in_array($competition->state, [CompetitionState::Draft, CompetitionState::Cancelled], true)) {
                $this->line("{$label}: skipped (no standings).");
                continue;
            }
            if ($competition->state === CompetitionState::Finalized && !$refinalize && !$dryRun) {
                $this->warn("{$label}: finalized — skipped (use --refinalize to rebuild and rewrite its finishes).");
                continue;
            }

            $result = $service->rebuildStandings($competition, null, $dryRun, ClubAuditEvent::ACTOR_SYSTEM, 'console');
            $count  = count($result['changes']);
            $total += $count;
            $this->line("{$label}: {$result['players']} players, {$count} " . ($count === 1 ? 'difference' : 'differences') . ($dryRun ? ' (dry run)' : ''));
            foreach (array_slice($result['changes'], 0, 50) as $c) {
                $this->line(sprintf('  %s user:%s pack:%s %s %s → %s', $c['type'], $c['user_id'] ?? '-', $c['pack_id'] ?? '-', $c['field'],
                    json_encode($c['from']), json_encode($c['to'])));
            }
            if ($count > 50) {
                $this->line('  … ' . ($count - 50) . ' more');
            }
            if (!$dryRun && $competition->state === CompetitionState::Finalized) {
                $this->line('  finishes rewritten.');
            }
        }

        $this->info(($dryRun ? 'Dry run: ' : 'Done: ') . "{$total} " . ($total === 1 ? 'difference' : 'differences') . '.');

        return self::SUCCESS;
    }
}
