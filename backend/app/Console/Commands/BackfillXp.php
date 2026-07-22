<?php

namespace App\Console\Commands;

use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\XpService;
use Illuminate\Console\Command;

class BackfillXp extends Command
{
    protected $signature = 'ballspot:backfill-xp
                            {--dry-run : Print what would be created without writing}
                            {--user= : Only backfill this user id}
                            {--force : Reserved — no destructive rebuild is performed}';

    protected $description = 'Create XP ledger events for existing daily + tournament guesses (idempotent)';

    public function handle(XpService $xp): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user');

        if ($this->option('force')) {
            // Never delete/rebuild ledger history — force is intentionally a no-op.
            $this->warn('--force is a no-op: existing XP events are never deleted or rebuilt.');
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no XP events will be written.');
            $this->newLine();
        }

        $created = 0;
        $skipped = 0;

        // --- Daily challenge guesses ---
        $daily = DailyChallengeGuess::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get(['id', 'user_id', 'score']);

        foreach ($daily as $guess) {
            if ($xp->alreadyAwarded($this->userStub($guess->user_id), XpEvent::SOURCE_DAILY_GUESS, $guess->id)) {
                $skipped++;
                continue;
            }
            $this->line("  <fg=green>DAILY</>  user={$guess->user_id} guess={$guess->id} +{$guess->score} XP");
            if (!$dryRun) {
                $xp->awardXp($this->userStub($guess->user_id), XpEvent::SOURCE_DAILY_GUESS, $guess->id, (int) $guess->score, 'Daily challenge completed');
            }
            $created++;
        }

        // --- Tournament round guesses ---
        $tournament = Guess::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get(['id', 'user_id', 'score']);

        foreach ($tournament as $guess) {
            if ($xp->alreadyAwarded($this->userStub($guess->user_id), XpEvent::SOURCE_TOURNAMENT_GUESS, $guess->id)) {
                $skipped++;
                continue;
            }
            $this->line("  <fg=cyan>TOURN</>  user={$guess->user_id} guess={$guess->id} +{$guess->score} XP");
            if (!$dryRun) {
                $xp->awardXp($this->userStub($guess->user_id), XpEvent::SOURCE_TOURNAMENT_GUESS, $guess->id, (int) $guess->score, 'Tournament round completed');
            }
            $created++;
        }

        $this->newLine();
        $verb = $dryRun ? 'would be created' : 'created';
        $this->info("Backfill complete — {$created} XP event(s) {$verb}, {$skipped} already present.");

        return self::SUCCESS;
    }

    /** Lightweight User instance carrying just the id (awardXp only needs ->id). */
    private function userStub(int $userId): User
    {
        $user = new User();
        $user->id = $userId;
        return $user;
    }
}
