<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only check of stored usernames, to run before deciding on any data fix
 * or constraint. Prints counts and user ids only, never a username, name or email.
 */
class AuditUsernames extends Command
{
    protected $signature = 'ballspot:audit-usernames';

    protected $description = 'Report username data issues (read-only; prints user ids only)';

    private const MAX_IDS_SHOWN = 50;

    public function handle(): int
    {
        $found = [
            'empty'                      => [],
            'surrounding_spaces_or_at'   => [],
            'email_shaped'               => [],
            'equals_entered_name'        => [],
            'format_not_allowed_for_new' => [],
            'reserved_pattern_on_active' => [],
        ];
        $byLower = [];

        DB::table('users')->select(['id', 'username', 'name', 'anonymized_at'])->orderBy('id')
            ->chunk(500, function ($users) use (&$found, &$byLower) {
                foreach ($users as $user) {
                    if ($user->anonymized_at !== null) {
                        continue; // deleted-{id} placeholders are correct by design
                    }

                    $id  = (int) $user->id;
                    $raw = (string) $user->username;
                    $normalized = User::normalizeUsername($raw);

                    if ($normalized === '') {
                        $found['empty'][] = $id;
                        continue;
                    }

                    $byLower[mb_strtolower($normalized)][] = $id;

                    if ($raw !== trim($raw) || str_starts_with(trim($raw), '@')) {
                        $found['surrounding_spaces_or_at'][] = $id;
                    }
                    if (preg_match('/[^@\s]@[^@\s]/', $normalized)) {
                        $found['email_shaped'][] = $id;
                    }
                    if (mb_strtolower($normalized) === mb_strtolower(trim((string) $user->name))) {
                        $found['equals_entered_name'][] = $id;
                    }
                    if (!preg_match(User::USERNAME_PATTERN, $normalized)) {
                        $found['format_not_allowed_for_new'][] = $id;
                    }
                    if (preg_match(User::RESERVED_USERNAME_PATTERN, $normalized)) {
                        $found['reserved_pattern_on_active'][] = $id;
                    }
                }
            });

        $duplicates = array_values(array_filter($byLower, fn (array $ids) => count($ids) > 1));

        $this->line('Unique index on users.username: ' . ($this->hasUniqueIndex() ? 'yes' : 'NO'));
        $this->line('Case-insensitive duplicate groups: ' . count($duplicates));
        foreach (array_slice($duplicates, 0, self::MAX_IDS_SHOWN) as $ids) {
            $this->line('  ids: ' . implode(', ', $ids));
        }

        foreach ($found as $label => $ids) {
            $shown = array_slice($ids, 0, self::MAX_IDS_SHOWN);
            $this->line(sprintf('%s: %d%s', $label, count($ids), $shown === [] ? '' : ' (ids: ' . implode(', ', $shown) . ')'));
        }

        return self::SUCCESS;
    }

    private function hasUniqueIndex(): bool
    {
        foreach (Schema::getIndexes('users') as $index) {
            if ($index['unique'] && $index['columns'] === ['username']) {
                return true;
            }
        }

        return false;
    }
}
