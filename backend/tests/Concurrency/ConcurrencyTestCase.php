<?php

namespace Tests\Concurrency;

use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Real-concurrency tests for Clubs against a throwaway MariaDB/MySQL test
 * database. Not part of the default suites (phpunit.xml lists Unit + Feature
 * only), so `php artisan test` never runs them. SQLite turns lockForUpdate()
 * into a no-op, so these are the tests that exercise the row locks.
 *
 * Run (local docker container only):
 *   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=33062 \
 *   DB_DATABASE=ballpicker_clubs_test DB_USERNAME=clubs DB_PASSWORD=clubs_test \
 *   php artisan test tests/Concurrency
 *
 * Every test wipes and re-migrates that database. To make that impossible
 * anywhere else, the guard below refuses any connection that is not a
 * loopback host with a database name ending in `_test` — the same guard runs
 * in every worker process (worker.php).
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use CreatesClubs;

    protected function setUp(): void
    {
        parent::setUp();

        $problem = self::unsafeTarget();
        if ($problem !== null) {
            $this->markTestSkipped('Concurrency tests need the dedicated MariaDB/MySQL test DB: ' . $problem);
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->enableClubs();
    }

    /** Null when the default connection is a safe throwaway test DB, else the reason. */
    public static function unsafeTarget(): ?string
    {
        $name   = config('database.default');
        $conn   = config("database.connections.{$name}", []);
        $driver = $conn['driver'] ?? '';

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return "driver is {$driver}";
        }
        if (!in_array($conn['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            return 'host is not loopback';
        }
        if (!str_ends_with((string) ($conn['database'] ?? ''), '_test')) {
            return 'database name does not end in _test';
        }

        return null;
    }

    /**
     * Run one worker process per payload, released together on a shared
     * start time, and return their decoded JSON results.
     *
     * @param string|callable(int): string $scenario one scenario, or a per-payload picker
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, array<string, mixed>>
     */
    protected function runWorkers(string|callable $scenario, array $payloads): array
    {
        $startAt = microtime(true) + 1.5;
        $env     = array_merge(getenv(), ['CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync']);
        $procs   = [];

        foreach ($payloads as $i => $payload) {
            $name = is_string($scenario) ? $scenario : $scenario($i);
            $cmd  = [PHP_BINARY, __DIR__ . '/worker.php', $name, json_encode($payload), (string) $startAt];
            $procs[$i] = [
                'proc'  => proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env),
                'pipes' => $pipes,
            ];
        }

        $results = [];
        foreach ($procs as $i => $p) {
            $out = stream_get_contents($p['pipes'][1]);
            $err = stream_get_contents($p['pipes'][2]);
            fclose($p['pipes'][1]);
            fclose($p['pipes'][2]);
            proc_close($p['proc']);

            $decoded = json_decode(trim((string) strrchr("\n" . trim($out), "\n")), true);
            $this->assertIsArray($decoded, "worker {$i} produced no result. stdout: {$out} stderr: {$err}");
            $results[$i] = $decoded;
        }

        return $results;
    }

    /** @param array<int, array<string, mixed>> $results */
    protected function okCount(array $results): int
    {
        return count(array_filter($results, fn ($r) => ($r['ok'] ?? false) === true));
    }
}
