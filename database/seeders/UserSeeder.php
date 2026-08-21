<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Two accounts, no roles: one for the manager, one for the ranking owner.
 *
 * Passwords come from .env so they are not hardcoded here. Change them in .env
 * and re-run `php artisan db:seed --class=UserSeeder` to reset.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Ranking Owner',
                'email' => env('SEED_OWNER_EMAIL', 'ahmadmog.2091@gmail.com'),
                'password' => env('SEED_OWNER_PASSWORD'),
            ],
            [
                'name' => 'Manager',
                'email' => env('SEED_MANAGER_EMAIL', 'manager@fastercars.ae'),
                'password' => env('SEED_MANAGER_PASSWORD'),
            ],
        ];

        foreach ($accounts as $account) {
            if (blank($account['password'])) {
                $this->command->warn("Skipping {$account['email']}: no password set in .env.");

                continue;
            }

            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($account['password']),
                    'email_verified_at' => now(),
                ]
            );

            $this->command->info("User ready: {$account['email']}");
        }
    }
}
