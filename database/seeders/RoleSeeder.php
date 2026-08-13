<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (UserRole::cases() as $role) {
            Role::query()->firstOrCreate(
                ['role_code' => $role->value],
                ['role_name' => $role->label(), 'active' => true],
            );
        }
    }
}
