<?php

namespace App\Enums;

enum Perfil: string
{
    case ADMIN = 'admin';
    case DIRETOR = 'diretor';
    case MEMBRO = 'membro';
    case HONORARIO = 'honorario';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrador',
            self::DIRETOR => 'Diretor',
            self::MEMBRO => 'Membro',
            self::HONORARIO => 'Honorário',
        };
    }
}
