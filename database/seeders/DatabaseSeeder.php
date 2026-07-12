<?php

namespace Database\Seeders;

use App\Enums\Perfil;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Associacao;
use App\Models\Membro;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Create associations
        $assocAlfa = Associacao::firstOrCreate(['nom_associacao' => 'Associação Alfa']);
        $assocBeta = Associacao::firstOrCreate(['nom_associacao' => 'Associação Beta']);

        // Create Admin (no member needed)
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('localhost@1'),
                'role' => Perfil::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create Member for jp.trabalho@gmail.com
        $membroAdmin = Membro::updateOrCreate(
            ['eml_membro' => 'jp.trabalho@gmail.com'],
            [
                'nom_membro' => 'João Paulo Novais',
                'num_cpf_membro' => '85236250110',
                'tel_membro' => '61981546988',
                'end_logradouro' => 'Rua Jerivá 113 B',
                'tip_associado' => Perfil::ADMIN,
                'idt_associacao' => $assocAlfa->idt_associacao,
                'ind_aprovado' => true,
            ]
        );

        // Create jp.trabalho@gmail.com Admin
        User::firstOrCreate(
            ['email' => 'jp.trabalho@gmail.com'],
            [
                'name' => 'João Paulo Novais',
                'password' => Hash::make('localhost@1'),
                'role' => Perfil::ADMIN,
                'email_verified_at' => now(),
                'idt_membro' => $membroAdmin->idt_membro,
            ]
        );

        // Create Member for Diretor
        $membroDiretor = Membro::updateOrCreate(
            ['eml_membro' => 'diretor@email.com'],
            [
                'nom_membro' => 'Diretor User',
                'num_cpf_membro' => fake('pt_BR')->unique()->cpf(),
                'tel_membro' => '61999999999',
                'dat_nascimento' => '1980-08-20',
                'tip_associado' => Perfil::DIRETOR,
                'idt_associacao' => $assocAlfa->idt_associacao,
                'ind_aprovado' => true,
            ]
        );

        // Create Diretor User
        User::firstOrCreate(
            ['email' => 'diretor@email.com'],
            [
                'name' => 'Diretor User',
                'password' => Hash::make('localhost@1'),
                'role' => Perfil::DIRETOR,
                'email_verified_at' => now(),
                'idt_membro' => $membroDiretor->idt_membro,
            ]
        );

        // Create Member for membro@email.com
        $membroJP = Membro::updateOrCreate(
            ['eml_membro' => 'membro@email.com'],
            [
                'nom_membro' => 'João Paulo Silva',
                'num_cpf_membro' => fake('pt_BR')->unique()->cpf(),
                'tel_membro' => '61987654321',
                'dat_nascimento' => '1985-04-12',
                'end_logradouro' => 'Rua das Flores',
                'end_numero' => '123',
                'end_complemento' => 'Jardim Primavera',
                'tip_associado' => Perfil::MEMBRO,
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
                'password' => Hash::make('localhost@1'),
                'role' => Perfil::MEMBRO,
                'email_verified_at' => now(),
                'idt_membro' => $membroJP->idt_membro,
            ]
        );

        // Create Member for pendente@email.com
        $membroMaria = Membro::updateOrCreate(
            ['eml_membro' => 'pendente@email.com'],
            [
                'nom_membro' => 'Maria Oliveira',
                'num_cpf_membro' => fake('pt_BR')->unique()->cpf(),
                'tel_membro' => '(11) 91234-5678',
                'dat_nascimento' => now()->subYears(30)->format('Y-m-d'),
                'end_logradouro' => 'Avenida Brasil',
                'end_numero' => '456',
                'end_complemento' => 'Centro',
                'tip_associado' => Perfil::MEMBRO,
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
                'password' => Hash::make('localhost@1'),
                'role' => Perfil::MEMBRO,
                'email_verified_at' => now(),
                'idt_membro' => $membroMaria->idt_membro,
            ]
        );

        // Create Member for devedor@email.com
        $membroDevedor = Membro::updateOrCreate(
            ['eml_membro' => 'devedor@email.com'],
            [
                'nom_membro' => 'Devedor da Silva',
                'num_cpf_membro' => fake('pt_BR')->unique()->cpf(),
                'tel_membro' => '(11) 98765-4321',
                'dat_nascimento' => now()->subYears(25)->format('Y-m-d'),
                'end_logradouro' => 'Rua do Atraso',
                'end_numero' => '789',
                'end_complemento' => 'Bairro Alto',
                'tip_associado' => Perfil::MEMBRO,
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
                'password' => Hash::make('localhost@1'),
                'role' => Perfil::MEMBRO,
                'email_verified_at' => now(),
                'idt_membro' => $membroDevedor->idt_membro,
            ]
        );

        /*$this->call([
            MembroSeeder::class,
            OfxSeeder::class,
            ResumoSeeder::class,
        ]);*/
    }
}
