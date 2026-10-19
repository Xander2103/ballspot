<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ClubContentReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClubContentReport> */
class ClubContentReportFactory extends Factory
{
    protected $model = ClubContentReport::class;

    public function definition(): array
    {
        return [
            'club_id'          => Club::factory(),
            'reporter_user_id' => User::factory(),
            'subject_type'     => ClubContentReport::SUBJECT_CLUB,
            'subject_ref'      => null,
            'reason'           => 'inappropriate',
            'details'          => null,
            'status'           => ClubContentReport::STATUS_OPEN,
        ];
    }
}
