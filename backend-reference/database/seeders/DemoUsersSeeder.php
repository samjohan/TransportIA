<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// Creates one demo login per role so the Docker Compose stack is usable
// immediately, without needing `php artisan tinker` by hand.
// Passwords are intentionally simple ("password") — demo/dev data only.
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $demo = [
            ['name' => 'Ana Planificadora', 'email' => 'planificador@demo.com', 'role' => 'planificador'],
            ['name' => 'Carlos Contable', 'email' => 'contable@demo.com', 'role' => 'contable'],
            ['name' => 'Diego Conductor', 'email' => 'conductor@demo.com', 'role' => 'conductor'],
        ];

        foreach ($demo as $datos) {
            $user = User::firstOrCreate(
                ['email' => $datos['email']],
                ['name' => $datos['name'], 'password' => Hash::make('password')]
            );

            if (! $user->hasRole($datos['role'])) {
                $user->assignRole($datos['role']);
            }
        }
    }
}
