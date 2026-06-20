<?php

namespace App\Enums;

enum TipoNotificacao: string
{
    case INADIMPLENTE = 'inadimplente';
    case ANIVERSARIANTE = 'aniversariante';
    case BOAS_VINDAS = 'boas_vindas';
    case CUSTOM = 'custom';
    case QUITACAO_ANUAL = 'quitacao_anual';

    public function label(): string
    {
        return match ($this) {
            self::INADIMPLENTE => 'Inadimplência',
            self::ANIVERSARIANTE => 'Aniversário',
            self::BOAS_VINDAS => 'Boas-vindas',
            self::CUSTOM => 'Mensagem Customizada',
            self::QUITACAO_ANUAL => 'Quitação Anual',
        };
    }
}
