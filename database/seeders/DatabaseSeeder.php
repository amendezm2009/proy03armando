<?php

namespace Database\Seeders;

use App\Models\User;
use App\RoleName;
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
        $this->call(RolesAndPermissionsSeeder::class);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'admin@example.com',
        ])->assignRole(RoleName::Admin);
        User::factory()->create([
            'name' => 'Client User',
            'email' => 'client@example.com',
        ])->assignRole(RoleName::Cliente);

        $this->call(ClassificationSeeder::class);

    }
}
