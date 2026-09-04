<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\PackAttempt;
use App\Models\Sport;
use App\Models\TournamentFinish;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only operational snapshot for /admin/diagnostics.
 *
 * Everything here is a count, a status or a boolean. It NEVER returns secrets
 * (APP_KEY, DB/mail passwords, beta code), stack traces, job payloads, tokens,
 * codes or emails, and it never mutates data. Cheap enough to run on every
 * page load during beta; nothing is cached so the numbers are always live.
 */
class DiagnosticsService
{
    /** Below this many never-used daily-eligible photos the daily rotation is at risk. */
    public const DAILY_POOL_LOW = 14;

    /** A 7-day tournament needs 7 unique photos; fewer means "cannot start". */
    public const TOURNAMENT_POOL_LOW = 7;

    /** Queue jobs older than this are considered stuck (no worker / crashed worker). */
    public const JOB_STALE_MINUTES = 15;

    /** How many days of scheduled dailies we want in reserve before warning. */
    public const DAILY_RUNWAY_DAYS = 3;

    /**
     * Warning-level events that still deserve a red row on the dashboard —
     * each one is a user-visible failure of a launch-critical flow.
     */
    public const WATCHED_WARNINGS = [
        'password.reset_failed',
        'password.reset_requested',
        'auth.verification_failed',
        'auth.beta_code_rejected',
        'pack.start_failed',
    ];

    /** How much of the log file tail to scan for recent errors (bytes). */
    private const LOG_TAIL_BYTES = 512 * 1024;

    /** @var array<int, array{level: string, section: string, message: string}> */
    private array $warnings = [];

    public function snapshot(): array
    {
        $this->warnings = [];

        $now = now();

        $data = [
            'generated_at' => $now,
            'app'          => $this->app($now),
            'log'          => $this->log($now),
            'queue'        => $this->queue($now),
            'daily'        => $this->daily($now),
            'content'      => $this->content(),
            'tournaments'  => $this->tournaments($now),
            'packs'        => $this->packs(),
            'storage'      => $this->storage(),
            'activity'     => $this->activity($now),
            'commands'     => $this->commands(),
        ];

        $data['warnings'] = $this->warnings;

        return $data;
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    private function app(Carbon $now): array
    {
        $debug = (bool) config('app.debug');
        $env   = (string) config('app.env');

        if ($debug && $env === 'production') {
            $this->warn('danger', 'app', 'APP_DEBUG is ON in production — stack traces and request data leak to users. Set APP_DEBUG=false.');
        }

        return [
            'name'        => config('ballspot.app_name', 'BallPicker'),
            'version'     => config('ballspot.version', 'v1'),
            'env'         => $env,
            'debug'       => $debug,
            'url'         => (string) config('app.url'),
            'timezone'    => (string) config('app.timezone'),
            'server_time' => $now,
            'php_version' => PHP_VERSION,
            'push_enabled'                => (bool) config('ballspot.notifications.push_enabled'),
            'daily_reminder_push_enabled' => (bool) config('ballspot.notifications.daily_reminder_push_enabled'),
            'beta_gate_enabled'           => (bool) config('ballspot.beta_code'),
        ];
    }

    /**
     * Log file facts + a scan of the tail of laravel.log for recent errors.
     * Only timestamps, levels and a sanitized, truncated first line are kept —
     * never a stack trace, never SQL, never emails.
     */
    private function log(Carbon $now): array
    {
        $path = storage_path('logs/laravel.log');
        $eventsPath = storage_path('logs/ballpicker-events-' . $now->toDateString() . '.log');

        $result = [
            'channel'           => (string) config('logging.default'),
            'level'             => (string) (config('logging.channels.single.level') ?? env('LOG_LEVEL', 'debug')),
            'file'              => 'storage/logs/laravel.log',
            'exists'            => is_file($path),
            'size_bytes'        => is_file($path) ? (int) filesize($path) : 0,
            'modified_at'       => is_file($path) ? Carbon::createFromTimestamp(filemtime($path)) : null,
            'events_file'       => 'storage/logs/ballpicker-events-YYYY-MM-DD.log',
            'events_file_today' => is_file($eventsPath),
            'errors_24h'        => 0,
            'warnings_24h'      => 0,
            'last_error_at'     => null,
            'last_error_summary'=> null,
        ];

        if (!$result['exists']) {
            return $result;
        }

        $tail = $this->tail($path, self::LOG_TAIL_BYTES);
        $since = $now->copy()->subDay();

        foreach (preg_split('/\r?\n/', $tail) as $line) {
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING): (.*)$/', $line, $m)) {
                continue;
            }
            $at = Carbon::parse($m[1]);
            if ($at->lt($since)) {
                continue;
            }
            if ($m[2] === 'WARNING') {
                $result['warnings_24h']++;
                continue;
            }
            $result['errors_24h']++;
            $result['last_error_at']      = $at;
            $result['last_error_summary'] = $this->sanitizeLogLine($m[3]);
        }

        if ($result['errors_24h'] > 0) {
            $this->warn('warning', 'log', "{$result['errors_24h']} error(s) logged in the last 24h — run: tail -n 100 storage/logs/laravel.log");
        }

        $result['event_errors_24h'] = $this->eventErrors($now);
        foreach ($result['event_errors_24h'] as $name => $count) {
            $this->warn('warning', 'log', "{$count}× {$name} in the last 24h (events log)");
        }

        return $result;
    }

    /**
     * Failed operational events (AppLog::error / ::warn on the critical account
     * flows) from today's and yesterday's events files, counted by event name.
     * Only names and counts — the JSON context is never read into the page.
     *
     * @return array<string,int>
     */
    private function eventErrors(Carbon $now): array
    {
        $since  = $now->copy()->subDay();
        $counts = [];

        foreach ([$now->copy()->subDay(), $now] as $day) {
            $path = storage_path('logs/ballpicker-events-' . $day->toDateString() . '.log');
            if (!is_file($path)) {
                continue;
            }
            foreach (preg_split('/\r?\n/', $this->tail($path, self::LOG_TAIL_BYTES)) as $line) {
                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING): ([a-z_]+\.[a-z_]+)\b/', $line, $m)) {
                    continue;
                }
                if (Carbon::parse($m[1])->lt($since)) {
                    continue;
                }
                $name = $m[3];
                if ($m[2] === 'WARNING' && !in_array($name, self::WATCHED_WARNINGS, true)) {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    private function queue(Carbon $now): array
    {
        $hasJobs   = Schema::hasTable('jobs');
        $hasFailed = Schema::hasTable('failed_jobs');

        $pending = $hasJobs ? (int) DB::table('jobs')->count() : 0;
        $stale   = $hasJobs
            ? (int) DB::table('jobs')->where('created_at', '<', $now->copy()->subMinutes(self::JOB_STALE_MINUTES)->timestamp)->count()
            : 0;
        $failed  = $hasFailed ? (int) DB::table('failed_jobs')->count() : 0;
        $latestFailedAt = $hasFailed ? DB::table('failed_jobs')->max('failed_at') : null;

        if ($failed > 0) {
            $this->warn('danger', 'queue', "{$failed} failed job(s) — run: php artisan queue:failed");
        }
        if ($stale > 0) {
            $this->warn('warning', 'queue', "{$stale} job(s) waiting longer than " . self::JOB_STALE_MINUTES . " minutes — is the queue worker running? (sudo supervisorctl status)");
        }

        return [
            'connection'       => (string) config('queue.default'),
            'tables_present'   => $hasJobs && $hasFailed,
            'pending_jobs'     => $pending,
            'stale_jobs'       => $stale,
            'failed_jobs'      => $failed,
            'latest_failed_at' => $latestFailedAt ? Carbon::parse($latestFailedAt) : null,
            // Honest note for the reader: nothing is queued today.
            'note'             => 'Mail and push currently run synchronously inside the request/command; these counters should stay at 0 unless queued work is introduced.',
        ];
    }

    private function daily(Carbon $now): array
    {
        $today = $now->toDateString();

        $todayRow = DailyChallenge::forDate($today)->with('challenge:id,title,sport_id,status')->first();
        $todayStatus = $todayRow?->status ?? 'none';

        $latest = DailyChallenge::whereIn('status', ['scheduled', 'active'])->max('challenge_date');
        $latestDate = $latest ? Carbon::parse($latest)->toDateString() : null;

        $scheduledUpcoming = DailyChallenge::where('status', 'scheduled')
            ->whereDate('challenge_date', '>=', $today)
            ->count();

        $activeUpcoming = DailyChallenge::where('status', 'active')
            ->whereDate('challenge_date', '>', $today)
            ->count();

        $poolAvailable = Challenge::dailyPool()->readySql()->notDailyUsed()->count();

        if ($todayStatus === 'none') {
            $this->warn('danger', 'daily', "No daily challenge exists for today ({$today}). Players see \"No daily challenge\". Run: php artisan ballspot:schedule-daily-challenges");
        } elseif ($todayStatus === 'scheduled') {
            $this->warn('danger', 'daily', "Today's daily challenge is still \"scheduled\", not \"active\" — the app only serves active dailies. Activate it under Admin → Daily.");
        } elseif ($todayStatus === 'archived') {
            $this->warn('danger', 'daily', "Today's daily challenge is archived — players see no daily.");
        } elseif ($todayRow && $todayRow->challenge && $todayRow->challenge->status !== 'active') {
            $this->warn('danger', 'daily', "Today's daily challenge points at a challenge that is not active (status: {$todayRow->challenge->status}).");
        }

        if ($latestDate === null || Carbon::parse($latestDate)->lt($now->copy()->addDays(self::DAILY_RUNWAY_DAYS))) {
            $this->warn('warning', 'daily', 'Fewer than ' . self::DAILY_RUNWAY_DAYS . ' days of daily challenges are scheduled ahead. Schedule more before the runway runs out.');
        }

        if ($poolAvailable < self::DAILY_POOL_LOW) {
            $this->warn('warning', 'daily', "Daily pool is low: {$poolAvailable} never-used daily-eligible photo(s) left (threshold " . self::DAILY_POOL_LOW . '). Upload more daily/general photos.');
        }

        return [
            'today'                => $today,
            'today_status'         => $todayStatus,
            'today_challenge_id'   => $todayRow?->challenge_id,
            'today_challenge_title'=> $todayRow?->challenge?->title,
            'latest_scheduled_date'=> $latestDate,
            'scheduled_count'      => $scheduledUpcoming,
            'active_upcoming_count'=> $activeUpcoming,
            'pool_available'       => $poolAvailable,
            'pool_low_threshold'   => self::DAILY_POOL_LOW,
            'cron_command'         => 'ballspot:schedule-daily-challenges (daily 00:05)',
        ];
    }

    private function content(): array
    {
        $sports = Sport::orderBy('sort_order')->orderBy('name')->get();

        $perSport = $sports->map(function (Sport $sport) {
            $base = fn () => Challenge::where('sport_id', $sport->id);

            $row = [
                'sport_id'            => $sport->id,
                'name'                => $sport->name,
                'slug'                => $sport->slug,
                'status'              => $sport->status,
                'active_ready'        => $base()->where('status', 'active')->readySql()->count(),
                'daily_eligible'      => $base()->dailyPool()->readySql()->notDailyUsed()->count(),
                'tournament_eligible' => $base()->tournamentEligibleStrict()->count(),
                'pack_only'           => $base()->where('status', 'active')->where('usage_pool', Challenge::POOL_PACK)->readySql()->count(),
                'used_as_daily'       => $base()->dailyUsed()->count(),
            ];

            if ($sport->status === Sport::STATUS_ACTIVE) {
                if ($row['tournament_eligible'] < self::TOURNAMENT_POOL_LOW) {
                    $this->warn('warning', 'content', "{$sport->name}: only {$row['tournament_eligible']} tournament-eligible photo(s) — a 7-day tournament cannot start below " . self::TOURNAMENT_POOL_LOW . '.');
                }
                if ($row['daily_eligible'] < self::DAILY_POOL_LOW) {
                    $this->warn('warning', 'content', "{$sport->name}: only {$row['daily_eligible']} never-used daily-eligible photo(s) (threshold " . self::DAILY_POOL_LOW . ').');
                }
            }

            return $row;
        })->values()->all();

        return [
            'active_ready'        => Challenge::where('status', 'active')->readySql()->count(),
            'daily_pool'          => Challenge::dailyPool()->readySql()->count(),
            'daily_available'     => Challenge::dailyPool()->readySql()->notDailyUsed()->count(),
            'tournament_eligible' => Challenge::tournamentEligibleStrict()->count(),
            'pack_only'           => Challenge::where('status', 'active')->where('usage_pool', Challenge::POOL_PACK)->readySql()->count(),
            'used_as_daily'       => Challenge::dailyUsed()->count(),
            'total'               => Challenge::count(),
            'draft'               => Challenge::where('status', 'draft')->count(),
            'archived'            => Challenge::where('status', 'archived')->count(),
            'per_sport'           => $perSport,
            'thresholds'          => [
                'tournament' => self::TOURNAMENT_POOL_LOW,
                'daily'      => self::DAILY_POOL_LOW,
            ],
        ];
    }

    private function tournaments(Carbon $now): array
    {
        $byStatus = League::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $expiredActive = League::where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->count();

        if ($expiredActive > 0) {
            $this->warn('warning', 'tournaments', "{$expiredActive} active tournament(s) are past their end date but not completed. Completion only happens when every member has played every round — a member who stopped playing keeps it open until the host cancels it.");
        }

        return [
            'lobby'          => (int) ($byStatus['lobby'] ?? 0),
            'active'         => (int) ($byStatus['active'] ?? 0),
            'completed'      => (int) ($byStatus['completed'] ?? 0),
            'cancelled'      => (int) ($byStatus['cancelled'] ?? 0),
            'expired_active' => $expiredActive,
            'completion_note'=> 'Completion is guess-triggered (no time-based sweep).',
        ];
    }

    private function packs(): array
    {
        $live = ChallengePack::where('status', '!=', ChallengePack::STATUS_ARCHIVED);

        $zero = (clone $live)->withCount('challenges')->get()->filter(fn ($p) => $p->challenges_count === 0);
        $zeroPublic = $zero->filter(fn ($p) => $p->status === ChallengePack::STATUS_ACTIVE && $p->visibility === ChallengePack::VISIBILITY_PUBLIC);

        if ($zeroPublic->isNotEmpty()) {
            $names = $zeroPublic->pluck('name')->implode(', ');
            $this->warn('danger', 'packs', "Public active pack(s) with zero challenges: {$names}. Players get \"This pack has no ready challenges yet.\"");
        }

        return [
            'active_public'   => ChallengePack::visibleToUsers()->count(),
            'total'           => ChallengePack::count(),
            'zero_challenges' => $zero->count(),
            'zero_challenge_names' => $zero->pluck('name')->values()->all(),
            'with_trophy'     => (clone $live)->whereNotNull('completion_badge_id')->count(),
            'without_trophy'  => (clone $live)->whereNull('completion_badge_id')->count(),
        ];
    }

    private function storage(): array
    {
        $root = storage_path('app/public');
        $link = public_path('storage');

        $rootExists   = is_dir($root);
        $rootWritable = $rootExists && is_writable($root);
        $linkExists   = is_link($link) || is_dir($link);

        if (!$rootExists) {
            $this->warn('danger', 'storage', 'storage/app/public does not exist — uploads will fail.');
        } elseif (!$rootWritable) {
            $this->warn('danger', 'storage', 'storage/app/public is not writable — uploads will fail. Check ownership/permissions.');
        }
        if (!$linkExists) {
            $this->warn('danger', 'storage', 'public/storage link is missing — images will not load. Run: php artisan storage:link');
        }

        $dirs = [];
        foreach ([
            'challenges/hidden'   => 'Challenge images (hidden)',
            'challenges/original' => 'Challenge images (reveal)',
            'avatars'             => 'Avatars',
            'packs/covers'        => 'Pack covers',
        ] as $rel => $label) {
            $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $exists = is_dir($abs);
            $dirs[] = [
                'label'  => $label,
                'path'   => 'storage/app/public/' . $rel,
                'exists' => $exists,
                'files'  => $exists ? $this->countFiles($abs) : 0,
            ];
        }

        return [
            'root'          => 'storage/app/public',
            'root_exists'   => $rootExists,
            'root_writable' => $rootWritable,
            'link'          => 'public/storage',
            'link_exists'   => $linkExists,
            'directories'   => $dirs,
        ];
    }

    /** Counts only — a quick pulse of "is anyone playing / did anything happen". */
    private function activity(Carbon $now): array
    {
        $since = $now->copy()->subDay();

        return [
            'window'                => 'last 24h',
            'registrations'         => User::where('created_at', '>=', $since)->count(),
            'account_deletions'     => Schema::hasColumn('users', 'anonymized_at') ? User::where('anonymized_at', '>=', $since)->count() : 0,
            'daily_guesses'         => DB::table('daily_challenge_guesses')->where('submitted_at', '>=', $since)->count(),
            'tournament_guesses'    => DB::table('guesses')->where('submitted_at', '>=', $since)->count(),
            'pack_guesses'          => DB::table('pack_attempt_guesses')->where('created_at', '>=', $since)->count(),
            'tournaments_created'   => League::where('created_at', '>=', $since)->count(),
            'tournaments_completed' => TournamentFinish::where('created_at', '>=', $since)->distinct('league_id')->count('league_id'),
            'pack_completions'      => PackAttempt::where('status', PackAttempt::STATUS_COMPLETED)->where('completed_at', '>=', $since)->count(),
            'push_tokens_seen'      => DB::table('push_tokens')->where('last_seen_at', '>=', $since)->count(),
            'push_tokens_total'     => DB::table('push_tokens')->count(),
            'users_total'           => User::count(),
        ];
    }

    /** Text only. Never executed from the web. */
    private function commands(): array
    {
        return [
            ['label' => 'Recent application log',        'command' => 'tail -n 100 storage/logs/laravel.log'],
            ['label' => 'Follow the log live',           'command' => 'tail -f storage/logs/laravel.log'],
            ['label' => 'Operational events (today)',    'command' => 'tail -n 100 storage/logs/ballpicker-events-$(date +%F).log'],
            ['label' => 'Failed queue jobs',             'command' => 'php artisan queue:failed'],
            ['label' => 'Worker / process status',       'command' => 'sudo supervisorctl status'],
            ['label' => 'Scheduled tasks + next run',    'command' => 'php artisan schedule:list'],
            ['label' => 'Schedule dailies (preview)',    'command' => 'php artisan ballspot:schedule-daily-challenges --dry-run'],
            ['label' => 'Content backup',                'command' => 'php artisan ballspot:backup-content'],
            ['label' => 'Public health check',           'command' => 'curl -s ' . rtrim((string) config('app.url'), '/') . '/api/health'],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function warn(string $level, string $section, string $message): void
    {
        $this->warnings[] = ['level' => $level, 'section' => $section, 'message' => $message];
    }

    private function tail(string $path, int $bytes): string
    {
        $size = (int) filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($size > $bytes) {
            fseek($fh, -$bytes, SEEK_END);
        }
        $data = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $data;
    }

    /**
     * First line of a log record, minus anything that could carry data:
     * the JSON context, SQL, connection details, emails. Hard-capped.
     */
    private function sanitizeLogLine(string $line): string
    {
        foreach ([' {"', ' (Connection:', ' (SQL:', ' in /', ' in C:'] as $cut) {
            $pos = strpos($line, $cut);
            if ($pos !== false) {
                $line = substr($line, 0, $pos);
            }
        }
        $line = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[email]', $line) ?? $line;
        $line = trim($line);

        return mb_strlen($line) > 140 ? mb_substr($line, 0, 140) . '…' : $line;
    }

    private function countFiles(string $dir): int
    {
        $n = 0;
        foreach (new \DirectoryIterator($dir) as $f) {
            if ($f->isFile() && !$f->isDot()) {
                $n++;
            }
        }

        return $n;
    }
}
