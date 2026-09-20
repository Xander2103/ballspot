<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A PHP file that starts with a UTF-8 byte-order mark (or any whitespace)
 * before `<?php` ECHOES those bytes the moment it is included. For a config,
 * route, lang, middleware or resource file that means every JSON response of
 * an un-cached deployment starts with "\xEF\xBB\xBF{...}" — the mobile app's
 * JSON.parse rejects it, so every screen fails with a "network" error while
 * the HTTP status is a healthy 200. The Laravel test client never sees the
 * bytes (they go to stdout, not into the Response), so this test looks at the
 * files themselves and at what including one of them prints.
 */
class SourceFileHygieneTest extends TestCase
{
    private const DIRS = ['app', 'bootstrap', 'config', 'database', 'lang', 'routes'];

    /** @return list<string> */
    private function phpFiles(): array
    {
        $finder = (new Finder())->files()->name('*.php')->in(array_map(fn ($d) => base_path($d), self::DIRS));
        $paths = [];
        foreach ($finder as $file) {
            $paths[] = $file->getRealPath();
        }
        sort($paths);

        return $paths;
    }

    public function test_no_php_source_file_starts_with_a_bom_or_whitespace(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $path) {
            $head = (string) file_get_contents($path, false, null, 0, 8);
            if (!str_starts_with($head, '<?php')) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path) . ' starts with ' . bin2hex(substr($head, 0, 3));
            }
        }

        $this->assertSame([], $offenders, 'These files emit bytes before any response body:');
    }

    public function test_including_every_config_file_prints_nothing(): void
    {
        // What php-fpm would send ahead of the JSON body when config is not cached.
        foreach (glob(base_path('config/*.php')) as $file) {
            ob_start();
            require $file;
            $printed = (string) ob_get_clean();

            $this->assertSame('', $printed, basename($file) . ' printed ' . bin2hex($printed) . ' on include');
        }
    }
}
