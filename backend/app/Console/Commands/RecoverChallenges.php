<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\Sport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RecoverChallenges extends Command
{
    protected $signature = 'ballspot:recover-challenges {--dry-run : Show what would be recovered without writing anything}';
    protected $description = 'Recover orphaned uploaded images as draft challenge records';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no records will be created.');
        }

        $this->info('Scanning storage/app/public for orphaned images...');
        $this->newLine();

        // Collect all image files in public storage
        $allImages = $this->scanImages();

        if (empty($allImages)) {
            $this->info('No image files found in storage/app/public.');
            return self::SUCCESS;
        }

        $this->line("Found " . count($allImages) . " image file(s) in storage.");

        // Collect all paths referenced by existing challenges
        $referenced = Challenge::whereNotNull('hidden_image_path')
            ->pluck('hidden_image_path')
            ->merge(Challenge::whereNotNull('original_image_path')->pluck('original_image_path'))
            ->map(fn($p) => ltrim($p, '/'))
            ->unique()
            ->toArray();

        $orphaned = array_filter($allImages, fn($img) => !in_array($img, $referenced, true));

        if (empty($orphaned)) {
            $this->info('No orphaned images found — all images are referenced by existing challenges.');
            return self::SUCCESS;
        }

        $this->line(count($orphaned) . " orphaned image(s) found:");
        $this->newLine();

        // Resolve sport and category for recovered drafts
        $sport    = $dryRun ? null : Sport::where('slug', 'football')->first();
        $category = $dryRun ? null : ChallengeCategory::where('slug', 'general')->first();

        $recovered = 0;
        foreach ($orphaned as $imagePath) {
            $filename = basename($imagePath);
            $title    = 'Recovered image - ' . $filename;

            $this->line("  <fg=yellow>ORPHANED:</> {$imagePath}");
            $this->line("    → Would create draft: \"{$title}\"");

            if ($dryRun) {
                $recovered++;
                continue;
            }

            // Skip if a draft with this exact hidden_image_path already exists
            if (Challenge::where('hidden_image_path', $imagePath)->exists()) {
                $this->line("    <fg=yellow>SKIP:</> Already has a challenge record.");
                continue;
            }

            if (!$sport) {
                $this->error("    No football sport found — cannot create challenge. Run seeders first.");
                continue;
            }

            Challenge::create([
                'sport_id'              => $sport->id,
                'challenge_category_id' => $category?->id,
                'title'                 => $title,
                'hidden_image_path'     => $imagePath,
                'original_image_path'   => null,
                'ball_x_ratio'          => 0.5,
                'ball_y_ratio'          => 0.5,
                'difficulty'            => 'medium',
                'status'                => 'draft',
            ]);

            $this->line("    <fg=green>CREATED</> draft challenge.");
            $recovered++;
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn("Dry run complete. {$recovered} challenge(s) would be created.");
            $this->line('Run without --dry-run to create them.');
        } else {
            $this->info("{$recovered} draft challenge(s) created.");
            $this->newLine();
            $this->warn('⚠  Recovered challenges are drafts.');
            $this->warn('   Open the admin panel, set the correct ball position, and verify images before making them active.');
            $this->line('   Admin: <fg=cyan>http://127.0.0.1:8000/admin/challenges</>');
        }

        return self::SUCCESS;
    }

    private function scanImages(): array
    {
        $disk    = Storage::disk('public');
        $files   = $disk->allFiles();
        $images  = [];
        $extList = self::IMAGE_EXTENSIONS;

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $extList, true)) {
                $images[] = $file;
            }
        }

        return $images;
    }
}
