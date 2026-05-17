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

class NotificarBoasVindas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly Membro $membro) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $dispatcher->notificar($this->membro, TipoNotificacao::BOAS_VINDAS);
    }
}
