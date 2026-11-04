<?php

namespace App\Console\Commands;

use App\Models\Badge;
use App\Models\CompetitionFinish;
use App\Models\DailyChallengeGuess;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\CompetitionPeriodService;
use App\Services\RuntimeFingerprintService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Proves which competition period the PHONE receives, next to what the CLI
 * sees — for "tinker says monthly, the app says Weekly".
 *
 *   php artisan ballspot:competition-period-audit
 *       CLI fingerprint only (read-only).
 *
 *   php artisan ballspot:competition-period-audit --user=42
 *       Also creates a temporary API token for YOUR account #42, calls
 *       GET {APP_URL}/api/daily/stats and /api/daily/leaderboard/weekly over
 *       real HTTP (public hostname → nginx → php-fpm, exactly like the app),
 *       prints only the `period` blocks and deletes the token again.
 *       Asks first; never prints the token.
 *
 *       Then checks that the LABEL matches the real SCORING: both endpoints
 *       carry the same period, the window is the current calendar month (or
 *       ISO week) and the leaderboard totals equal the database sums over that
 *       window, not over the other one. Prints ranks, scores and dates only —
 *       no names, e-mails, user ids or tokens.
 *
 *       Also counts historical competition finishes per period type, so old
 *       weekly results are visible as weekly.
 *
 * Compare the CLI block with the "Runtime (this web process)" card on
 * /admin/settings, which shows the same fields from inside php-fpm.
 */
class CompetitionPeriodAudit extends Command
{
    protected $signature = 'ballspot:competition-period-audit
        {--user= : User ID of your own account, to fetch the real HTTP response}
        {--base-url= : API base URL to call (default: APP_URL/api)}
        {--yes : Skip the confirmation for the temporary token}';

    protected $description = 'Compare the CLI competition period with the period the API really returns over HTTP';

    public const TOKEN_NAME = 'period-audit (temporary)';

    /** How many leaderboard rows (best first) are re-summed from the database. */
    public const SCORE_SAMPLE = 3;

    public function handle(RuntimeFingerprintService $runtime, CompetitionPeriodService $period): int
    {
        $cli = $runtime->snapshot();

        $this->info('CLI process');
        $this->table(['field', 'value'], $this->rows($cli));

        $dotenv = $cli['period_dotenv_file'];
        if (is_array($dotenv) && count($dotenv) > 1) {
            $this->warn('.env assigns ' . RuntimeFingerprintService::PERIOD_KEY . ' ' . count($dotenv) . ' times; the FIRST value wins: ' . implode(', ', $dotenv));
        }

        if ($this->option('user') === null) {
            $this->line('Add --user=<your user id> to fetch the real /api/daily/stats response over HTTP.');

            return self::SUCCESS;
        }

        $user = User::find((int) $this->option('user'));
        if (! $user || $user->anonymized_at !== null) {
            $this->error('No active user with that ID.');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('base-url') ?: rtrim((string) config('app.url'), '/') . '/api'), '/');

        if (! $this->option('yes') && ! $this->confirm("Create a temporary API token for user #{$user->id}, call {$base}/daily/stats and delete the token again?", false)) {
            $this->line('Nothing done.');

            return self::SUCCESS;
        }

        $new = $user->createToken(self::TOKEN_NAME);
        $results = [];
        try {
            foreach (['/daily/stats', '/daily/leaderboard/weekly'] as $path) {
                $results[$path] = $this->fetch($base . $path, $new->plainTextToken);
            }
        } finally {
            $user->tokens()->whereKey($new->accessToken->getKey())->delete();
        }

        $this->newLine();
        $this->info('HTTP responses (what the app receives)');
        $this->table(['endpoint', 'HTTP', 'period_type', 'period_label', 'window'], collect($results)->map(fn ($r, $path) => [
            $path, $r['status'], $r['period_type'] ?? '(missing)', $r['period_label'] ?? '-',
            isset($r['period_start']) ? $r['period_start'] . ' → ' . ($r['period_end'] ?? '?') : ($r['error'] ?? '-'),
        ])->values()->all());

        $consistent = $this->reportConsistency($results, $user, $period);
        $this->reportHistory();

        $httpType = $results['/daily/stats']['period_type'] ?? null;
        if ($httpType === null) {
            $this->error('The HTTP response carries no period — the app then shows neutral "View Leaderboard". Check the status and the URL above.');

            return self::FAILURE;
        }
        if ($httpType !== $cli['period_effective']) {
            $this->error("MISMATCH: the CLI computes '{$cli['period_effective']}', the web process answers '{$httpType}'.");
            $this->line('Open /admin/settings → "Runtime (this web process)" and compare base path, git commit, PHP version, config cache and the env values with the CLI table above.');

            return self::FAILURE;
        }

        if (! $consistent) {
            $this->error("INCONSISTENT: the API says '{$httpType}', but the window or the scores above do not match it. Do not trust the label until this is fixed.");

            return self::FAILURE;
        }

        $this->info("OK: CLI and HTTP agree on '{$httpType}'. The app shows \"View " . ucfirst($httpType) . ' Leaderboard" for this response.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function fetch(string $url, string $token): array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->get($url);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => class_basename($e)];
        }

        $period = $response->json('period');
        $body = $response->json();

        return ['status' => $response->status(), 'body' => is_array($body) ? $body : []]
            + (is_array($period) ? array_intersect_key($period, array_flip([
                'period_type', 'period_label', 'period_start', 'period_end',
            ])) : []);
    }

    /**
     * Label vs scoring: same period on both endpoints, a window that is really
     * the current month/week, and leaderboard totals that equal the database
     * sums over THAT window. Prints no names or ids.
     *
     * @param  array<string, array<string, mixed>>  $results
     */
    private function reportConsistency(array $results, User $user, CompetitionPeriodService $period): bool
    {
        $stats = $results['/daily/stats'];
        $board = $results['/daily/leaderboard/weekly'];
        $type = $stats['period_type'] ?? null;
        $window = isset($stats['period_start'], $stats['period_end']) ? [$stats['period_start'], $stats['period_end']] : null;

        $now = Carbon::now($period->timezone());
        $windows = [
            CompetitionPeriodService::MONTHLY => $period->boundariesFor(CompetitionPeriodService::MONTHLY, $now),
            CompetitionPeriodService::WEEKLY  => $period->boundariesFor(CompetitionPeriodService::WEEKLY, $now),
        ];

        $rows = [];
        $ok = true;
        $check = function (string $name, ?bool $pass, string $detail) use (&$rows, &$ok): void {
            if ($pass === false) {
                $ok = false;
            }
            $rows[] = [$name, $pass === null ? 'n/a' : ($pass ? 'OK' : 'FAIL'), $detail];
        };

        $fmt = fn (?array $w) => $w ? "{$w[0]} → {$w[1]}" : '(missing)';

        // 1. Both endpoints describe the same period.
        $boardWindow = isset($board['period_start'], $board['period_end']) ? [$board['period_start'], $board['period_end']] : null;
        $check(
            'stats.period = leaderboard.period',
            $type === null ? null : ($type === ($board['period_type'] ?? null) && $window === $boardWindow),
            ($type ?? '-') . ' ' . $fmt($window) . ' vs ' . ($board['period_type'] ?? '-') . ' ' . $fmt($boardWindow),
        );

        // 2. The legacy week_start/week_end keys carry the same boundaries.
        $legacy = isset($board['body']['week_start'], $board['body']['week_end']) ? [$board['body']['week_start'], $board['body']['week_end']] : null;
        $check(
            'leaderboard week_start/week_end = period',
            $legacy === null || $boardWindow === null ? null : $legacy === $boardWindow,
            $fmt($legacy),
        );

        // 3. The window really is the current month (or week) for that type.
        $shape = $window === null ? null : (array_search($window, $windows, true) ?: 'other');
        $check(
            'window is the current ' . match ($type) { 'weekly' => 'ISO week', 'monthly' => 'calendar month', default => 'period' },
            $type === null || $window === null ? null : $shape === $type,
            'HTTP window ' . $fmt($window) . " = {$shape}; month " . $fmt($windows['monthly']) . ', week ' . $fmt($windows['weekly']),
        );

        // 4. The totals on the leaderboard are sums over the month, not the week.
        $samples = [];
        foreach (array_slice((array) ($board['body']['data'] ?? []), 0, self::SCORE_SAMPLE) as $entry) {
            if (is_array($entry) && isset($entry['user_id'], $entry['total_score'], $entry['rank'])) {
                $samples[] = ['label' => '#' . $entry['rank'], 'user_id' => (int) $entry['user_id'], 'score' => (int) $entry['total_score']];
            }
        }
        $meta = (array) ($board['body']['meta'] ?? []);
        if (isset($meta['current_user_score'])) {
            $samples[] = ['label' => 'you (#' . ($meta['current_user_rank'] ?? '?') . ')', 'user_id' => $user->id, 'score' => (int) $meta['current_user_score']];
        }

        $sums = [];
        foreach ($windows as $kind => $w) {
            $sums[$kind] = $this->sumsFor(array_column($samples, 'user_id'), $w);
        }

        $scoreRows = [];
        $verdicts = [];
        foreach ($samples as $sample) {
            $month = $sums['monthly'][$sample['user_id']] ?? 0;
            $week = $sums['weekly'][$sample['user_id']] ?? 0;
            $verdict = match (true) {
                $sample['score'] === $month && $sample['score'] === $week => 'either (no play outside this week)',
                $sample['score'] === $month => 'monthly',
                $sample['score'] === $week  => 'weekly',
                default                     => 'neither',
            };
            $verdicts[] = $verdict;
            $scoreRows[] = [$sample['label'], $sample['score'], $month, $week, $verdict];
        }

        $decisive = array_values(array_unique(array_filter($verdicts, fn ($v) => in_array($v, ['monthly', 'weekly', 'neither'], true))));
        $scoring = match (true) {
            $samples === []                 => null,
            $decisive === []                => 'undecided',
            count($decisive) === 1          => $decisive[0],
            default                         => 'mixed',
        };
        $check(
            'leaderboard scores = sums over the ' . ($type ?? 'period') . ' window',
            $type === null || $scoring === null || $scoring === 'undecided' ? null : $scoring === $type,
            $scoring === null ? 'no leaderboard rows to compare' : "scores computed over: {$scoring}",
        );

        // 5. The rank on Daily Result is the rank on the leaderboard.
        $statsRank = $stats['body']['weekly_rank'] ?? null;
        $boardRank = $meta['current_user_rank'] ?? null;
        $check(
            'stats.weekly_rank = leaderboard rank',
            array_key_exists('weekly_rank', $stats['body'] ?? []) && array_key_exists('current_user_rank', $meta) ? $statsRank === $boardRank : null,
            ($statsRank ?? '-') . ' vs ' . ($boardRank ?? '-'),
        );

        $this->newLine();
        $this->info('Label vs scoring (what the numbers on screen are really based on)');
        $this->table(['check', 'result', 'detail'], $rows);

        if ($scoreRows !== []) {
            $this->table(['row', 'HTTP total', 'DB sum month', 'DB sum week', 'matches'], $scoreRows);
        }

        return $ok;
    }

    /**
     * Total daily score per user for [start, end], counted exactly like
     * CompetitionStandingsService (challenge_date window).
     *
     * @param  list<int>  $userIds
     * @param  array{0:string,1:string}  $window
     * @return array<int, int>
     */
    private function sumsFor(array $userIds, array $window): array
    {
        if ($userIds === []) {
            return [];
        }

        return DailyChallengeGuess::whereHas('dailyChallenge', fn ($q) => $q->whereBetween('challenge_date', $window))
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(score) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Weekly text that lives in the DATABASE, not in the period: closed
     * competitions (old weekly results stay weekly), their XP reasons, and the
     * weekly_top_10 badge name (renamed by BadgeSeeder on deploy). Aggregates only.
     */
    private function reportHistory(): void
    {
        $badge = Badge::where('code', 'weekly_top_10')->first(['name', 'description']);
        $weeklyXp = XpEvent::where('source_type', XpEvent::SOURCE_COMPETITION_FINISH)->where('reason', 'like', 'Weekly%')->count();

        $this->newLine();
        $this->info('Stored "Weekly" text (shown as is by the app)');
        $this->table(['source', 'value'], [
            ['badge weekly_top_10 name', $badge ? $badge->name . (stripos($badge->name . ' ' . $badge->description, 'weekly') !== false ? '  ← still the old name: run BadgeSeeder' : '') : '(no badge)'],
            ['XP events "Weekly competition …"', (string) $weeklyXp],
        ]);

        $history = CompetitionFinish::query()
            ->selectRaw('period_type, COUNT(*) as finishes, COUNT(DISTINCT period_start) as periods, MIN(period_start) as first_start, MAX(period_end) as last_end')
            ->groupBy('period_type')
            ->orderBy('period_type')
            ->get();

        $this->newLine();
        $this->info('Closed competitions (Trophy Room history)');
        if ($history->isEmpty()) {
            $this->line('No closed competitions yet.');

            return;
        }

        $this->table(['period_type', 'periods', 'finishes', 'first start', 'last end'], $history->map(fn ($h) => [
            $h->period_type, $h->periods, $h->finishes,
            Carbon::parse($h->first_start)->toDateString(), Carbon::parse($h->last_end)->toDateString(),
        ])->all());
    }

    /** @return list<array{0:string,1:string}> */
    private function rows(array $snapshot, string $prefix = ''): array
    {
        $rows = [];
        foreach ($snapshot as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $rows = array_merge($rows, $this->rows($value, $prefix . $key . '.'));
                continue;
            }
            $rows[] = [$prefix . $key, match (true) {
                is_bool($value)  => $value ? 'yes' : 'no',
                is_array($value) => $value === [] ? '(not in file)' : implode(', ', $value),
                $value === null  => '-',
                default          => (string) $value,
            }];
        }

        return $rows;
    }
}
