<?php

namespace App\Console\Commands;

use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Mail\LembretePagamentoMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class LembreteInadimplentes extends Command
{
    protected $signature = 'membros:lembrete-inadimplentes';
    protected $description = 'Envia e-mails de lembrete de pagamento para associados inadimplentes com base no último OFX importado';

    public function handle()
    {
        $latestOfx = Ofx::latest()->first();

        if (!$latestOfx) {
            $this->warn('Nenhum extrato OFX encontrado no sistema.');
            return 0;
        }

        $this->info("Processando inadimplentes com base no extrato OFX ID: {$latestOfx->idt_ofx}...");

        $resumosPendentes = Resumo::where('idt_ofx', $latestOfx->idt_ofx)
            ->where('ind_pago', false)
            ->get();

        $enviados = 0;

        foreach ($resumosPendentes as $resumo) {
            // Encontra o membro pelo nome de matching
            $membro = Membro::where('nom_ofx', $resumo->nom_pessoa)
                ->orWhere('nom_membro', $resumo->nom_pessoa)
                ->first();

            if ($membro && $membro->eml_membro) {
                try {
                    $dados = [
                        'mes' => $resumo->nom_mes,
                        'valor' => $resumo->val_total,
                    ];
                    
                    Mail::to($membro->eml_membro)
                        ->send(new LembretePagamentoMail($membro, $dados));

                    $this->line("E-mail enviado para: {$membro->nom_membro} ({$membro->eml_membro})");
                    $enviados++;
                } catch (\Exception $e) {
                    $this->error("Falha ao enviar e-mail para {$membro->nom_membro}: " . $e->getMessage());
                }
            }
        }

        $this->info("Concluído! {$enviados} e-mails de lembrete enviados.");
        return 0;
    }
}
