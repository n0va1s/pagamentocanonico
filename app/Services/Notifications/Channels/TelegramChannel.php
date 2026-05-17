<?php

namespace App\Services\Notifications\Channels;

use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Models\Notificacao;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramChannel implements NotificationChannelInterface
{
    private string $botToken;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token', '');
    }

    public function getChannelName(): string
    {
        return 'telegram';
    }

    public function send(Membro $membro, string $mensagem, TipoNotificacao $tipo): array
    {
        $chatId = $membro->des_telegram_chat_id;

        if (empty($chatId)) {
            return ['success' => false, 'error' => 'Chat ID do Telegram não cadastrado.'];
        }

        try {
            $response = Http::post(
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $mensagem,
                    'parse_mode' => 'HTML',
                ]
            );

            $data = $response->json();

            if ($response->successful() && ($data['ok'] ?? false)) {
                $externalId = (string) ($data['result']['message_id'] ?? '');
                $this->registrar($membro, $mensagem, $tipo, true, $externalId);

                return ['success' => true, 'external_id' => $externalId];
            }

            $erro = $data['description'] ?? 'Erro desconhecido no Telegram.';
            $this->registrar($membro, $mensagem, $tipo, false, null, $erro);

            return ['success' => false, 'error' => $erro];

        } catch (\Exception $e) {
            Log::error("TelegramChannel [{$membro->idt_membro}]: ".$e->getMessage());
            $this->registrar($membro, $mensagem, $tipo, false, null, $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function registrar(
        Membro $membro,
        string $conteudo,
        TipoNotificacao $tipo,
        bool $sucesso,
        ?string $externalId = null,
        ?string $erro = null
    ): void {
        Notificacao::create([
            'idt_membro' => $membro->idt_membro,
            'tip_canal' => $this->getChannelName(),
            'txt_conteudo' => $conteudo,
            'ind_enviada' => $sucesso,
            'num_externo' => $externalId,
            'msg_erro' => $erro,
        ]);
    }
}
