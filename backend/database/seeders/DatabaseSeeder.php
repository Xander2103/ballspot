<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'admin@ballspot.local'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
            ]
        );
        if ($admin->wasRecentlyCreated) {
            $admin->is_admin = true;
            $admin->save();
        }

        $this->call([
            SportSeeder::class,
            ChallengeCategorySeeder::class,
            ChallengeSeeder::class,
            DailyChallengeSeeder::class,
            BadgeSeeder::class,
        ]);
    }
}
