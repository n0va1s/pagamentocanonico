<?php

namespace App\Console\Commands;

use App\Models\Membro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class LembreteAniversario extends Command
{
    protected $signature = 'pag:lembrete-aniversario';

    protected $description = 'Envia e-mails de feliz aniversário para todos os aniversariantes do dia';

    public function handle()
    {
        $hoje = now();

        $aniversariantes = Membro::whereMonth('dat_nascimento', $hoje->month)
            ->whereDay('dat_nascimento', $hoje->day)
            ->get();

        $this->info("Encontrados {$aniversariantes->count()} aniversariantes hoje.");

        $enviados = 0;

        foreach ($aniversariantes as $membro) {
            if ($membro->eml_membro) {
                try {
                    Mail::to($membro->eml_membro)
                        ->send(new LembreteAniversarioMail($membro));

                    $this->line("E-mail de aniversário enviado para: {$membro->nom_membro} ({$membro->eml_membro})");
                    $enviados++;
                } catch (\Exception $e) {
                    $this->error("Falha ao enviar e-mail de aniversário para {$membro->nom_membro}: ".$e->getMessage());
                }
            }
        }

        $this->info("Concluído! {$enviados} e-mails de feliz aniversário enviados.");

        return 0;
    }
}
