<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Services\ImageVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * READ-ONLY report of what the app downloads: size, dimensions, format and
 * EXIF orientation of every challenge photo (guess + reveal) and pack cover,
 * and how many already have a display copy. Never writes a file or a row.
 *
 *   php artisan ballspot:images-audit
 *   php artisan ballspot:images-audit --json
 */
class ImagesAudit extends Command
{
    protected $signature = 'ballspot:images-audit {--json : Machine-readable output}';

    protected $description = 'Read-only: sizes/dimensions of challenge + cover images and display-copy coverage';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $groups = [
            'guess (hidden)'  => ['rows' => [], 'variant' => []],
            'reveal (original)' => ['rows' => [], 'variant' => []],
            'pack cover'      => ['rows' => [], 'variant' => []],
        ];

        Challenge::query()->select(['id', 'hidden_image_path', 'original_image_path', 'image_variants'])
            ->orderBy('id')->chunkById(200, function ($chunk) use (&$groups, $disk) {
                foreach ($chunk as $c) {
                    $variants = (array) $c->image_variants;
                    $this->collect($groups['guess (hidden)'], $disk, $c->hidden_image_path, $variants['hidden'] ?? null);
                    $this->collect($groups['reveal (original)'], $disk, $c->original_image_path, $variants['original'] ?? null);
                }
            });
        ChallengePack::query()->select(['id', 'cover_image_path', 'cover_image_variant'])
            ->orderBy('id')->chunkById(200, function ($chunk) use (&$groups, $disk) {
                foreach ($chunk as $p) {
                    $this->collect($groups['pack cover'], $disk, $p->cover_image_path, $p->cover_image_variant);
                }
            });

        $report = [
            'capabilities' => ImageVariantService::capabilities(),
            'config' => [
                'serve_variants'     => (bool) config('ballspot.images.serve_variants', true),
                'generate_on_upload' => (bool) config('ballspot.images.generate_on_upload', true),
                'challenge_max_edge' => (int) config('ballspot.images.challenge_max_edge', 2048),
                'cover_max_edge'     => (int) config('ballspot.images.cover_max_edge', 1200),
            ],
            'groups' => array_map(fn ($g) => $this->summarise($g), $groups),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $caps = $report['capabilities'];
        $this->info('Image audit (read-only)');
        $this->line(sprintf('GD: %s · WebP encoder: %s · JPEG encoder: %s · exif ext: %s',
            $caps['gd'] ? 'yes' : 'NO', $caps['webp'] ? 'yes' : 'no', $caps['jpeg'] ? 'yes' : 'no', $caps['exif_ext'] ? 'yes' : 'no (not needed)'));
        $this->line(sprintf('Serve display copies: %s · build on upload: %s',
            $report['config']['serve_variants'] ? 'on' : 'OFF', $report['config']['generate_on_upload'] ? 'on' : 'OFF'));
        $this->newLine();

        $rows = [];
        foreach ($report['groups'] as $name => $s) {
            $rows[] = [
                $name, $s['files'], $s['missing'],
                self::mb($s['avg_bytes']), self::mb($s['p90_bytes']), self::mb($s['max_bytes']),
                $s['max_edge_px'] ? $s['max_edge_px'] . ' px' : '-', $s['over_2048_px'], $s['exif_rotated'],
                implode(' ', array_map(fn ($k, $v) => "$k:$v", array_keys($s['formats']), $s['formats'])),
                $s['with_variant'] . '/' . $s['files'],
                self::mb($s['served_avg_bytes']),
            ];
        }
        $this->table(
            ['Images', 'Files', 'Missing', 'Avg', 'P90', 'Max', 'Largest edge', '>2048px', 'EXIF-rotated', 'Formats', 'Display copy', 'Avg served now'],
            $rows,
        );

        $g = $report['groups']['guess (hidden)'];
        $r = $report['groups']['reveal (original)'];
        $this->line(sprintf(
            'One play (guess + reveal) downloads on average %s now; %s with the original uploads.',
            self::mb($g['served_avg_bytes'] + $r['served_avg_bytes']),
            self::mb($g['avg_bytes'] + $r['avg_bytes']),
        ));
        if ($g['files'] + $r['files'] > $g['with_variant'] + $r['with_variant']) {
            $this->warn('Some images have no display copy yet: run php artisan ballspot:images-optimize');
        }

        return self::SUCCESS;
    }

    private function collect(array &$group, $disk, ?string $path, mixed $variant): void
    {
        if (! $path) {
            return;
        }
        if (! $disk->exists($path)) {
            $group['rows'][] = ['missing' => true];

            return;
        }
        $full = $disk->path($path);
        $bytes = (int) @filesize($full);
        $info = @getimagesize($full);
        $mime = $info['mime'] ?? (str_ends_with(strtolower($path), '.svg') ? 'image/svg+xml' : 'unknown');
        $orientation = 1;
        if ($mime === 'image/jpeg') {
            $head = @file_get_contents($full, false, null, 0, 256 * 1024);
            $orientation = is_string($head) ? ImageVariantService::jpegOrientation($head) : 1;
        }
        $valid = Challenge::variantMatches($variant, $path);
        $variantPath = $valid ? ($variant['path'] ?? null) : null;
        $variantOk = $valid && (! $variantPath || $disk->exists($variantPath));

        $group['rows'][] = [
            'missing'     => false,
            'bytes'       => $bytes,
            'edge'        => $info ? max((int) $info[0], (int) $info[1]) : 0,
            'format'      => match ($mime) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', default => 'other',
            },
            'rotated'     => $orientation !== 1,
            'has_variant' => $variantOk,
            'served'      => $variantOk && $variantPath ? (int) ($variant['bytes'] ?? $bytes) : $bytes,
        ];
    }

    private function summarise(array $group): array
    {
        $present = array_values(array_filter($group['rows'], fn ($r) => ! $r['missing']));
        $bytes = array_map(fn ($r) => $r['bytes'], $present);
        sort($bytes);
        $n = count($bytes);
        $formats = [];
        foreach ($present as $r) {
            $formats[$r['format']] = ($formats[$r['format']] ?? 0) + 1;
        }

        return [
            'files'            => $n,
            'missing'          => count($group['rows']) - $n,
            'total_bytes'      => array_sum($bytes),
            'avg_bytes'        => $n ? (int) round(array_sum($bytes) / $n) : 0,
            'p50_bytes'        => $n ? $bytes[(int) floor(($n - 1) * 0.5)] : 0,
            'p90_bytes'        => $n ? $bytes[(int) floor(($n - 1) * 0.9)] : 0,
            'max_bytes'        => $n ? $bytes[$n - 1] : 0,
            'max_edge_px'      => $n ? max(array_map(fn ($r) => $r['edge'], $present)) : 0,
            'over_2048_px'     => count(array_filter($present, fn ($r) => $r['edge'] > 2048)),
            'exif_rotated'     => count(array_filter($present, fn ($r) => $r['rotated'])),
            'formats'          => $formats,
            'with_variant'     => count(array_filter($present, fn ($r) => $r['has_variant'])),
            'served_avg_bytes' => $n ? (int) round(array_sum(array_map(fn ($r) => $r['served'], $present)) / $n) : 0,
        ];
    }

    private static function mb(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.2f MB', $bytes / 1048576) : sprintf('%d KB', (int) round($bytes / 1024));
    }
}
