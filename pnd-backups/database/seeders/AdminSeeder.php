<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $name = env('ADMIN_NAME', 'Administrador');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD vacíos en .env — admin NO sembrado.');
            return;
        }

        // Solo siembra una vez. Si el admin ya existe, no toca su password
        // (para no resetearlo en cada arranque).
        $user = User::where('email', $email)->first();
        if ($user) {
            $this->command?->info("Admin {$email} ya existe — sin cambios.");
            return;
        }

        User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
            'role'     => 'admin',
            'instance_slug' => null,
        ]);

        $this->command?->info("Admin {$email} creado.");
    }
}
