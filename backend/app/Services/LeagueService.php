<?php
namespace App\Services;

use App\Models\Challenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use Illuminate\Support\Str;

class LeagueService
{
    public function create(array $data, int $userId): League
    {
        $sport = Sport::where('slug', 'football')->firstOrFail();
        $totalRounds = $data['duration_days'] * $data['rounds_per_day'];

        $league = League::create([
            'name' => $data['name'],
            'join_code' => $this->generateJoinCode(),
            'owner_user_id' => $userId,
            'sport_id' => $sport->id,
            'duration_days' => $data['duration_days'],
            'rounds_per_day' => $data['rounds_per_day'],
            'status' => 'active',
        ]);

        LeagueMember::create([
            'league_id' => $league->id,
            'user_id' => $userId,
            'joined_at' => now(),
        ]);

        $this->generateRounds($league, $totalRounds);

        return $league;
    }

    public function join(string $joinCode, int $userId): League
    {
        $league = League::where('join_code', $joinCode)->firstOrFail();

        LeagueMember::firstOrCreate([
            'league_id' => $league->id,
            'user_id' => $userId,
        ], [
            'joined_at' => now(),
        ]);

        return $league;
    }

    private function generateRounds(League $league, int $total): void
    {
        $challenges = Challenge::where('status', 'active')
            ->where('sport_id', $league->sport_id)
            ->inRandomOrder()
            ->get();

        if ($challenges->isEmpty()) {
            return;
        }

        for ($i = 0; $i < $total; $i++) {
            $challenge = $challenges[$i % $challenges->count()];
            LeagueRound::create([
                'league_id' => $league->id,
                'challenge_id' => $challenge->id,
                'round_number' => $i + 1,
                'status' => 'open',
            ]);
        }
    }

    private function generateJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (League::where('join_code', $code)->exists());
        return $code;
    }
}
