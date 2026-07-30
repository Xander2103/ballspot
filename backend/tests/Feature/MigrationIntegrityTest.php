<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Static analysis of the migration set.
 *
 * SQLite (used by the test suite) resolves foreign key targets lazily: it will
 * happily create a table whose FK points at a table that does not exist yet,
 * and only complains at INSERT time — by which point the parent table exists.
 * MySQL/MariaDB resolve them eagerly and abort the deploy with
 * errno 150 "Foreign key constraint is incorrectly formed".
 *
 * These tests reproduce MariaDB's strictness without needing a MariaDB server,
 * so an ordering regression fails locally instead of on the deploy box.
 */
class MigrationIntegrityTest extends TestCase
{
    /**
     * Migrations in the exact order Laravel's Migrator runs them
     * (sorted by migration name — see Migrator::getMigrationFiles()).
     *
     * @return array<int, array{name: string, path: string}>
     */
    private function migrationsInRunOrder(): array
    {
        $paths = glob(database_path('migrations/*.php'));
        $migrations = [];

        foreach ($paths as $path) {
            $migrations[] = ['name' => basename($path, '.php'), 'path' => $path];
        }

        usort($migrations, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $migrations;
    }

    /**
     * Schema events in source order for one migration file.
     *
     * Returns entries of ['offset' => int, 'type' => 'create'|'fk', ...] so a
     * file that creates a parent and then a child pivot table is evaluated in
     * the order the statements actually execute.
     *
     * @return array<int, array<string, mixed>>
     */
    private function schemaEvents(string $contents): array
    {
        $events = [];

        // Schema::create('table', ...)
        preg_match_all("/Schema::create\(\s*'([^']+)'/", $contents, $creates, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($creates as $match) {
            $events[] = [
                'offset' => $match[0][1],
                'type' => 'create',
                'table' => $match[1][0],
            ];
        }

        // $table->foreignId('x_id')->...->constrained('parent')
        // The [^;]*? bound keeps the match inside a single statement, so a
        // foreignId() without constrained() cannot borrow the next one's.
        preg_match_all(
            "/->foreignId\(\s*'([^']+)'\s*\)([^;]*?)->constrained\(\s*(?:'([^']+)')?\s*[,)]/s",
            $contents,
            $constrained,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );
        foreach ($constrained as $match) {
            $column = $match[1][0];
            $explicit = $match[3][0] ?? '';

            $events[] = [
                'offset' => $match[0][1],
                'type' => 'fk',
                'column' => $column,
                // constrained() with no argument infers the parent table the
                // same way Laravel does: strip _id, then pluralise.
                'references' => $explicit !== '' ? $explicit : Str::plural(Str::beforeLast($column, '_id')),
            ];
        }

        // $table->foreign('x')->references('id')->on('parent')
        preg_match_all(
            "/->foreign\(\s*'([^']+)'\s*\)[^;]*?->on\(\s*'([^']+)'\s*\)/s",
            $contents,
            $explicitFks,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );
        foreach ($explicitFks as $match) {
            $events[] = [
                'offset' => $match[0][1],
                'type' => 'fk',
                'column' => $match[1][0],
                'references' => $match[2][0],
            ];
        }

        usort($events, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        return $events;
    }

    public function test_every_foreign_key_targets_a_table_created_by_an_earlier_statement(): void
    {
        $created = [];
        $violations = [];

        foreach ($this->migrationsInRunOrder() as $migration) {
            $contents = file_get_contents($migration['path']);

            foreach ($this->schemaEvents($contents) as $event) {
                if ($event['type'] === 'create') {
                    $created[$event['table']] = $migration['name'];

                    continue;
                }

                if (! isset($created[$event['references']])) {
                    $violations[] = sprintf(
                        '%s: foreign key on "%s" references "%s", which no earlier migration has created.',
                        $migration['name'],
                        $event['column'],
                        $event['references']
                    );
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "Migration order is not MySQL/MariaDB safe. MariaDB rejects these with\n".
            "errno 150 \"Foreign key constraint is incorrectly formed\":\n\n%s\n",
            implode("\n", $violations)
        ));
    }

    public function test_foreign_key_columns_match_the_referenced_primary_key_type(): void
    {
        // Parent tables declared with $table->id() get BIGINT UNSIGNED, so every
        // child column must be foreignId()/unsignedBigInteger(). MariaDB also
        // reports a type mismatch as errno 150.
        $bigIntParents = [];
        $mismatches = [];

        foreach ($this->migrationsInRunOrder() as $migration) {
            $contents = file_get_contents($migration['path']);

            preg_match_all(
                "/Schema::create\(\s*'([^']+)'.*?\\\$table->(id|bigIncrements)\(/s",
                $contents,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                $bigIntParents[$match[1]] = true;
            }
        }

        foreach ($this->migrationsInRunOrder() as $migration) {
            $contents = file_get_contents($migration['path']);

            foreach ($this->schemaEvents($contents) as $event) {
                if ($event['type'] !== 'fk' || ! isset($bigIntParents[$event['references']])) {
                    continue;
                }

                $declaration = sprintf("/->(foreignId|unsignedBigInteger)\(\s*'%s'\s*\)/", preg_quote($event['column'], '/'));

                if (! preg_match($declaration, $contents)) {
                    $mismatches[] = sprintf(
                        '%s: "%s" references %s.id (BIGINT UNSIGNED) but is not declared as foreignId()/unsignedBigInteger().',
                        $migration['name'],
                        $event['column'],
                        $event['references']
                    );
                }
            }
        }

        $this->assertSame([], $mismatches, implode("\n", $mismatches));
    }

    /**
     * End-to-end check against a real MySQL/MariaDB server.
     *
     * Opt-in only, and DESTRUCTIVE: it runs migrate:fresh, so the target
     * database is dropped and rebuilt. Point it at a throwaway schema — never
     * at staging or production. Enable with:
     *
     *   MIGRATION_TEST_MYSQL_HOST=127.0.0.1 MIGRATION_TEST_MYSQL_PORT=3306 \
     *   MIGRATION_TEST_MYSQL_DATABASE=ballpicker_migration_test \
     *   MIGRATION_TEST_MYSQL_USERNAME=root MIGRATION_TEST_MYSQL_PASSWORD=secret \
     *   php artisan test --filter=migrations_apply_cleanly
     */
    public function test_migrations_apply_cleanly_on_a_mysql_compatible_server(): void
    {
        $host = env('MIGRATION_TEST_MYSQL_HOST');

        if (! $host) {
            $this->markTestSkipped(
                'Set MIGRATION_TEST_MYSQL_HOST (plus _PORT/_DATABASE/_USERNAME/_PASSWORD) to run '.
                'migrations against a real MySQL/MariaDB server. The target database is dropped '.
                'and rebuilt, so use a throwaway schema only.'
            );
        }

        $database = env('MIGRATION_TEST_MYSQL_DATABASE', 'ballpicker_migration_test');

        config()->set('database.connections.migration_test', [
            'driver' => 'mariadb',
            'host' => $host,
            'port' => env('MIGRATION_TEST_MYSQL_PORT', '3306'),
            'database' => $database,
            'username' => env('MIGRATION_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('MIGRATION_TEST_MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);

        // Fails with errno 150 if any FK is created before its parent table.
        Artisan::call('migrate:fresh', [
            '--database' => 'migration_test',
            '--force' => true,
        ]);

        // Prove the constraints were really created rather than quietly skipped.
        $foreignKeys = DB::connection('migration_test')
            ->table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('REFERENCED_TABLE_NAME', 'CONSTRAINT_NAME')
            ->all();

        $this->assertArrayHasKey('challenges_sport_id_foreign', $foreignKeys);
        $this->assertSame('sports', $foreignKeys['challenges_sport_id_foreign']);
        $this->assertArrayHasKey('guesses_league_round_id_foreign', $foreignKeys);
        $this->assertArrayHasKey('league_members_league_id_foreign', $foreignKeys);
        $this->assertArrayHasKey('league_rounds_league_id_foreign', $foreignKeys);
    }

    public function test_migration_names_are_unique_and_unambiguously_ordered(): void
    {
        // Two migrations sharing a timestamp are ordered by the rest of the
        // filename alphabetically — which is how create_challenges_table came
        // to run before create_sports_table. Keep timestamps distinct so run
        // order is explicit rather than accidental.
        $timestamps = [];

        foreach ($this->migrationsInRunOrder() as $migration) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $migration['name'], $match)) {
                $timestamps[$match[1]][] = $migration['name'];
            }
        }

        $collisions = array_filter($timestamps, fn ($names) => count($names) > 1);

        $this->assertSame([], $collisions, sprintf(
            "Migrations share a timestamp, so their run order depends on alphabetical filename sorting:\n%s",
            json_encode($collisions, JSON_PRETTY_PRINT)
        ));
    }
}
