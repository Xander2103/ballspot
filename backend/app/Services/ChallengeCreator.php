<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\Club;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\User;
use App\Services\Clubs\ClubAuditLogger;
use App\Services\Clubs\ClubMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The one place that creates a Challenge row (shared by the admin challenge
 * form and the club photo conversion, ClubPhotoReviewService::convert()).
 *
 *  - BallPicker challenges: images on the public disk, usage pool as chosen
 *    (default general).
 *  - Club challenges (club_id set): images on the private club_private disk
 *    (GD re-encoded, EXIF stripped), usage pool `club` — never Daily,
 *    tournaments or public packs — and a `challenge.created` club audit row.
 *
 * Ball ratios are rounded to BALL_RATIO_DECIMALS. Tags are comma-separated
 * text tags (created on demand).
 */
class ChallengeCreator
{
    /**
     * Ball coordinates are 0..1 ratios. Three decimals is about one pixel on a
     * 1000px image — precise enough for scoring, short enough to hand-edit.
     */
    public const BALL_RATIO_DECIMALS = 3;

    public function __construct(
        private ClubMediaService $clubMedia,
        private ClubAuditLogger $audit,
    ) {}

    /**
     * Store the uploaded images on the owner's disk.
     *
     * @return array{hidden: string, original: ?string}
     */
    public function storeImages(?Club $club, UploadedFile $hidden, ?UploadedFile $original = null): array
    {
        if ($club) {
            $hiddenPath = $this->clubMedia->storeImage($hidden, $club, ClubMediaService::KIND_CHALLENGE_HIDDEN)['path'];
            try {
                $originalPath = $original
                    ? $this->clubMedia->storeImage($original, $club, ClubMediaService::KIND_CHALLENGE_ORIGINAL)['path']
                    : null;
            } catch (\Throwable $e) {
                $this->clubMedia->delete($hiddenPath);
                throw $e;
            }

            return ['hidden' => $hiddenPath, 'original' => $originalPath];
        }

        return [
            'hidden'   => $hidden->store('challenges/hidden', 'public'),
            'original' => $original?->store('challenges/original', 'public'),
        ];
    }

    /**
     * Create the challenge (+ tags, subcategories, club audit) for already
     * stored image paths. Runs in a transaction; joins the caller's one.
     *
     * @param array{title: string, difficulty: string, status: string, usage_pool?: ?string, sport_id?: ?int,
     *              challenge_category_id?: ?int, ball_x_ratio: float|string, ball_y_ratio: float|string,
     *              tags?: ?string, subcategories?: ?array} $data
     */
    public function create(array $data, ?Club $club, string $hiddenPath, ?string $originalPath, ?User $actor = null, string $actorKind = 'platform_admin'): Challenge
    {
        return DB::transaction(function () use ($data, $club, $hiddenPath, $originalPath, $actor, $actorKind) {
            $challenge = Challenge::create([
                'club_id'               => $club?->id,
                // Default to football to preserve existing behaviour.
                'sport_id'              => $data['sport_id'] ?? Sport::where('slug', 'football')->value('id'),
                'challenge_category_id' => $data['challenge_category_id'] ?? null,
                'title'                 => $data['title'],
                'difficulty'            => $data['difficulty'],
                'status'                => $data['status'],
                'usage_pool'            => $club ? Challenge::POOL_CLUB : ($data['usage_pool'] ?? Challenge::POOL_GENERAL),
                'ball_x_ratio'          => round((float) $data['ball_x_ratio'], self::BALL_RATIO_DECIMALS),
                'ball_y_ratio'          => round((float) $data['ball_y_ratio'], self::BALL_RATIO_DECIMALS),
                'hidden_image_path'     => $hiddenPath,
                'original_image_path'   => $originalPath,
            ]);

            $challenge->tags()->sync($this->resolveTagIds($data['tags'] ?? null));
            $challenge->subcategories()->sync($data['subcategories'] ?? []);

            if ($club) {
                $this->audit->log($club, 'challenge.created', $actor, $challenge, [
                    'challenge_id' => $challenge->id,
                    'name'         => $challenge->title,
                    'status'       => $challenge->status,
                ], $actorKind);
            }

            return $challenge;
        });
    }

    /** Delete freshly stored images after a failed create (owner's disk). */
    public function discardImages(?Club $club, array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            $club ? $this->clubMedia->delete($path) : \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
        }
    }

    /**
     * Turn a comma-separated tag string into tag IDs, creating missing tags.
     * Text tags only — no copyrighted logos/imagery.
     *
     * @return int[]
     */
    public function resolveTagIds(?string $tagString): array
    {
        if (empty($tagString)) {
            return [];
        }

        return collect(explode(',', $tagString))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn ($name) => Tag::findOrCreateByName($name)->id)
            ->values()
            ->all();
    }
}
