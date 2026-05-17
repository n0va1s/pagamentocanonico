<?php

namespace App\Jobs;

use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotificarAniversariantes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly bool $simular = false) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $hoje = now();

        // Busca membros que fazem aniversário hoje (requer campo dat_nascimento no model)
        $aniversariantes = Membro::query()
            ->whereMonth('dat_nascimento', $hoje->month)
            ->whereDay('dat_nascimento', $hoje->day)
            ->where(function ($q) {
                $q->where('ind_notificar_whatsapp', true)
                    ->orWhere('ind_notificar_email', true)
                    ->orWhere('ind_notificar_telegram', true);
            })
            ->get();

        foreach ($aniversariantes as $membro) {
            if ($this->simular) {
                Log::info("[SIMULAÇÃO] Aniversariante: {$membro->nom_membro} — {$hoje->format('d/m')}");

                continue;
            }

            $dispatcher->notificar($membro, TipoNotificacao::ANIVERSARIANTE);
        }
    }
}
