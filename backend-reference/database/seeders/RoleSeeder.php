<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['contable', 'planificador', 'conductor'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }
}
