<?php

namespace App\Jobs;

use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Models\Resumo;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotificarInadimplentes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  int  $idtOfx  ID da importação OFX de referência
     * @param  bool  $simular  Se true, loga sem enviar
     */
    public function __construct(
        public readonly int $idtOfx,
        public readonly bool $simular = false,
    ) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $membros = Membro::query()
            ->where(function ($q) {
                $q->where('ind_notificar_whatsapp', true)
                    ->orWhere('ind_notificar_email', true)
                    ->orWhere('ind_notificar_telegram', true);
            })
            ->get();

        foreach ($membros as $membro) {
            // Usa nom_ofx quando preenchido, com fallback para nom_membro (ADR-0002)
            $nomeMatching = $membro->nomeParaMatchingOfx();

            $resumosAtrasados = Resumo::query()
                ->where('idt_ofx', $this->idtOfx)
                ->where('nom_pessoa', $nomeMatching)
                ->where('ind_pago', false)
                ->orderByDesc('num_ano')
                ->orderByDesc('num_mes')
                ->get();

            if ($resumosAtrasados->isEmpty()) {
                continue;
            }

            // Notifica pelo mês mais recente em atraso
            $maisRecente = $resumosAtrasados->first();

            if ($this->simular) {
                Log::info("[SIMULAÇÃO] Inadimplente: {$membro->nom_membro} (matching: \"{$nomeMatching}\") — {$maisRecente->nom_mes}/{$maisRecente->num_ano}");

                continue;
            }

            $dispatcher->notificar($membro, TipoNotificacao::INADIMPLENTE, [
                'mes' => "{$maisRecente->nom_mes}/{$maisRecente->num_ano}",
                'valor' => $maisRecente->val_total,
            ]);
        }
    }
}
