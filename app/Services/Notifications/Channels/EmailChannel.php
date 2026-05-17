<?php

namespace App\Services\Notifications\Channels;

use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Models\Notificacao;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailChannel implements NotificationChannelInterface
{
    public function getChannelName(): string
    {
        return 'email';
    }

    public function send(Membro $membro, string $mensagem, TipoNotificacao $tipo): array
    {
        if (empty($membro->eml_membro)) {
            return ['success' => false, 'error' => 'E-mail não cadastrado.'];
        }

        try {
            Mail::raw($mensagem, function ($mail) use ($membro, $tipo) {
                $mail->to($membro->eml_membro, $membro->nom_membro)
                    ->subject($this->assunto($tipo));
            });

            $this->registrar($membro, $mensagem, $tipo, true);

            return ['success' => true];

        } catch (\Exception $e) {
            Log::error("EmailChannel [{$membro->idt_membro}]: ".$e->getMessage());
            $this->registrar($membro, $mensagem, $tipo, false, null, $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function assunto(TipoNotificacao $tipo): string
    {
        return match ($tipo) {
            TipoNotificacao::INADIMPLENTE => '['.config('app.name').'] Pendência financeira',
            TipoNotificacao::ANIVERSARIANTE => '['.config('app.name').'] Feliz aniversário! 🎂',
            TipoNotificacao::BOAS_VINDAS => 'Bem-vindo(a) ao '.config('app.name').'! 🎉',
        };
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
