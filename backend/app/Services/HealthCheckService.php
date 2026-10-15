<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The checks behind GET /api/health, `php artisan ballspot:health` and the
 * health strip on /admin/diagnostics — one definition of "healthy", so the
 * 5-minute monitor, the deploy script and the admin page can never disagree.
 *
 * run() returns the booleans the public endpoint may show, plus details only
 * the admin page and the CLI print: paths relative to the install, counts,
 * user names. Never secrets, never stack traces.
 *
 * Every check catches everything: a broken dependency turns its own flag
 * false and must not turn the health call itself into a 500.
 */
class HealthCheckService
{
    /** Queue jobs older than this are considered stuck (no worker / crashed worker). */
    public const JOB_STALE_MINUTES = 15;

    public function run(): array
    {
        $details = [
            'database'  => $this->database(),
            'storage'   => $this->storage(),
            'cache'     => $this->cache((int) config('ballspot.monitoring.cache_scan_limit', 4096)),
            'queue'     => $this->queue(),
            'scheduler' => $this->scheduler(),
        ];

        $checks = array_map(fn (array $d) => (bool) $d['ok'], $details);

        return [
            'ok'      => !in_array(false, $checks, true),
            'checks'  => $checks,
            'details' => $details,
        ];
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    public function database(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return ['ok' => true, 'reason' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'database unreachable (' . class_basename($e) . ')'];
        }
    }

    public function storage(): array
    {
        $root = storage_path('app/public');
        $ok   = is_dir($root) && is_writable($root);

        return ['ok' => $ok, 'path' => 'storage/app/public', 'reason' => $ok ? null : 'storage/app/public is not writable by this process'];
    }

    /**
     * The store behind the rate limiter — every throttled API route reads and
     * writes it on each request. A real write/read/delete probe, plus, for the
     * file driver, a bounded scan for entries this process cannot write: each
     * limiter key lives in its own hashed data/xx/yy/ directory, so one
     * root-owned directory breaks only the keys that hash into it and a single
     * probe key would miss it (the 2026-10-15 incident).
     */
    public function cache(int $scanLimit): array
    {
        $default = (string) config('cache.default');
        $store   = (string) (config('cache.limiter') ?: $default);
        $driver  = (string) config("cache.stores.{$store}.driver", $store);

        $result = [
            'ok'                 => true,
            'reason'             => null,
            'default_store'      => $default,
            'limiter_store'      => $store,
            'limiter_driver'     => $driver,
            'probe_ok'           => false,
            'probe_error'        => null,
            'file_path'          => null,
            'file_root_writable' => null,
            'unwritable_count'   => 0,
            'unwritable_samples' => [],
            'scanned'            => 0,
            'scan_truncated'     => false,
        ];

        $result['probe_error'] = $this->probeStore($store);
        $result['probe_ok']    = $result['probe_error'] === null;
        if (!$result['probe_ok']) {
            $result['ok']     = false;
            $result['reason'] = "cache store '{$store}' failed a write/read/delete probe: " . $result['probe_error'];
        }

        if ($driver === 'file') {
            $root = (string) config("cache.stores.{$store}.path", storage_path('framework/cache/data'));
            $result['file_path']          = $this->relativePath($root);
            $result['file_root_writable'] = is_dir($root) ? is_writable($root) : is_writable(dirname($root));

            if (is_dir($root)) {
                $this->scanFileCache($root, $scanLimit, $result);
            }

            if (!$result['file_root_writable']) {
                $result['ok']     = false;
                $result['reason'] ??= $result['file_path'] . ' is not writable by this process';
            } elseif ($result['unwritable_count'] > 0) {
                $result['ok']     = false;
                $result['reason'] ??= $result['unwritable_count'] . ' file-cache entr' . ($result['unwritable_count'] === 1 ? 'y' : 'ies') . ' not writable by this process';
            }
        }

        return $result;
    }

    public function queue(): array
    {
        try {
            $hasJobs   = Schema::hasTable('jobs');
            $hasFailed = Schema::hasTable('failed_jobs');
            $window    = (int) config('ballspot.monitoring.queue_failed_window_hours', 24);

            $stale = $hasJobs
                ? (int) DB::table('jobs')->where('created_at', '<', now()->subMinutes(self::JOB_STALE_MINUTES)->timestamp)->count()
                : 0;
            $recentFailed = $hasFailed
                ? (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours($window))->count()
                : 0;

            $reason = match (true) {
                !$hasJobs || !$hasFailed => 'queue tables missing — run migrations',
                $stale > 0               => "{$stale} job(s) waiting longer than " . self::JOB_STALE_MINUTES . ' minutes',
                $recentFailed > 0        => "{$recentFailed} job(s) failed in the last {$window}h",
                default                  => null,
            };

            return [
                'ok'               => $reason === null,
                'reason'           => $reason,
                'stale_jobs'       => $stale,
                'recent_failed'    => $recentFailed,
                'failed_window_h'  => $window,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'queue tables unreadable (' . class_basename($e) . ')', 'stale_jobs' => null, 'recent_failed' => null, 'failed_window_h' => null];
        }
    }

    /**
     * `ballspot:heartbeat` (scheduled every five minutes, never through the
     * cache) writes when it ran and as which user. A stale or missing file
     * means the cron is not running; a user other than the web user is the
     * misconfiguration that caused the 2026-10-15 outage (root-owned cache
     * directories / log files), so it fails the check too.
     */
    public function scheduler(): array
    {
        $path = (string) config('ballspot.monitoring.heartbeat_file', storage_path('framework/scheduler-heartbeat.json'));
        $max  = max(1, (int) config('ballspot.monitoring.scheduler_heartbeat_max_minutes', 15));

        [$webUid, $webUser] = $this->expectedUser();

        $result = [
            'ok'           => false,
            'reason'       => null,
            'file'         => $this->relativePath($path),
            'last_run_at'  => null,
            'age_minutes'  => null,
            'max_minutes'  => $max,
            'ran_as'       => null,
            'ran_as_uid'   => null,
            'web_user'     => $webUser,
            'web_uid'      => $webUid,
            'user_ok'      => null,
        ];

        $raw  = is_file($path) ? @file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['unix'])) {
            $result['reason'] = 'no scheduler heartbeat yet — is the cron (schedule:run every minute) installed?';

            return $result;
        }

        $last = Carbon::createFromTimestamp((int) $data['unix']);
        $result['last_run_at'] = $last;
        $result['age_minutes'] = (int) floor(max(0, now()->timestamp - (int) $data['unix']) / 60);
        $result['ran_as']      = isset($data['user']) && is_string($data['user']) ? $data['user'] : null;
        $result['ran_as_uid']  = isset($data['uid']) && is_int($data['uid']) ? $data['uid'] : null;

        if ($result['ran_as_uid'] !== null && $webUid !== null) {
            $result['user_ok'] = $result['ran_as_uid'] === $webUid;
        }

        if ($result['age_minutes'] > $max) {
            $result['reason'] = "scheduler last ran {$result['age_minutes']} minutes ago (limit {$max}) — cron not running?";
        } elseif ($result['user_ok'] === false) {
            $result['reason'] = 'scheduler cron runs as ' . ($result['ran_as'] ?? 'uid ' . $result['ran_as_uid']) . ', not as the web user ' . ($webUser ?? 'uid ' . $webUid) . ' — it creates files the web user cannot write';
        } else {
            $result['ok'] = true;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers shared with the diagnostics page
    // ------------------------------------------------------------------

    /** Write, read back and delete a random key. Null when fine, else a short sanitized error. */
    public function probeStore(string $store): ?string
    {
        try {
            $key  = 'health:probe:' . Str::random(12);
            $repo = Cache::store($store);
            $repo->put($key, 1, 60);
            $ok = (int) $repo->get($key) === 1;
            $repo->forget($key);

            return $ok ? null : 'value written to the cache could not be read back';
        } catch (\Throwable $e) {
            return class_basename($e) . ': ' . Str::limit($this->relativePath($e->getMessage()), 140);
        }
    }

    /**
     * Bounded walk of the file cache counting entries this process cannot
     * write (typically created by artisan or cron running as root). Samples
     * are relative to the cache root — never the absolute server path.
     */
    public function scanFileCache(string $root, int $limit, array &$result): void
    {
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($it as $entry) {
                if ($result['scanned'] >= $limit) {
                    $result['scan_truncated'] = true;
                    break;
                }
                $result['scanned']++;

                if (!$entry->isWritable()) {
                    $result['unwritable_count']++;
                    if (count($result['unwritable_samples']) < 5) {
                        $result['unwritable_samples'][] = ltrim(substr($entry->getPathname(), strlen($root)), DIRECTORY_SEPARATOR);
                    }
                }
            }
        } catch (\Throwable) {
            // An unreadable directory is itself a permission problem.
            $result['unwritable_count']++;
        }
    }

    /** Strip the install path so no output shows server layout beyond storage/. */
    public function relativePath(string $text): string
    {
        return str_replace(rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, '', $text);
    }

    /**
     * The user the scheduler must run as: BALLPICKER_WEB_USER when set,
     * otherwise this very process (correct under php-fpm; on the CLI run
     * `ballspot:health` as the web user, as every artisan command).
     *
     * @return array{0:?int,1:?string}
     */
    private function expectedUser(): array
    {
        $configured = config('ballspot.monitoring.web_user');
        if (is_string($configured) && $configured !== '' && function_exists('posix_getpwnam')) {
            $info = @posix_getpwnam($configured);
            if (is_array($info)) {
                return [(int) $info['uid'], $configured];
            }
        }

        if (!function_exists('posix_geteuid')) {
            return [null, null];
        }

        $uid  = posix_geteuid();
        $info = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;

        return [$uid, is_array($info) ? (string) $info['name'] : null];
    }
}
