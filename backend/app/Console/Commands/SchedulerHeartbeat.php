<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Records that the scheduler ran: when, and as which system user.
 *
 * Scheduled every five minutes in routes/console.php WITHOUT
 * withoutOverlapping() — it must never depend on the cache store, because a
 * broken cache store is one of the things the heartbeat exists to expose.
 * GET /api/health and /admin/diagnostics read the file: stale = cron not
 * running; written by a user other than the web user = the misconfiguration
 * behind the 2026-10-15 outage (root-owned cache directories).
 *
 * Written to a temp file and renamed so a file left behind by root can still
 * be replaced once the cron runs as the web user (rename needs write access
 * to the directory, not to the old file).
 */
class SchedulerHeartbeat extends Command
{
    protected $signature = 'ballspot:heartbeat';

    protected $description = 'Record that the scheduler ran (read by /api/health and /admin/diagnostics)';

    public function handle(): int
    {
        $path = (string) config('ballspot.monitoring.heartbeat_file', storage_path('framework/scheduler-heartbeat.json'));
        $uid  = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $info = $uid !== null && function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        $user = is_array($info) ? (string) $info['name'] : null;

        $payload = json_encode([
            'at'   => now()->toIso8601String(),
            'unix' => now()->timestamp,
            'uid'  => $uid,
            'user' => $user,
        ], JSON_PRETTY_PRINT);

        try {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create {$dir}");
            }
            $tmp = $path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $payload) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException("cannot write {$path}");
            }
            @chmod($path, 0664);
        } catch (\Throwable $e) {
            $this->error('Heartbeat not written: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('Heartbeat written as ' . ($user ?? ($uid !== null ? "uid {$uid}" : 'unknown user')) . '.');

        return self::SUCCESS;
    }
}
