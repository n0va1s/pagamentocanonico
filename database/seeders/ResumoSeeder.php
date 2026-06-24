<?php

namespace Database\Seeders;

use App\Models\Ofx;
use App\Models\Resumo;
use Illuminate\Database\Seeder;

class ResumoSeeder extends Seeder
{
    public function run(): void
    {
        $nomesMeses = [
            1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
            5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
            9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
        ];

        $pessoas = [
            'João Paulo Silva',
            'Maria Oliveira',
            'Carlos Eduardo Santos',
            'Ana Beatriz Lima',
            'Devedor da Silva',
        ];

        $ofxImports = Ofx::all();

        if ($ofxImports->isEmpty()) {
            $this->command->warn('Nenhum OFX encontrado. Execute OfxSeeder primeiro.');

            return;
        }

        foreach ($ofxImports as $ofx) {
            foreach ($pessoas as $pessoa) {
                // Gera resumo para os últimos 3 meses
                for ($mes = 3; $mes >= 1; $mes--) {
                    $data = now()->subMonths($mes);
                    $numMes = (int) $data->format('n');
                    $numAno = (int) $data->format('Y');
                    
                    if ($pessoa === 'Devedor da Silva') {
                        $total = fake()->randomFloat(2, 100, 300);
                        $indPago = false;
                        $numTransacao = 0;
                    } else {
                        $total = fake()->randomFloat(2, 0, 500);
                        $indPago = $total > 0;
                        $numTransacao = fake()->numberBetween(1, 5);
                    }

                    Resumo::firstOrCreate(
                        [
                            'idt_ofx' => $ofx->idt_ofx,
                            'nom_pessoa' => $pessoa,
                            'num_ano' => $numAno,
                            'num_mes' => $numMes,
                        ],
                        [
                            'nom_mes' => $nomesMeses[$numMes],
                            'val_total' => $total,
                            'num_transacao' => $numTransacao,
                            'ind_pago' => $indPago,
                        ]
                    );
                }
            }
        }
    }
}
