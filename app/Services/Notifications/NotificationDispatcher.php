<?php

namespace App\Services\Notifications;

use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use App\Services\Notifications\Messages\MensagemBuilder;

class NotificationDispatcher
{
    /** @var array<string, NotificationChannelInterface> */
    private array $canais;

    public function __construct(private MensagemBuilder $builder)
    {
        $this->canais = [
            'whatsapp' => app(WhatsAppChannel::class),
            'telegram' => app(TelegramChannel::class),
            'email' => app(EmailChannel::class),
        ];
    }

    /**
     * Notifica o membro por todos os canais ativos para o tipo informado.
     *
     * @param  array<string, mixed>  $dados  Dados extras para a mensagem (ex: ['mes' => 'Jan/2026', 'valor' => 150.00])
     * @return array<string, array{success: bool, external_id?: string|null, error?: string}>
     */
    public function notificar(Membro $membro, TipoNotificacao $tipo, array $dados = []): array
    {
        $mensagem = $this->builder->construir($tipo, $membro, $dados);
        $resultados = [];

        foreach ($this->canaisAtivos($membro) as $canal) {
            $resultados[$canal->getChannelName()] = $canal->send($membro, $mensagem, $tipo);
        }

        return $resultados;
    }

    /**
     * Envia por um canal específico com mensagem customizada.
     *
     * @return array{success: bool, external_id?: string|null, error?: string}
     */
    public function enviarPor(string $canal, Membro $membro, string $mensagem, TipoNotificacao $tipo): array
    {
        if (! isset($this->canais[$canal])) {
            return ['success' => false, 'error' => "Canal '{$canal}' não encontrado."];
        }

        return $this->canais[$canal]->send($membro, $mensagem, $tipo);
    }

    /**
     * Retorna apenas os canais habilitados para o membro, na ordem de prioridade.
     *
     * @return NotificationChannelInterface[]
     */
    private function canaisAtivos(Membro $membro): array
    {
        $ativos = [];

        // Ordem de prioridade: WhatsApp → Telegram → Email
        if ($membro->ind_notificar_whatsapp && filled($membro->tel_membro)) {
            $ativos[] = $this->canais['whatsapp'];
        }

        if ($membro->ind_notificar_telegram && filled($membro->des_telegram_chat_id)) {
            $ativos[] = $this->canais['telegram'];
        }

        if ($membro->ind_notificar_email && filled($membro->eml_membro)) {
            $ativos[] = $this->canais['email'];
        }

        return $ativos;
    }
}
