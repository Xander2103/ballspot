<?php

namespace Tests\Feature;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Runs scripts/deploy.sh and scripts/deploy-wrapper.sh in a sandbox: a fake
 * backend directory and shims for sudo, php, git, composer, curl,
 * supervisorctl and systemctl on PATH. Nothing touches the real system.
 *
 * What must hold on production (docs/ops-runbook.md §5.3):
 *  - maintenance mode is lifted on every exit path (failed step, failing
 *    `artisan up`, Ctrl-C), never left on;
 *  - a missing scheduler heartbeat right after the deploy is tolerated
 *    (exit 0), any other failing post-deploy check gives exit 2 with the app up;
 *  - a rollback to a commit without scripts/ still completes;
 *  - the wrapper refuses to start when the scripts are missing.
 */
class DeployScriptTest extends TestCase
{
    private string $root;
    private string $backend;
    private string $bin;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is required');
        }

        $this->root    = sys_get_temp_dir() . '/bp-deploy-' . uniqid();
        $this->backend = $this->root . '/backend';
        $this->bin     = $this->root . '/bin';
        foreach ([$this->backend . '/storage/framework', $this->backend . '/scripts', $this->bin, $this->root . '/tmp'] as $dir) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->backend . '/.env', "APP_ENV=production\n");
        copy(base_path('scripts/fix-permissions.sh'), $this->backend . '/scripts/fix-permissions.sh');
        copy(base_path('scripts/deploy.sh'), $this->backend . '/scripts/deploy.sh');
        chmod($this->backend . '/scripts/fix-permissions.sh', 0755);
        chmod($this->backend . '/scripts/deploy.sh', 0755);

        $this->writeShims();
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            Process::run(['rm', '-rf', $this->root]);
        }
        parent::tearDown();
    }

    private function writeShims(): void
    {
        $shims = [
            // sudo: drop -v / -u USER -H / env K=V…, simulate fix-permissions.sh, exec the rest
            'sudo' => <<<'SH'
#!/usr/bin/env bash
echo "sudo $*" >> "$SHIM_CALLS"
[[ "${1:-}" == "-v" ]] && exit 0
args=("$@")
while [[ ${#args[@]} -gt 0 ]]; do
  case "${args[0]}" in
    -u) args=("${args[@]:2}") ;;
    -H) args=("${args[@]:1}") ;;
    env) args=("${args[@]:1}"); while [[ ${#args[@]} -gt 0 && "${args[0]}" == *=* ]]; do args=("${args[@]:1}"); done ;;
    *) break ;;
  esac
done
case "${args[*]}" in
  *fix-permissions.sh*--check*) echo "fix-permissions --check (${args[1]})"; exit "${SHIM_FIXCHECK_EXIT:-0}" ;;
  *fix-permissions.sh*)         echo "fix-permissions repair (${args[1]})"; exit "${SHIM_FIXPERM_EXIT:-0}" ;;
esac
exec "${args[@]}"
SH,
            // php artisan <command>: maintenance flag files, health, list
            'php' => <<<'SH'
#!/usr/bin/env bash
echo "php $*" >> "$SHIM_CALLS"
shift
fw="$BALLPICKER_BACKEND_DIR/storage/framework"
case "${1:-}" in
  down)            mkdir -p "$fw"; echo '{}' > "$fw/down"; echo '<?php' > "$fw/maintenance.php"; exit 0 ;;
  up)              if [[ "${SHIM_UP_EXIT:-0}" == 0 ]]; then rm -f "$fw/down" "$fw/maintenance.php"; echo "Application is now live."; fi; exit "${SHIM_UP_EXIT:-0}" ;;
  list)            printf 'ballspot:health   Run the health checks\nmigrate           Run migrations\n'; exit 0 ;;
  migrate:status)  echo "Ran"; exit 0 ;;
  ballspot:health) [[ "${2:-}" == "--json" ]] && printf '%s\n' "${SHIM_HEALTH_JSON:-}"; exit "${SHIM_HEALTH_EXIT:-0}" ;;
esac
[[ -n "${SHIM_FAIL_CMD:-}" && "${1:-}" == "$SHIM_FAIL_CMD" ]] && { echo "simulated failure of $1" >&2; exit 1; }
exit 0
SH,
            'git' => <<<'SH'
#!/usr/bin/env bash
echo "git $*" >> "$SHIM_CALLS"
case "$1" in
  rev-parse) [[ "${2:-}" == "--short" ]] && echo "abc1234" || echo "abc1234abc1234abc1234abc1234abc1234abc12"; exit 0 ;;
  status)    exit 0 ;;
  fetch)     exit "${SHIM_GIT_FETCH_EXIT:-0}" ;;
  reset)     [[ "${SHIM_RESET_REMOVES_SCRIPTS:-0}" == 1 ]] && rm -f "$BALLPICKER_BACKEND_DIR/scripts/fix-permissions.sh"; exit 0 ;;
esac
exit 0
SH,
            'composer' => <<<'SH'
#!/usr/bin/env bash
echo "composer $*" >> "$SHIM_CALLS"
if [[ "${SHIM_COMPOSER_INTERRUPTS:-0}" == 1 ]]; then kill -INT $PPID; sleep 1; exit 0; fi
exit "${SHIM_COMPOSER_EXIT:-0}"
SH,
            'curl'          => "#!/usr/bin/env bash\necho '{\"status\":\"ok\"}'\nexit 0\n",
            'supervisorctl' => "#!/usr/bin/env bash\necho \"supervisorctl \$*\" >> \"\$SHIM_CALLS\"\nexit 0\n",
            'systemctl'     => "#!/usr/bin/env bash\necho \"systemctl \$*\" >> \"\$SHIM_CALLS\"\nexit 0\n",
        ];
        foreach ($shims as $name => $body) {
            file_put_contents($this->bin . '/' . $name, $body);
            chmod($this->bin . '/' . $name, 0755);
        }
    }

    /** @param array<string,string|int> $env */
    private function deploy(array $env = [], array $args = []): ProcessResult
    {
        $user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $user = $user ?: trim((string) shell_exec('id -un'));

        return Process::path($this->backend)
            ->timeout(60)
            ->env(array_merge([
                'PATH'                   => $this->bin . ':' . getenv('PATH'),
                'TMPDIR'                 => $this->root . '/tmp',
                'SHIM_CALLS'             => $this->root . '/calls.log',
                'BALLPICKER_APP_ROOT'    => $this->root,
                'BALLPICKER_BACKEND_DIR' => $this->backend,
                'BALLPICKER_WEB_USER'    => $user,
                'BALLPICKER_WEB_URL'     => 'http://127.0.0.1:9',
            ], $env))
            ->run(array_merge(['bash', $this->backend . '/scripts/deploy.sh'], $args));
    }

    private function calls(): string
    {
        return (string) @file_get_contents($this->root . '/calls.log');
    }

    private function assertAppIsUp(): void
    {
        $this->assertFileDoesNotExist($this->backend . '/storage/framework/down', 'maintenance mode was left ON');
        $this->assertFileDoesNotExist($this->backend . '/storage/framework/maintenance.php');
    }

    public function test_a_clean_deploy_exits_zero_runs_every_artisan_step_as_the_web_user_and_lifts_maintenance(): void
    {
        $r = $this->deploy();

        $this->assertSame(0, $r->exitCode(), $r->output() . $r->errorOutput());
        $this->assertStringContainsString('Deploy complete', $r->output());
        $this->assertAppIsUp();

        $calls = $this->calls();
        foreach (['artisan down --retry=30', 'artisan package:discover', 'artisan migrate --force', 'artisan db:seed --class=BadgeSeeder --force',
                  'artisan view:clear', 'artisan config:cache', 'artisan route:cache', 'artisan view:cache', 'artisan queue:restart', 'artisan up'] as $step) {
            $this->assertStringContainsString($step, $calls, "missing step: {$step}");
        }
        $this->assertStringNotContainsString('optimize:clear', $calls);
        $this->assertStringNotContainsString('cache:clear', $calls);
        $this->assertStringNotContainsString('migrate:fresh', $calls);
        // every artisan call goes through sudo -u <web user>
        $this->assertSame(
            substr_count($calls, "\nphp artisan") + (str_starts_with($calls, 'php artisan') ? 1 : 0),
            substr_count($calls, '-H php artisan'),
            'an artisan call ran without sudo -u <web user>'
        );
        $this->assertStringContainsString('fix-permissions repair', $r->output());
        $this->assertStringContainsString('fix-permissions --check', $r->output());
        $this->assertStringContainsString('composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts', $calls);
    }

    public function test_a_failing_step_brings_the_app_back_up_and_exits_one(): void
    {
        $r = $this->deploy(['SHIM_COMPOSER_EXIT' => 1]);

        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('DEPLOY FAILED: composer install failed', $r->errorOutput());
        $this->assertStringContainsString('Bringing the app back up', $r->errorOutput());
        $this->assertStringContainsString('rollback: ' . $this->root . '/deploy.sh --ref=abc1234', $r->errorOutput());
        $this->assertAppIsUp();
        $this->assertStringContainsString('artisan up', $this->calls());
        $this->assertStringNotContainsString('artisan migrate', $this->calls(), 'steps after the failure must not run');
    }

    public function test_when_artisan_up_itself_fails_the_flag_files_are_removed_directly(): void
    {
        $r = $this->deploy(['SHIM_COMPOSER_EXIT' => 1, 'SHIM_UP_EXIT' => 1]);

        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('removing the maintenance flag files directly', $r->errorOutput());
        $this->assertStringContainsString('Maintenance mode lifted (storage/framework/down removed)', $r->errorOutput());
        $this->assertAppIsUp();
        $this->assertStringContainsString('sudo rm -f ' . $this->backend . '/storage/framework/down ' . $this->backend . '/storage/framework/maintenance.php', $this->calls());
    }

    public function test_an_interrupt_during_composer_lifts_maintenance_and_exits_130(): void
    {
        $r = $this->deploy(['SHIM_COMPOSER_INTERRUPTS' => 1]);

        $this->assertSame(130, $r->exitCode(), $r->output() . $r->errorOutput());
        $this->assertStringContainsString('DEPLOY INTERRUPTED', $r->errorOutput());
        $this->assertAppIsUp();
        $this->assertStringNotContainsString('artisan migrate', $this->calls());
    }

    public function test_a_missing_scheduler_heartbeat_right_after_the_deploy_is_tolerated(): void
    {
        $r = $this->deploy([
            'SHIM_HEALTH_EXIT' => 1,
            'SHIM_HEALTH_JSON' => json_encode(['status' => 'degraded', 'checks' => [
                'database' => true, 'storage' => true, 'cache' => true, 'queue' => true, 'scheduler' => false,
            ]], JSON_PRETTY_PRINT),
        ]);

        $this->assertSame(0, $r->exitCode(), $r->output() . $r->errorOutput());
        $this->assertStringContainsString('Only the scheduler heartbeat is missing or stale', $r->output());
        $this->assertStringContainsString('Deploy complete', $r->output());
        $this->assertStringNotContainsString('PROBLEM —', $r->output());
        $this->assertAppIsUp();
    }

    public function test_any_other_failing_health_check_exits_two_with_the_app_up(): void
    {
        $r = $this->deploy([
            'SHIM_HEALTH_EXIT' => 1,
            'SHIM_HEALTH_JSON' => json_encode(['status' => 'degraded', 'checks' => [
                'database' => true, 'storage' => true, 'cache' => false, 'queue' => true, 'scheduler' => false,
            ]], JSON_PRETTY_PRINT),
        ]);

        $this->assertSame(2, $r->exitCode(), $r->output() . $r->errorOutput());
        $this->assertStringContainsString('PROBLEM — failing checks: cache,scheduler', $r->output());
        $this->assertStringContainsString('the app is UP, but 1 post-deploy check(s) failed', $r->output());
        $this->assertStringNotContainsString('DEPLOY FAILED', $r->errorOutput());
        $this->assertAppIsUp();
    }

    public function test_a_failing_permission_check_after_the_deploy_exits_two_and_names_the_repair(): void
    {
        $r = $this->deploy(['SHIM_FIXCHECK_EXIT' => 1]);

        $this->assertSame(2, $r->exitCode(), $r->output() . $r->errorOutput());
        $this->assertStringContainsString('PROBLEM — something still writes to storage/ as another user', $r->output());
        $this->assertStringContainsString('sudo bash ' . $this->backend . '/scripts/fix-permissions.sh', $r->output());
        $this->assertAppIsUp();
        $this->assertStringContainsString('artisan up', $this->calls(), 'maintenance was lifted before the checks');
    }

    public function test_a_failing_permission_repair_before_the_first_artisan_step_aborts_and_lifts_maintenance(): void
    {
        $r = $this->deploy(['SHIM_FIXPERM_EXIT' => 1]);

        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('DEPLOY FAILED: fix-permissions.sh failed', $r->errorOutput());
        $this->assertAppIsUp();
        $this->assertStringNotContainsString('artisan migrate', $this->calls());
    }

    public function test_rolling_back_to_a_commit_without_scripts_uses_the_copy_taken_before_the_reset(): void
    {
        $r = $this->deploy(['SHIM_RESET_REMOVES_SCRIPTS' => 1], ['--ref=bcae3ce']);

        $this->assertSame(0, $r->exitCode(), $r->output() . $r->errorOutput());
        $this->assertStringContainsString('git reset --hard bcae3ce', $this->calls());
        $this->assertStringContainsString('using the copy taken before the reset', $r->output());
        $this->assertStringContainsString('fix-permissions repair (' . $this->root . '/tmp/ballpicker-deploy.', $r->output());
        $this->assertAppIsUp();
        $this->assertFileDoesNotExist($this->backend . '/scripts/fix-permissions.sh');
        $this->assertSame([], glob($this->root . '/tmp/ballpicker-deploy.*') ?: [], 'the temporary copy is removed again');
    }

    public function test_help_and_unknown_flags_never_touch_the_app(): void
    {
        $help = $this->deploy([], ['--help']);
        $this->assertSame(0, $help->exitCode());
        $this->assertStringContainsString('usage: ' . $this->root . '/deploy.sh', $help->output());

        $bad = $this->deploy([], ['--nope']);
        $this->assertSame(1, $bad->exitCode());

        $this->assertSame('', $this->calls(), 'no git/composer/artisan call may happen');
    }

    public function test_the_wrapper_refuses_without_the_scripts_and_hands_over_when_they_exist(): void
    {
        $wrapper = base_path('scripts/deploy-wrapper.sh');
        $env     = ['PATH' => $this->bin . ':' . getenv('PATH'), 'SHIM_CALLS' => $this->root . '/calls.log', 'BALLPICKER_APP_ROOT' => $this->root, 'BALLPICKER_BACKEND_DIR' => $this->backend, 'TMPDIR' => $this->root . '/tmp'];

        $ok = Process::env($env)->run(['bash', $wrapper, '--help']);
        $this->assertSame(0, $ok->exitCode(), $ok->output() . $ok->errorOutput());
        $this->assertStringContainsString('usage: ' . $this->root . '/deploy.sh', $ok->output(), 'the wrapper passes its own path as the rollback entry point');

        chmod($this->backend . '/scripts/deploy.sh', 0644);
        $notExec = Process::env($env)->run(['bash', $wrapper, '--help']);
        $this->assertSame(1, $notExec->exitCode());
        $this->assertStringContainsString('is not executable', $notExec->errorOutput());

        unlink($this->backend . '/scripts/deploy.sh');
        $missing = Process::env($env)->run(['bash', $wrapper, '--help']);
        $this->assertSame(1, $missing->exitCode());
        $this->assertStringContainsString('git checkout origin/master -- backend/scripts', $missing->errorOutput());
    }

    public function test_the_scripts_in_the_repository_are_executable_and_parse(): void
    {
        foreach (['deploy.sh', 'deploy-wrapper.sh', 'fix-permissions.sh', 'monitor-health.sh'] as $script) {
            $path = base_path('scripts/' . $script);
            $this->assertTrue(is_executable($path), "{$script} must be executable (git mode 100755)");
            $this->assertSame(0, Process::run(['bash', '-n', $path])->exitCode(), "{$script} does not parse");
        }
        $deploy = file_get_contents(base_path('scripts/deploy.sh'));
        $this->assertStringEndsWith("main \"\$@\"\n", $deploy, 'the whole file must be parsed before git reset --hard replaces it');
        $this->assertStringContainsString('trap cleanup EXIT', $deploy);
    }
}
