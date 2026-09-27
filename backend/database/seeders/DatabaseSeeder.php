<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
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
        User::updateOrCreate(['username' => 'admin.hr'], ['password' => 'AdminPayFlow2026!', 'role' => UserRole::Administrator]);
        User::updateOrCreate(['username' => 'staff.hr'], ['password' => 'StaffPayFlow2026!', 'role' => UserRole::Staff]);

        $this->call(PayrollBaselineSeeder::class);
        $this->call(DemoDataSeeder::class);
    }
}
