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
        \App\Models\User::firstOrCreate(
            ['email' => 'admin@ballspot.local'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'is_admin' => true,
            ]
        );

        $this->call([
            SportSeeder::class,
            ChallengeSeeder::class,
        ]);
    }
}
