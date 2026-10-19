<?php

namespace App\Console\Commands;

use App\Enums\ClubStatus;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Services\Clubs\ClubMembershipService;
use App\Services\Clubs\ClubStandingsService;
use App\Support\AppLog;
use Illuminate\Console\Command;

/**
 * Nightly reconciliation of the denormalised Clubs counters (plan §5.11
 * step 4): clubs.members_count / active_packs_count /
 * live_competitions_count / needs_owner and club_competitions.participants_count.
 * Pure recount from the source tables — idempotent.
 */
class ClubReconcileCounters extends Command
{
    protected $signature = 'ballspot:clubs:reconcile-counters';

    protected $description = 'Recount the denormalised club and club-competition counters';

    public function handle(ClubMembershipService $memberships, ClubStandingsService $standings): int
    {
        $clubs = $competitions = 0;

        Club::where('status', '!=', ClubStatus::Archived->value)->orderBy('id')->each(function (Club $club) use ($memberships, &$clubs) {
            $memberships->refreshCounters($club);
            $clubs++;
        });

        ClubCompetition::orderBy('id')->each(function (ClubCompetition $competition) use ($standings, &$competitions) {
            $standings->refreshParticipants($competition);
            $competitions++;
        });

        $this->line("Reconciled {$clubs} clubs and {$competitions} competitions.");
        AppLog::event('club.counters_reconciled', ['clubs' => $clubs, 'competitions' => $competitions]);

        return self::SUCCESS;
    }
}
