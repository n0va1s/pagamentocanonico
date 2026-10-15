<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Lancada quando uma operacao de auditoria e tentada sobre uma associacao
 * a qual o usuario/contexto nao tem permissao de acesso.
 */
class AutorizacaoAuditoriaException extends RuntimeException
{
    public function __construct(
        string $message = 'Operação não permitida para a associação informada.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
