<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use Illuminate\Console\Command;

/**
 * The same checks as GET /api/health, with reasons, for the deploy script and
 * for a shell on the server. Exit 0 when healthy, 1 when degraded, so
 * scripts/deploy.sh can stop on a broken deploy.
 *
 * Run it as the web user (sudo -u www-data php artisan ballspot:health): the
 * storage/cache checks test what THIS process can write, and the scheduler
 * check compares the cron's user with this process unless BALLPICKER_WEB_USER
 * is set.
 */
class HealthCheck extends Command
{
    protected $signature = 'ballspot:health {--json : Machine-readable output}';

    protected $description = 'Run the health checks (database, storage, cache, queue, scheduler) with reasons';

    public function handle(HealthCheckService $health): int
    {
        $result = $health->run();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $result['ok'] ? 'ok' : 'degraded',
                'checks' => $result['checks'],
                'reasons' => array_filter(array_map(fn (array $d) => $d['reason'], $result['details'])),
            ], JSON_PRETTY_PRINT));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $rows = [];
        foreach ($result['details'] as $name => $d) {
            $rows[] = [$name, $d['ok'] ? 'ok' : 'FAIL', $d['reason'] ?? ''];
        }
        $this->table(['check', 'result', 'reason'], $rows);

        $s = $result['details']['scheduler'];
        if ($s['last_run_at']) {
            $this->line('Scheduler last ran ' . $s['age_minutes'] . ' min ago as ' . ($s['ran_as'] ?? 'uid ' . $s['ran_as_uid']) . '; this process runs as ' . ($s['web_user'] ?? 'uid ' . $s['web_uid']) . '.');
        }

        $this->line($result['ok'] ? 'Status: ok' : 'Status: DEGRADED');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
