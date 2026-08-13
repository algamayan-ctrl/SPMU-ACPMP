<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            OrganizationalUnitSeeder::class,
            RoleSeeder::class,
            InventoryReferenceSeeder::class,
            SystemSettingSeeder::class,
            DemoUserSeeder::class,
        ]);
    }
}
