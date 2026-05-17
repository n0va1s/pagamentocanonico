<?php

namespace App\Console\Commands;

use App\Jobs\NotificarAniversariantes;
use App\Jobs\NotificarInadimplentes;
use App\Models\Ofx;
use Illuminate\Console\Command;

class NotificarMembros extends Command
{
    protected $signature = 'membros:notificar
                            {tipo : Tipo de notificação: inadimplentes | aniversariantes}
                            {--ofx=  : ID da importação OFX (obrigatório para inadimplentes)}
                            {--dry-run : Simula o envio sem disparar notificações}';

    protected $description = 'Dispara notificações para membros inadimplentes ou aniversariantes';

    public function handle(): int
    {
        $tipo = $this->argument('tipo');
        $simular = $this->option('dry-run');

        return match ($tipo) {
            'inadimplentes' => $this->inadimplentes($simular),
            'aniversariantes' => $this->aniversariantes($simular),
            default => $this->tipoInvalido($tipo),
        };
    }

    private function inadimplentes(bool $simular): int
    {
        $idtOfx = $this->option('ofx');

        if (! $idtOfx) {
            // Usa a importação mais recente se não informada
            $ofx = Ofx::latest()->first();

            if (! $ofx) {
                $this->error('Nenhuma importação OFX encontrada. Importe um arquivo primeiro.');

                return self::FAILURE;
            }

            $idtOfx = $ofx->idt_ofx;
            $this->line("Usando importação mais recente: <info>{$ofx->des_arquivo}</info> (ID: {$idtOfx})");
        }

        if ($simular) {
            $this->warn('[DRY-RUN] Nenhuma notificação será enviada.');
        }

        NotificarInadimplentes::dispatch((int) $idtOfx, $simular);

        $this->info('Job de inadimplentes despachado para a fila.');

        return self::SUCCESS;
    }

    private function aniversariantes(bool $simular): int
    {
        if ($simular) {
            $this->warn('[DRY-RUN] Nenhuma notificação será enviada.');
        }

        NotificarAniversariantes::dispatch($simular);

        $this->info('Job de aniversariantes despachado para a fila.');

        return self::SUCCESS;
    }

    private function tipoInvalido(string $tipo): int
    {
        $this->error("Tipo '{$tipo}' inválido. Use: inadimplentes | aniversariantes");

        return self::FAILURE;
    }
}
