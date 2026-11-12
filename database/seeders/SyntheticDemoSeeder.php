<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SyntheticDemoSeeder extends Seeder
{
    /**
     * Synthetic demo-контур (план §6.5): только dev/staging/test,
     * жёстко заблокирован от production. Пароли документированы в README,
     * только домены example.test, никаких реальных данных.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('SyntheticDemoSeeder пропущен: production окружение.');

            return;
        }

        $users = [
            ['name' => 'Demo Admin', 'email' => 'admin@example.test', 'role' => 'administrator'],
            ['name' => 'Demo Manager', 'email' => 'manager@example.test', 'role' => 'manager'],
            ['name' => 'Demo Viewer', 'email' => 'viewer@example.test', 'role' => 'viewer'],
        ];

        foreach ($users as $user) {
            $role = $user['role'];

            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                    'password_set_at' => now(),
                ],
            )->syncRoles([$role]);
        }
    }
}
