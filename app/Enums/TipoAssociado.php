<?php

namespace App\Enums;

enum TipoAssociado: string
{
    case ASSOCIADO = 'A';
    case DIRETOR = 'D';
    case HONORARIO = 'H';

    public function label(): string
    {
        return match ($this) {
            self::ASSOCIADO => 'Associado',
            self::DIRETOR => 'Diretor',
            self::HONORARIO => 'Honorário',
        };
    }
}
