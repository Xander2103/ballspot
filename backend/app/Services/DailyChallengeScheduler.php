<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigns challenges to free daily-challenge dates.
 *
 * Two product rules drive everything here:
 *   1. One daily challenge per date (also enforced by a unique index on
 *      daily_challenges.challenge_date).
 *   2. A challenge is used as a daily at most once, ever. This one lives in
 *      application logic only — there is deliberately no unique index on
 *      challenge_id, so the CLI's --allow-reuse escape hatch keeps working.
 */
class DailyChallengeScheduler
{
    public const SKIP_ALREADY_USED = 'already_used';
    public const SKIP_NOT_READY    = 'not_ready';
    public const SKIP_MISSING      = 'missing';
    public const SKIP_WRONG_POOL   = 'wrong_pool';

    /**
     * IDs of every challenge that has ever been scheduled as a daily.
     *
     * @return array<int, int>
     */
    public function usedChallengeIds(): array
    {
        return DailyChallenge::distinct()->pluck('challenge_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Active, ready, daily-pool (daily|general) challenges that have never
     * been used as a daily.
     */
    public function eligibleChallenges(): Collection
    {
        $used = $this->usedChallengeIds();

        return Challenge::dailyPool()
            ->orderBy('title')
            ->get()
            ->filter(fn (Challenge $c) => $c->isDailyEligible() && !in_array($c->id, $used, true))
            ->values();
    }

    /**
     * The first date on or after $from that has no daily challenge yet.
     */
    public function nextFreeDate(CarbonInterface|string|null $from = null): Carbon
    {
        $cursor   = $this->toDate($from);
        $occupied = $this->occupiedDatesFrom($cursor);

        while (isset($occupied[$cursor->toDateString()])) {
            $cursor->addDay();
        }

        return $cursor;
    }

    /**
     * Schedule the given challenges, in the given order, onto consecutive free dates.
     *
     * Each entry is either created or skipped with a reason; nothing partially
     * applies, because the whole run is wrapped in a transaction.
     *
     * @param  array<int, int|string>  $challengeIds  ordered as selected by the admin
     * @return array{created: array<int, array{challenge: Challenge, date: string}>, skipped: array<int, array{challenge: ?Challenge, id: int, reason: string}>}
     */
    public function schedule(array $challengeIds, CarbonInterface|string|null $startDate = null, string $status = 'scheduled'): array
    {
        // A challenge ticked twice in one submission is still one challenge.
        $orderedIds = array_values(array_unique(array_map('intval', $challengeIds)));

        $challenges = Challenge::whereIn('id', $orderedIds)->get()->keyBy('id');

        $cursor   = $this->toDate($startDate);
        $occupied = $this->occupiedDatesFrom($cursor);
        $used     = array_flip($this->usedChallengeIds());

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($orderedIds, $challenges, $status, &$cursor, &$occupied, &$used, &$created, &$skipped) {
            foreach ($orderedIds as $id) {
                /** @var Challenge|null $challenge */
                $challenge = $challenges->get($id);

                if (!$challenge) {
                    $skipped[] = ['challenge' => null, 'id' => $id, 'reason' => self::SKIP_MISSING];
                    continue;
                }

                if (isset($used[$id])) {
                    $skipped[] = ['challenge' => $challenge, 'id' => $id, 'reason' => self::SKIP_ALREADY_USED];
                    continue;
                }

                if (!$challenge->isReadyForDaily()) {
                    $skipped[] = ['challenge' => $challenge, 'id' => $id, 'reason' => self::SKIP_NOT_READY];
                    continue;
                }

                if (!$challenge->isInDailyPool()) {
                    $skipped[] = ['challenge' => $challenge, 'id' => $id, 'reason' => self::SKIP_WRONG_POOL];
                    continue;
                }

                while (isset($occupied[$cursor->toDateString()])) {
                    $cursor->addDay();
                }

                $date = $cursor->toDateString();

                DailyChallenge::create([
                    'challenge_id'   => $challenge->id,
                    'challenge_date' => $date,
                    'status'         => $status,
                ]);

                $occupied[$date] = true;
                $used[$id]       = true;
                $created[]       = ['challenge' => $challenge, 'date' => $date];

                $cursor->addDay();
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Dates from $from onwards that already hold a daily challenge, as a lookup map.
     *
     * @return array<string, true>
     */
    private function occupiedDatesFrom(CarbonInterface $from): array
    {
        return DailyChallenge::where('challenge_date', '>=', $from->toDateString())
            ->pluck('challenge_date')
            ->mapWithKeys(fn ($date) => [
                ($date instanceof CarbonInterface ? $date->toDateString() : (string) $date) => true,
            ])
            ->all();
    }

    private function toDate(CarbonInterface|string|null $value): Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::parse($value->toDateString());
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->startOfDay();
        }

        return Carbon::today();
    }
}
