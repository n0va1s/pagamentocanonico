<?php

namespace Database\Seeders;

use App\Models\Membro;
use Illuminate\Database\Seeder;

class MembroSeeder extends Seeder
{
    public function run(): void
    {
        // 20 membros aleatórios
        Membro::factory()->count(20)->create();

        // Membros fixos para testes
        Membro::firstOrCreate(
            ['eml_membro' => 'jp.pessoal@email.com'],
            [
                'nom_membro' => 'João Paulo Silva',
                'tel_membro' => '61987654321',
                'end_logradouro' => 'Rua das Flores',
                'end_mumero' => '123',
                'end_complemento' => 'Jardim Primavera',
                'tip_associado' => 'membro',
                'ind_notificar_whatsapp' => true,
                'ind_notificar_email' => true,
                'ind_notificar_telegram' => true,
            ]
        );

        Membro::firstOrCreate(
            ['eml_membro' => 'maria.oliveira@email.com'],
            [
                'nom_membro' => 'Maria Oliveira',
                'tel_membro' => '(11) 91234-5678',
                'end_logradouro' => 'Avenida Brasil',
                'end_mumero' => '456',
                'end_complemento' => 'Centro',
                'tip_associado' => 'diretor',
                'ind_notificar_whatsapp' => true,
                'ind_notificar_email' => true,
                'ind_notificar_telegram' => false,
            ]
        );
    }
}
