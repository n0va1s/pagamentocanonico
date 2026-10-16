<?php

namespace App\Enums;

enum EfeitoRegra: string
{
    case EXCLUI_RECEITA = 'EXCLUI_RECEITA';
    case EXCLUI_DESPESA = 'EXCLUI_DESPESA';
    case EXCLUI_TUDO = 'EXCLUI_TUDO';

    public function label(): string
    {
        return match ($this) {
            self::EXCLUI_RECEITA => 'Excluir da Receita',
            self::EXCLUI_DESPESA => 'Excluir da Despesa',
            self::EXCLUI_TUDO => 'Excluir de Tudo',
        };
    }
}
