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

        $ofxImports = Ofx::all();

        if ($ofxImports->isEmpty()) {
            $this->command->warn('Nenhum OFX encontrado. Execute OfxSeeder primeiro.');

            return;
        }

        foreach ($ofxImports as $ofx) {
            $membros = \App\Models\Membro::where('idt_associacao', $ofx->idt_associacao)->get();

            foreach ($membros as $membro) {
                // Determine if this member is adimplente or inadimplente
                // 30% inadimplentes, 70% adimplentes.
                // Devedor da Silva must always be inadimplente.
                $isInadimplente = fake()->boolean(30);
                if ($membro->eml_membro === 'devedor@email.com') {
                    $isInadimplente = true;
                }

                $nomePessoa = $membro->nomeParaMatchingOfx();

                // Gera resumo para os últimos 3 meses
                for ($mes = 3; $mes >= 1; $mes--) {
                    $data = now()->subMonths($mes);
                    $numMes = (int) $data->format('n');
                    $numAno = (int) $data->format('Y');
                    
                    if ($isInadimplente) {
                        $total = 0; // Value must be 0 for them to be Inadimplente in dashboard
                        $indPago = false;
                        $numTransacao = 0;
                    } else {
                        $total = fake()->randomFloat(2, 50, 500);
                        $indPago = true;
                        $numTransacao = fake()->numberBetween(1, 5);
                    }

                    Resumo::firstOrCreate(
                        [
                            'idt_ofx' => $ofx->idt_ofx,
                            'nom_pessoa' => $nomePessoa,
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
