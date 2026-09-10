<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\LeagueRound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Admin-only export of challenge media (guess image = hidden_image_path, the
 * photo WITHOUT the ball; reveal image = original_image_path, WITH the ball)
 * as one ZIP with a manifest.
 *
 * Read-only: nothing is ever written to challenges. Files are resolved on
 * the public disk only, through Storage::disk('public')->path(), and every
 * resolved path must still sit inside that disk's root — a stored path with
 * `..` or an absolute path is treated as missing, never opened. Missing
 * files are reported in the manifest (`missing_files`) instead of failing
 * the export. The ZIP is built in storage/app/exports and deleted after the
 * download.
 *
 * ZIP layout:
 *   ballpicker-media-export/
 *     manifest.json
 *     manifest.csv
 *     challenges/{sport-slug}/{usage-pool}/challenge-{id}-{safe-title}/
 *       guess.{ext}  reveal.{ext}  metadata.json
 */
class MediaExportService
{
    public const DISK      = 'public';
    public const ROOT_DIR  = 'ballpicker-media-export';
    public const IMAGE_ANY = 'both';

    public const FILTER_DEFAULTS = [
        'sport'          => '',     // '' | sport id
        'usage_pool'     => '',     // '' | daily|tournament|pack|general
        'status'         => '',     // '' | draft|active|archived
        'used_as_daily'  => '',     // '' | yes | no
        'pack'           => '',     // '' | any | none | pack id
        'tournament'     => '',     // '' | used | not_used
        'image_type'     => 'both', // guess | reveal | both
        'difficulty'     => '',     // '' | easy|medium|hard
        'created_from'   => '',
        'created_to'     => '',
        'updated_from'   => '',
        'updated_to'     => '',
        'search'         => '',
    ];

    /** Only what the filter form knows; anything else is dropped. */
    public function normalizeFilters(array $input): array
    {
        $f = [];
        foreach (self::FILTER_DEFAULTS as $key => $default) {
            $value = $input[$key] ?? $default;
            $f[$key] = is_scalar($value) ? trim((string) $value) : $default;
        }
        if (!in_array($f['image_type'], ['guess', 'reveal', 'both'], true)) {
            $f['image_type'] = 'both';
        }
        if ($f['usage_pool'] !== '' && !in_array($f['usage_pool'], Challenge::POOLS, true)) {
            $f['usage_pool'] = '';
        }
        if ($f['status'] !== '' && !in_array($f['status'], ['draft', 'active', 'archived'], true)) {
            $f['status'] = '';
        }
        if ($f['difficulty'] !== '' && !in_array($f['difficulty'], ['easy', 'medium', 'hard'], true)) {
            $f['difficulty'] = '';
        }
        foreach (['created_from', 'created_to', 'updated_from', 'updated_to'] as $d) {
            if ($f[$d] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$d])) {
                $f[$d] = '';
            }
        }
        $f['search'] = Str::limit($f['search'], 100, '');

        return $f;
    }

    public function query(array $filters): Builder
    {
        $f = $this->normalizeFilters($filters);

        return Challenge::query()
            ->with(['sport', 'category', 'subcategories', 'packs', 'dailyChallenges'])
            ->withCount(['dailyChallenges', 'packs'])
            ->when($f['sport'] !== '', fn ($q) => $q->where('sport_id', (int) $f['sport']))
            ->when($f['usage_pool'] !== '', fn ($q) => $q->where('usage_pool', $f['usage_pool']))
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))
            ->when($f['difficulty'] !== '', fn ($q) => $q->where('difficulty', $f['difficulty']))
            ->when($f['used_as_daily'] === 'yes', fn ($q) => $q->dailyUsed())
            ->when($f['used_as_daily'] === 'no', fn ($q) => $q->notDailyUsed())
            ->when($f['pack'] === 'any', fn ($q) => $q->whereHas('packs'))
            ->when($f['pack'] === 'none', fn ($q) => $q->whereDoesntHave('packs'))
            ->when($f['pack'] !== '' && ctype_digit($f['pack']), fn ($q) => $q->whereHas('packs', fn ($p) => $p->where('challenge_packs.id', (int) $f['pack'])))
            ->when($f['tournament'] === 'used', fn ($q) => $q->whereIn('id', LeagueRound::query()->select('challenge_id')))
            ->when($f['tournament'] === 'not_used', fn ($q) => $q->whereNotIn('id', LeagueRound::query()->select('challenge_id')))
            ->when($f['image_type'] === 'guess', fn ($q) => $q->whereNotNull('hidden_image_path'))
            ->when($f['image_type'] === 'reveal', fn ($q) => $q->whereNotNull('original_image_path'))
            ->when($f['image_type'] === 'both', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('hidden_image_path')->orWhereNotNull('original_image_path')))
            ->when($f['created_from'] !== '', fn ($q) => $q->whereDate('created_at', '>=', $f['created_from']))
            ->when($f['created_to'] !== '', fn ($q) => $q->whereDate('created_at', '<=', $f['created_to']))
            ->when($f['updated_from'] !== '', fn ($q) => $q->whereDate('updated_at', '>=', $f['updated_from']))
            ->when($f['updated_to'] !== '', fn ($q) => $q->whereDate('updated_at', '<=', $f['updated_to']))
            ->when($f['search'] !== '', fn ($q) => $q->where('title', 'like', '%' . $f['search'] . '%'))
            ->orderBy('sport_id')->orderBy('usage_pool')->orderBy('id');
    }

    /**
     * Counts the page shows before anything is generated.
     *
     * @return array{challenges:int, guess_images:int, reveal_images:int, missing:int, files:int, bytes:int, max_files:int, too_many:bool}
     */
    public function summary(array $filters): array
    {
        $f = $this->normalizeFilters($filters);
        $out = ['challenges' => 0, 'guess_images' => 0, 'reveal_images' => 0, 'missing' => 0, 'files' => 0, 'bytes' => 0];

        $this->query($f)->chunkById(200, function (Collection $rows) use (&$out, $f) {
            foreach ($rows as $challenge) {
                $out['challenges']++;
                foreach ($this->filesFor($challenge, $f['image_type']) as $file) {
                    if ($file['exists']) {
                        $out[$file['kind'] === 'guess' ? 'guess_images' : 'reveal_images']++;
                        $out['files']++;
                        $out['bytes'] += $file['bytes'];
                    } else {
                        $out['missing']++;
                    }
                }
            }
        });

        $out['max_files'] = $this->maxFiles();
        $out['too_many']  = $out['files'] > $out['max_files'];

        return $out;
    }

    public function preview(array $filters, int $limit = 20): Collection
    {
        return $this->query($filters)->limit($limit)->get();
    }

    public function maxFiles(): int
    {
        return max(1, (int) config('ballspot.media_export.max_files', 500));
    }

    /**
     * Build the ZIP and return its absolute path (inside storage/app/exports).
     * The caller streams it and deletes it afterwards.
     *
     * @throws \RuntimeException when nothing matches, the cap is exceeded or the zip cannot be written
     */
    public function build(array $filters, ?int $adminUserId = null): string
    {
        $f       = $this->normalizeFilters($filters);
        $summary = $this->summary($f);

        if ($summary['challenges'] === 0) {
            throw new \RuntimeException('No challenges match these filters — nothing to export.');
        }
        if ($summary['too_many']) {
            throw new \RuntimeException("This selection has {$summary['files']} files; the export limit is {$summary['max_files']}. Narrow the filters (sport, pool, date range) and try again.");
        }

        $dir = storage_path('app/exports');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the export folder.');
        }
        $this->cleanupStale($dir);

        $stamp    = now()->format('Y-m-d-Hi');
        $filename = "ballpicker-media-export-{$stamp}.zip";
        $path     = $dir . DIRECTORY_SEPARATOR . Str::random(12) . '-' . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the ZIP file.');
        }

        $manifest = [];
        $missing  = [];
        $this->query($f)->chunkById(100, function (Collection $rows) use ($zip, $f, &$manifest, &$missing) {
            foreach ($rows as $challenge) {
                $meta   = $this->metadata($challenge);
                $folder = self::ROOT_DIR . '/challenges/' . ($meta['sport_slug'] ?: 'no-sport') . '/' . $meta['usage_pool'] . '/challenge-' . $challenge->id . '-' . $this->safeTitle($challenge->title);

                $exported = [];
                foreach ($this->filesFor($challenge, $f['image_type']) as $file) {
                    if ($file['exists']) {
                        $name = $file['kind'] . '.' . $file['ext'];
                        $zip->addFile($file['absolute'], $folder . '/' . $name);
                        $exported[$file['kind']] = $name;
                    } else {
                        $missing[] = ['challenge_id' => $challenge->id, 'kind' => $file['kind'], 'path' => $file['relative']];
                    }
                }
                $meta['exported_files'] = $exported;
                $meta['missing_files']  = array_values(array_filter(['guess', 'reveal'], fn ($k) => $this->wants($k, $f['image_type']) && $meta['has_' . $k . '_image'] && !isset($exported[$k])));

                $zip->addFromString($folder . '/metadata.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $manifest[] = $meta;
            }
        });

        $header = [
            'generated_at'  => now()->toIso8601String(),
            'app'           => (string) config('ballspot.app_name', 'BallPicker'),
            'version'       => (string) config('ballspot.version', 'v1'),
            'filters'       => $f,
            'challenge_count' => count($manifest),
            'missing_count' => count($missing),
            'missing_files' => $missing,
            'challenges'    => $manifest,
        ];
        $zip->addFromString(self::ROOT_DIR . '/manifest.json', json_encode($header, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString(self::ROOT_DIR . '/manifest.csv', $this->csv($manifest));

        if (!$zip->close()) {
            @unlink($path);
            throw new \RuntimeException('Could not finish writing the ZIP file.');
        }

        \App\Support\AppLog::event('admin.media_export', [
            'admin_user_id'   => $adminUserId,
            'challenge_count' => count($manifest),
            'file_count'      => $summary['files'],
            'missing_count'   => count($missing),
            'bytes'           => $summary['bytes'],
        ]);

        return $path;
    }

    /** The download name (without the random prefix used on disk). */
    public function downloadName(string $path): string
    {
        return preg_replace('/^[A-Za-z0-9]{12}-/', '', basename($path)) ?: basename($path);
    }

    // ---------------------------------------------------------------- internals

    private function wants(string $kind, string $imageType): bool
    {
        return $imageType === 'both' || $imageType === $kind;
    }

    /**
     * @return list<array{kind:string, relative:string, absolute:?string, exists:bool, bytes:int, ext:string}>
     */
    public function filesFor(Challenge $challenge, string $imageType = 'both'): array
    {
        $out = [];
        foreach (['guess' => $challenge->hidden_image_path, 'reveal' => $challenge->original_image_path] as $kind => $relative) {
            if (!$relative || !$this->wants($kind, $imageType)) {
                continue;
            }
            $absolute = $this->resolve((string) $relative);
            $exists   = $absolute !== null && is_file($absolute);
            $out[] = [
                'kind'     => $kind,
                'relative' => (string) $relative,
                'absolute' => $exists ? $absolute : null,
                'exists'   => $exists,
                'bytes'    => $exists ? (int) filesize($absolute) : 0,
                'ext'      => strtolower(pathinfo((string) $relative, PATHINFO_EXTENSION) ?: 'jpg'),
            ];
        }

        return $out;
    }

    /**
     * Absolute path on the public disk, or null when the stored path tries to
     * leave it (traversal, absolute path, stream wrapper).
     */
    public function resolve(string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('#^[a-z]+:#i', $relative)) {
            return null;
        }

        $root = realpath(Storage::disk(self::DISK)->path(''));
        $full = realpath(Storage::disk(self::DISK)->path($relative));
        if ($root === false || $full === false) {
            return null;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($full, $root) ? $full : null;
    }

    public function metadata(Challenge $challenge): array
    {
        $dailyDates = $challenge->dailyChallenges->pluck('challenge_date')->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)->sort()->values()->all();
        $packNames  = $challenge->packs->pluck('name')->values()->all();
        $roundCount = LeagueRound::where('challenge_id', $challenge->id)->count();

        return [
            'challenge_id'        => $challenge->id,
            'title'               => (string) $challenge->title,
            'sport'               => $challenge->sport?->name,
            'sport_slug'          => $challenge->sport?->slug,
            'usage_pool'          => (string) $challenge->usage_pool,
            'status'              => (string) $challenge->status,
            'difficulty'          => (string) $challenge->difficulty,
            'category'            => $challenge->category?->name,
            'subcategories'       => $challenge->subcategories->pluck('name')->values()->all(),
            'has_guess_image'     => (bool) $challenge->hidden_image_path,
            'has_reveal_image'    => (bool) $challenge->original_image_path,
            // Storage-relative (public disk) — never a server path.
            'guess_image_path'    => $challenge->hidden_image_path,
            'reveal_image_path'   => $challenge->original_image_path,
            'used_as_daily'       => $dailyDates !== [],
            'daily_dates'         => $dailyDates,
            'used_in_packs'       => $packNames !== [],
            'pack_names'          => $packNames,
            'used_in_tournaments' => $roundCount > 0,
            'tournament_round_count' => $roundCount,
            'created_at'          => $challenge->created_at?->toIso8601String(),
            'updated_at'          => $challenge->updated_at?->toIso8601String(),
        ];
    }

    private function csv(array $rows): string
    {
        $columns = ['id', 'title', 'sport', 'sport_slug', 'usage_pool', 'status', 'difficulty', 'has_guess_image', 'has_reveal_image', 'used_as_daily', 'used_in_packs', 'pack_names', 'used_in_tournaments', 'missing_files', 'created_at', 'updated_at'];
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $columns);
        foreach ($rows as $m) {
            fputcsv($h, [
                $m['challenge_id'], $m['title'], $m['sport'], $m['sport_slug'], $m['usage_pool'], $m['status'], $m['difficulty'],
                $m['has_guess_image'] ? 'yes' : 'no', $m['has_reveal_image'] ? 'yes' : 'no',
                $m['used_as_daily'] ? 'yes' : 'no', $m['used_in_packs'] ? 'yes' : 'no', implode('; ', $m['pack_names']),
                $m['used_in_tournaments'] ? 'yes' : 'no', implode('; ', $m['missing_files'] ?? []),
                $m['created_at'], $m['updated_at'],
            ]);
        }
        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);

        return $csv;
    }

    private function safeTitle(?string $title): string
    {
        $slug = Str::slug((string) $title);

        return $slug !== '' ? Str::limit($slug, 40, '') : 'untitled';
    }

    /** Exports older than an hour are leftovers from an interrupted download. */
    private function cleanupStale(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $file) {
            if (@filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    /** Options for the filter form. */
    public function packOptions(): Collection
    {
        return ChallengePack::orderBy('name')->get(['id', 'name']);
    }
}
