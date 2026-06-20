<?php

namespace App\Services\Notifications\Channels;

use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Models\Notificacao;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel implements NotificationChannelInterface
{
    public function getChannelName(): string
    {
        return 'whatsapp';
    }

    public function send(Membro $membro, string $mensagem, TipoNotificacao $tipo, array $dados = []): array

    {
        $telefone = $this->sanitizarTelefone($membro->tel_membro);

        if (empty($telefone)) {
            return ['success' => false, 'error' => 'Número de WhatsApp não cadastrado ou inválido.'];
        }

        // Gera a URL do WhatsApp Web
        $url = 'https://wa.me/' . $telefone . '?text=' . urlencode($mensagem);

        try {
            // Registra o envio no banco de dados local
            $this->registrar($membro, $mensagem, $tipo, true, 'wa_me_link');

            // Envia o espelho por e-mail se cadastrado
            if (filled($membro->eml_membro)) {
                $emailChannel = app(EmailChannel::class);
                $emailChannel->send($membro, $mensagem, $tipo);
            }

            return [
                'success' => true,
                'redirect_url' => $url,
            ];

        } catch (\Exception $e) {
            Log::error("WhatsAppChannel [{$membro->idt_membro}]: " . $e->getMessage());
            $this->registrar($membro, $mensagem, $tipo, false, null, $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sanitizarTelefone(?string $telefone): ?string
    {
        if (empty($telefone)) {
            return null;
        }

        $limpo = preg_replace('/\D/', '', $telefone);

        // Adiciona DDI 55 se tiver 10 ou 11 dígitos
        if (strlen($limpo) === 10 || strlen($limpo) === 11) {
            $limpo = '55' . $limpo;
        }

        if (!str_starts_with($limpo, '55') || strlen($limpo) < 12) {
            return null;
        }

        return $limpo;
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
