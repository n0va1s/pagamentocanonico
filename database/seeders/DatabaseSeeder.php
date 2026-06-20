<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => \App\Enums\Perfil::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        User::firstOrCreate(
            ['email' => 'diretor@example.com'],
            [
                'name' => 'Diretor User',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => \App\Enums\Perfil::DIRETOR,
                'email_verified_at' => now(),
            ]
        );

        User::firstOrCreate(
            ['email' => 'jp.pessoal@email.com'],
            [
                'name' => 'João Paulo Silva',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => \App\Enums\Perfil::MEMBRO,
                'email_verified_at' => now(),
            ]
        );

        User::firstOrCreate(
            ['email' => 'maria.oliveira@email.com'],
            [
                'name' => 'Maria Oliveira',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => \App\Enums\Perfil::MEMBRO,
                'email_verified_at' => now(),
            ]
        );

        $this->call([
            MembroSeeder::class,
            OfxSeeder::class,
            ResumoSeeder::class,
        ]);
    }
}
