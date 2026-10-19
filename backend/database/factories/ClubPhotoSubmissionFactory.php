<?php

namespace Database\Factories;

use App\Enums\PhotoSubmissionStatus;
use App\Models\Club;
use App\Models\ClubPhotoSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Metadata only — no file is written. Use ClubMediaService::storeImage() in
 * tests that need the file.
 *
 * @extends Factory<ClubPhotoSubmission>
 */
class ClubPhotoSubmissionFactory extends Factory
{
    protected $model = ClubPhotoSubmission::class;

    public function definition(): array
    {
        return [
            'club_id'             => Club::factory(),
            'source'              => ClubPhotoSubmission::SOURCE_ADMIN_UPLOAD,
            'disk'                => 'club_private',
            'path'                => fn (array $a) => $a['club_id'] . '/photos/' . Str::random(40) . '.jpg',
            'mime'                => 'image/jpeg',
            'width'               => 1600,
            'height'              => 1200,
            'bytes'               => 250000,
            'sha256'              => hash('sha256', Str::random(32)),
            'status'              => PhotoSubmissionStatus::Pending,
            'rights_confirmed_at' => now(),
        ];
    }
}
