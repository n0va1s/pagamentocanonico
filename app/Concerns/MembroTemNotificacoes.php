<?php

namespace App\Concerns;

trait MembroTemNotificacoes
{
    public function podeNotificarWhatsapp(): bool
    {
        return $this->ind_notificar_whatsapp && filled($this->tel_membro);
    }

    public function podeNotificarEmail(): bool
    {
        return $this->ind_notificar_email && filled($this->eml_membro);
    }

    public function canaisAtivos(): array
    {
        return array_filter([
            'whatsapp' => $this->podeNotificarWhatsapp(),
            'email' => $this->podeNotificarEmail(),
            'telegram' => $this->ind_notificar_telegram && filled($this->des_telegram_chat_id),
        ]);
    }
}
