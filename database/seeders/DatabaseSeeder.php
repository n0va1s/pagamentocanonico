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

        // Create associations
        $assocAlfa = \App\Models\Associacao::firstOrCreate(['nom_associacao' => 'Associação Alfa']);
        $assocBeta = \App\Models\Associacao::firstOrCreate(['nom_associacao' => 'Associação Beta']);

        // Create Admin (no member needed)
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => \Illuminate\Support\Facades\Hash::make('localhost@1'),
                'role' => \App\Enums\Perfil::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create admin@email.com Admin
        User::firstOrCreate(
            ['email' => 'admin@email.com'],
            [
                'name' => 'João Paulo Trabalho',
                'password' => \Illuminate\Support\Facades\Hash::make('localhost@1'),
                'role' => \App\Enums\Perfil::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create Member for Diretor
        $membroDiretor = \App\Models\Membro::updateOrCreate(
            ['eml_membro' => 'diretor@email.com'],
            [
                'nom_membro' => 'Diretor User',
                'tel_membro' => '61999999999',
                'dat_nascimento' => '1980-08-20',
                'tip_associado' => \App\Enums\Perfil::DIRETOR,
                'idt_associacao' => $assocAlfa->idt_associacao,
                'ind_aprovado' => true,
            ]
        );

        // Create Diretor User
        User::firstOrCreate(
            ['email' => 'diretor@email.com'],
            [
                'name' => 'Diretor User',
                'password' => \Illuminate\Support\Facades\Hash::make('localhost@1'),
                'role' => \App\Enums\Perfil::DIRETOR,
                'email_verified_at' => now(),
                'idt_membro' => $membroDiretor->idt_membro,
            ]
        );

        // Create Member for membro@email.com
        $membroJP = \App\Models\Membro::updateOrCreate(
            ['eml_membro' => 'membro@email.com'],
            [
                'nom_membro' => 'João Paulo Silva',
                'tel_membro' => '61987654321',
                'dat_nascimento' => '1985-04-12',
                'end_logradouro' => 'Rua das Flores',
                'end_mumero' => '123',
                'end_complemento' => 'Jardim Primavera',
                'tip_associado' => \App\Enums\Perfil::MEMBRO,
                'idt_associacao' => $assocAlfa->idt_associacao,
                'ind_aprovado' => true,
                'ind_notificar_whatsapp' => true,
                'ind_notificar_email' => true,
                'ind_notificar_telegram' => true,
            ]
        );

        // Create membro@email.com User
        User::firstOrCreate(
            ['email' => 'membro@email.com'],
            [
                'name' => 'João Paulo Silva',
                'password' => \Illuminate\Support\Facades\Hash::make('localhost@1'),
                'role' => \App\Enums\Perfil::MEMBRO,
                'email_verified_at' => now(),
                'idt_membro' => $membroJP->idt_membro,
            ]
        );

        // Create Member for pendente@email.com
        $membroMaria = \App\Models\Membro::updateOrCreate(
            ['eml_membro' => 'pendente@email.com'],
            [
                'nom_membro' => 'Maria Oliveira',
                'tel_membro' => '(11) 91234-5678',
                'dat_nascimento' => now()->subYears(30)->format('Y-m-d'),
                'end_logradouro' => 'Avenida Brasil',
                'end_mumero' => '456',
                'end_complemento' => 'Centro',
                'tip_associado' => \App\Enums\Perfil::MEMBRO,
                'idt_associacao' => $assocBeta->idt_associacao,
                'ind_aprovado' => false,
                'ind_notificar_whatsapp' => true,
                'ind_notificar_email' => true,
                'ind_notificar_telegram' => false,
            ]
        );

        // Create pendente@email.com User
        User::firstOrCreate(
            ['email' => 'pendente@email.com'],
            [
                'name' => 'Maria Oliveira',
                'password' => \Illuminate\Support\Facades\Hash::make('localhost@1'),
                'role' => \App\Enums\Perfil::MEMBRO,
                'email_verified_at' => now(),
                'idt_membro' => $membroMaria->idt_membro,
            ]
        );

        // Create Member for devedor@email.com
        $membroDevedor = \App\Models\Membro::updateOrCreate(
            ['eml_membro' => 'devedor@email.com'],
            [
                'nom_membro' => 'Devedor da Silva',
                'tel_membro' => '(11) 98765-4321',
                'dat_nascimento' => now()->subYears(25)->format('Y-m-d'),
                'end_logradouro' => 'Rua do Atraso',
                'end_mumero' => '789',
                'end_complemento' => 'Bairro Alto',
                'tip_associado' => \App\Enums\Perfil::MEMBRO,
                'idt_associacao' => $assocAlfa->idt_associacao,
                'ind_aprovado' => true,
                'ind_notificar_whatsapp' => true,
                'ind_notificar_email' => true,
                'ind_notificar_telegram' => false,
            ]
        );

        // Create devedor@email.com User
        User::firstOrCreate(
            ['email' => 'devedor@email.com'],
            [
                'name' => 'Devedor da Silva',
                'password' => \Illuminate\Support\Facades\Hash::make('localhost@1'),
                'role' => \App\Enums\Perfil::MEMBRO,
                'email_verified_at' => now(),
                'idt_membro' => $membroDevedor->idt_membro,
            ]
        );

        $this->call([
            MembroSeeder::class,
            OfxSeeder::class,
            ResumoSeeder::class,
        ]);
    }
}
