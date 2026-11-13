<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $phone = env('SEED_ADMIN_PHONE', '+967700000001');
        $email = env('SEED_ADMIN_EMAIL', 'admin@yemenservices.local');
        $password = env('SEED_ADMIN_PASSWORD') ?: Str::random(16);

        $user = User::updateOrCreate(['email' => $email], [
            'name' => 'Platform Admin',
            'phone' => $phone,
            'role' => 'admin',
            'status' => 'active',
            'preferred_locale' => 'ar',
            'password' => Hash::make($password),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        if (! env('SEED_ADMIN_PASSWORD')) {
            $this->command->warn("Admin email: {$email}");
            $this->command->warn("Admin phone: {$phone}");
            $this->command->warn("Generated admin password (store securely, then rotate): {$password}");
        }

        // A dispatcher role account for the ops team.
        User::updateOrCreate(['phone' => env('SEED_DISPATCHER_PHONE', '+967700000002')], [
            'name' => 'Dispatch Desk',
            'role' => 'dispatcher',
            'status' => 'active',
            'preferred_locale' => 'ar',
            'phone_verified_at' => now(),
        ]);
    }
}
