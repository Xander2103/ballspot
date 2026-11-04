<?php

namespace App\Services;

/**
 * Where is THIS PHP process reading its code and configuration from?
 *
 * Built for the 2026-11 "app says Weekly while tinker says monthly" bug: the
 * CLI (tinker, artisan) and the web process (nginx → php-fpm) can disagree
 * when they run a different checkout, a different PHP version/pool, a stale
 * config cache or a process-level env var. The same snapshot is printed by
 * `ballspot:competition-period-audit` (CLI side) and shown on /admin/settings
 * (web side), so the two can be compared line by line.
 *
 * Read-only and admin-only. It returns paths, flags and the competition
 * period — never a secret, a token, a password or a full .env line.
 */
class RuntimeFingerprintService
{
    public const PERIOD_KEY = 'BALLPICKER_COMPETITION_PERIOD';

    public function __construct(private CompetitionPeriodService $period) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $cachePath = app()->getCachedConfigPath();
        $cached    = app()->configurationIsCached();

        return [
            'sapi'                 => PHP_SAPI,
            'php_version'          => PHP_VERSION,
            'php_binary'           => PHP_BINARY,
            'loaded_ini'           => php_ini_loaded_file() ?: null,
            'process_user'         => $this->processUser(),
            'hostname'             => gethostname() ?: null,
            'base_path'            => base_path(),
            'base_path_real'       => realpath(base_path()) ?: null,
            'git_commit'           => $this->gitCommit(),
            'config_cached'        => $cached,
            'config_cache_path'    => $cachePath,
            'config_cache_mtime'   => $cached && is_file($cachePath) ? date('c', (int) filemtime($cachePath)) : null,
            'period_effective'     => $this->period->type(),
            'period_config_raw'    => is_string($raw = config('ballspot.competition.period')) ? $raw : null,
            'period_label'         => $this->period->label(),
            // The same key as seen by the process environment. A value here that
            // differs from the .env file means something outside .env (php-fpm
            // pool env[], fastcgi_param, systemd Environment=) injects it.
            'period_getenv'        => $this->str(getenv(self::PERIOD_KEY)),
            'period_server_var'    => $this->str($_SERVER[self::PERIOD_KEY] ?? null),
            'period_env_var'       => $this->str($_ENV[self::PERIOD_KEY] ?? null),
            'period_dotenv_file'   => $this->dotenvValues(self::PERIOD_KEY),
            'dotenv_path'          => app()->environmentFilePath(),
            'opcache'              => $this->opcache(),
        ];
    }

    private function str(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    /**
     * Every value assigned to $key in the .env file, in order (phpdotenv is
     * immutable: the FIRST one wins, a later duplicate is ignored). Only this
     * key's values are returned — never any other line.
     *
     * @return list<string>|null null when the file cannot be read
     */
    public function dotenvValues(string $key, ?string $path = null): ?array
    {
        $path ??= app()->environmentFilePath();
        if (! is_readable($path)) {
            return null;
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?' . preg_quote($key, '/') . '\s*=\s*(.*)$/', $line, $m)) {
                $v = preg_replace('/\s+#.*$/', '', trim($m[1]));
                $values[] = trim((string) $v, "\"' ");
            }
        }

        return $values;
    }

    private function processUser(): ?string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());

            return is_array($pw) ? ($pw['name'] ?? null) : null;
        }

        return get_current_user() ?: null;
    }

    /** Short commit of the checkout this process runs (repo root = base_path/..). */
    private function gitCommit(): ?string
    {
        foreach ([base_path('.git'), dirname(base_path()) . '/.git'] as $gitDir) {
            $head = @file_get_contents($gitDir . '/HEAD');
            if ($head === false) {
                continue;
            }
            $head = trim($head);
            if (! str_starts_with($head, 'ref: ')) {
                return substr($head, 0, 7);
            }
            $ref  = substr($head, 5);
            $hash = @file_get_contents($gitDir . '/' . $ref);
            if ($hash === false) {
                $packed = @file_get_contents($gitDir . '/packed-refs') ?: '';
                if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m)) {
                    $hash = $m[1];
                }
            }

            return $hash ? substr(trim($hash), 0, 7) : null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function opcache(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return [
            'enabled'            => is_array($status) ? (bool) ($status['opcache_enabled'] ?? false) : false,
            'validate_timestamps'=> ini_get('opcache.validate_timestamps'),
            'revalidate_freq'    => ini_get('opcache.revalidate_freq'),
            'file_cache'         => ini_get('opcache.file_cache') ?: null,
            'start_time'         => is_array($status) && isset($status['opcache_statistics']['start_time'])
                ? date('c', (int) $status['opcache_statistics']['start_time']) : null,
            'last_restart_time'  => is_array($status) && ! empty($status['opcache_statistics']['last_restart_time'])
                ? date('c', (int) $status['opcache_statistics']['last_restart_time']) : null,
        ];
    }
}
