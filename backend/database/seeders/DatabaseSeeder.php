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
        // Never create the well-known dev admin outside local/testing. A bare
        // `db:seed` or `migrate --seed` on a production box must not leave a
        // `password`-protected admin behind; the content seeders are safe.
        if (app()->environment('local', 'testing')) {
            $this->seedDevAdmin();
        }

        $this->call([
            SportSeeder::class,
            ChallengeCategorySeeder::class,
            ChallengeSeeder::class,
            DailyChallengeSeeder::class,
            BadgeSeeder::class,
        ]);
    }

    private function seedDevAdmin(): void
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
    }
}
