<?php

namespace Database\Seeders;

use App\Models\Ofx;
use App\Models\Transacao;
use Illuminate\Database\Seeder;

class OfxSeeder extends Seeder
{
    public function run(): void
    {
        $associacao = \App\Models\Associacao::firstOrCreate(['nom_associacao' => 'Associação Alfa']);

        // Cria 2 importações OFX com transações e resumos vinculados
        Ofx::factory()
            ->count(2)
            ->create(['idt_associacao' => $associacao->idt_associacao])
            ->each(function (Ofx $ofx) {
                // 15 transações de crédito por importação
                $transacoes = Transacao::factory()
                    ->count(15)
                    ->credito()
                    ->create(['idt_ofx' => $ofx->idt_ofx]);

                $total = $transacoes->sum('val_transacao');

                $ofx->update([
                    'qtd_transacao' => $transacoes->count(),
                    'val_total' => $total,
                ]);
            });
    }
}
