<?php

namespace App\Enums;

enum Canal: string
{
    case WHATSAPP = 'W';
    case TELEGRAM = 'T';
    case EMAIL = 'E';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => 'Whatsapp',
            self::TELEGRAM => 'Telegram',
            self::EMAIL => 'E-mail',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WHATSAPP => 'heroicon-m-user',
            self::TELEGRAM => 'heroicon-m-user-circle',
            self::EMAIL => 'heroicon-m-user-circle',
        };
    }
}
