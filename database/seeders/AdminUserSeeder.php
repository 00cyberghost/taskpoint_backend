<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's default admin user.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@taskpoint.test');
        $password = env('ADMIN_PASSWORD', 'password');
        $name = env('ADMIN_NAME', 'TaskPoint Admin');

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
                'timezone' => config('app.timezone'),
                'locale' => app()->getLocale(),
                'last_seen_at' => now(),
            ],
        );
    }
}
