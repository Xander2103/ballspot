<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Services\ImageVariantService;
use Illuminate\Console\Command;

/**
 * Builds the missing/outdated display copies (ImageVariantService) for every
 * challenge photo and pack cover. Additive: it only WRITES new files under
 * .../display/ and the image_variants / cover_image_variant columns. Original
 * uploads, ball positions and every other column are never touched, so it is
 * safe on played challenges and safe to re-run (already-done images are kept).
 *
 *   php artisan ballspot:images-optimize --dry-run   # what would happen
 *   php artisan ballspot:images-optimize             # do it
 *   php artisan ballspot:images-optimize --challenge=12 --force
 */
class ImagesOptimize extends Command
{
    protected $signature = 'ballspot:images-optimize
        {--dry-run : Only count what would be built}
        {--force : Rebuild copies that already exist}
        {--challenge=* : Only these challenge ids}
        {--no-covers : Skip pack covers}';

    protected $description = 'Build downscaled display copies of challenge photos and pack covers (originals untouched)';

    public function handle(): int
    {
        $caps = ImageVariantService::capabilities();
        if (! $caps['gd']) {
            $this->error('PHP GD is not installed (php8.x-gd): nothing can be built. The originals keep being served.');

            return self::FAILURE;
        }
        $this->line('Encoder: ' . ($caps['webp'] ? 'WebP' : 'JPEG (no WebP support in GD)'));

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $maxEdge = (int) config('ballspot.images.challenge_max_edge', 2048);
        $tally = [];
        $before = 0;
        $after = 0;

        $query = Challenge::query()->orderBy('id');
        if ($ids = array_filter(array_map('intval', (array) $this->option('challenge')))) {
            $query->whereIn('id', $ids);
        }

        $query->chunkById(50, function ($chunk) use ($dry, $force, $maxEdge, &$tally, &$before, &$after) {
            foreach ($chunk as $challenge) {
                if ($dry) {
                    foreach (['hidden' => $challenge->hidden_image_path, 'original' => $challenge->original_image_path] as $kind => $src) {
                        if (! $src) {
                            continue;
                        }
                        $ok = ! $force && Challenge::variantMatches(((array) $challenge->image_variants)[$kind] ?? null, $src, $maxEdge);
                        $tally[$ok ? 'kept' : 'would build'] = ($tally[$ok ? 'kept' : 'would build'] ?? 0) + 1;
                    }
                    continue;
                }
                foreach ($challenge->refreshImageVariants($force) as $kind => $status) {
                    if ($status === 'none') {
                        continue;
                    }
                    $tally[$status] = ($tally[$status] ?? 0) + 1;
                    $meta = ((array) $challenge->image_variants)[$kind] ?? null;
                    if (is_array($meta)) {
                        $before += (int) $meta['source_bytes'];
                        $after += (int) $meta['bytes'];
                    }
                    if ($status === 'failed') {
                        $this->warn("  challenge {$challenge->id} {$kind}: no copy (missing file, SVG or undecodable) — original stays in use");
                    }
                }
            }
        });

        if (! $this->option('no-covers') && ! $this->option('challenge')) {
            ChallengePack::query()->whereNotNull('cover_image_path')->orderBy('id')
                ->chunkById(50, function ($chunk) use ($dry, $force, &$tally) {
                    foreach ($chunk as $pack) {
                        $status = $dry
                            ? (! $force && Challenge::variantMatches($pack->cover_image_variant, $pack->cover_image_path, (int) config('ballspot.images.cover_max_edge', 1200)) ? 'kept' : 'would build')
                            : $pack->refreshCoverVariant($force);
                        $tally["cover {$status}"] = ($tally["cover {$status}"] ?? 0) + 1;
                    }
                });
        }

        ksort($tally);
        $this->table(['Result', 'Images'], array_map(fn ($k, $v) => [$k, $v], array_keys($tally), $tally));
        if (! $dry && $before > 0) {
            $this->info(sprintf('Challenge photos: %.1f MB of originals are now served as %.1f MB (%.0f%%).',
                $before / 1048576, $after / 1048576, 100 * $after / $before));
        }
        if ($dry) {
            $this->line('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
