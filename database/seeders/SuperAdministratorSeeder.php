<?php

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first Super Administrator so someone can log in on the admin
 * subdomain and configure roles/permissions for everyone else. Reads
 * credentials from env rather than a hardcoded default, so no real password
 * is ever committed (docs/FirstMaket_Security_Compliance.md section 12).
 */
class SuperAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn(
                'Skipped Super Administrator seed: set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env first.'
            );

            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => env('SUPER_ADMIN_NAME', 'Super Administrator'),
                'phone' => env('SUPER_ADMIN_PHONE', '+2340000000000'),
                'password' => Hash::make($password),
                'user_type' => UserType::Staff,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('Super Administrator')) {
            $user->assignRole('Super Administrator');
        }
    }
}
