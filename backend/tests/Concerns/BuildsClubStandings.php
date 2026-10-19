<?php

namespace Tests\Concerns;

use App\Enums\CompetitionState;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionEntry;
use App\Models\PackAttempt;
use App\Models\User;

/**
 * Fixtures for the Wave F CSV export suites: a club leaderboard from normal
 * pack attempts and a competition's standings from entry rows, with
 * usernames that try spreadsheet formula injection. Uses CreatesClubs.
 */
trait BuildsClubStandings
{
    /** @return array{pack: ChallengePack, users: array<string, User>} leaderboard: alice 300, =cmd 200, @evil 100 */
    protected function seedLeaderboard(Club $club): array
    {
        $pack  = $this->makeClubPack($club, 3);
        $users = [];
        foreach (['alice' => 300, '=HYPERLINK("http://x")' => 200, '@evil' => 100] as $name => $score) {
            $user = User::factory()->create(['username' => $name, 'email' => str_replace(['=', '"', '(', ')', ':', '/', '@'], '', $name) . '@secret-mail.test']);
            $this->addMember($club, $user);
            PackAttempt::create([
                'user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => 'completed',
                'started_at' => now()->subHour(), 'completed_at' => now()->subMinutes(30), 'total_score' => $score, 'current_index' => 3,
            ]);
            $users[$name] = $user;
        }

        return ['pack' => $pack, 'users' => $users];
    }

    /** A finalized-looking competition with entries: bob 500 (1st), -carl 400 (2nd), +dora 400 (2nd, later). */
    protected function seedCompetitionStandings(Club $club, string $name = 'Autumn Cup: "Final"', string $suffix = ''): ClubCompetition
    {
        $competition = $this->makeCompetition($club, [$this->makeClubPack($club, 1)], [
            'name' => $name, 'state' => CompetitionState::Published, 'starts_at' => now()->subDays(2), 'ends_at' => now()->addDay(),
        ]);
        $at = now()->subDay();
        foreach ([['bob', 500, 2, 2, 0], ['-carl', 400, 1, 3, 10], ['+dora', 400, 1, 3, 10]] as [$username, $score, $packs, $attempts, $minutes]) {
            $user = User::factory()->create(['username' => $username . $suffix, 'email' => ltrim($username, '-+') . $suffix . '@secret-mail.test']);
            $this->addMember($club, $user);
            ClubCompetitionEntry::factory()->create([
                'club_competition_id' => $competition->id, 'user_id' => $user->id, 'total_score' => $score,
                'packs_counted' => $packs, 'packs_completed' => $packs, 'attempts_used' => $attempts,
                'score_reached_at' => $at->copy()->addMinutes($minutes),
            ]);
        }

        return $competition;
    }

    /** CSV body → rows (BOM stripped). @return list<list<string>> */
    protected function csvRows(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }
}
