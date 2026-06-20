<?php

namespace App\Services\Notifications\Contracts;

use App\Enums\TipoNotificacao;
use App\Models\Membro;

interface NotificationChannelInterface
{
    /**
     * Envia uma notificação para o membro.
     *
     * @return array{success: bool, external_id?: string|null, error?: string}
     */
    public function send(Membro $membro, string $mensagem, TipoNotificacao $tipo, array $dados = []): array;


    /**
     * Retorna o identificador do canal (ex: 'whatsapp').
     */
    public function getChannelName(): string;
}
