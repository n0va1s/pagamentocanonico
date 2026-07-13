<?php

namespace App\Console\Commands;

use App\Models\Membro;
use App\Models\Resumo;
use App\Models\Transacao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VincularMembro extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pag:vincular-membro';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processa as transações e resumos antigos para vinculá-los ao membro informado';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $membros = Membro::all();

        $this->info("Iniciando vinculação para " . $membros->count() . " membros...");

        $totalResumosAfetados = 0;
        $totalTransacoesAfetadas = 0;

        DB::beginTransaction();
        try {
            foreach ($membros as $membro) {
                $membroCpf = $membro->num_cpf_membro ? preg_replace('/\D/', '', $membro->num_cpf_membro) : null;
                
                // Vinculando Transacoes (opcional mas recomendado para consistência)
                $transacoesQuery = Transacao::withoutGlobalScope('associacao')
                    ->whereNull('idt_membro')
                    ->where(function ($query) use ($membro, $membroCpf) {
                        $query->where('nom_pessoa', $membro->nom_membro);
                        $query->orWhere('des_transacao', 'LIKE', '%' . $membro->nom_membro . '%');
                        
                        if ($membro->nom_apelido) {
                            $query->orWhere('nom_pessoa', $membro->nom_apelido);
                            $query->orWhere('des_transacao', 'LIKE', '%' . $membro->nom_apelido . '%');
                        }

                        if ($membroCpf) {
                            $query->orWhereRaw("REPLACE(REPLACE(num_cpf_pagador, '.', ''), '-', '') = ?", [$membroCpf]);
                            $query->orWhereRaw("REPLACE(REPLACE(num_cpf_pagador, '.', ''), '-', '') LIKE ?", ['%'.$membroCpf]);
                        }
                    });

                $transacoes = $transacoesQuery->get();
                $afetadosTransacao = $transacoesQuery->update([
                    'idt_membro' => $membro->idt_membro,
                ]);

                $totalTransacoesAfetadas += $afetadosTransacao;

                // Vinculando Resumos das transações encontradas
                $idtResumos = $transacoes->pluck('idt_resumo')->filter()->unique();
                if ($idtResumos->isNotEmpty()) {
                    $afetadosResumo = Resumo::withoutGlobalScope('associacao')
                        ->whereIn('idt_resumo', $idtResumos)
                        ->update(['idt_membro' => $membro->idt_membro]);
                    
                    $totalResumosAfetados += $afetadosResumo;
                }
            }

            DB::commit();

            $this->info("Vinculação concluída com sucesso!");
            $this->line("Resumos atualizados: {$totalResumosAfetados}");
            $this->line("Transações atualizadas: {$totalTransacoesAfetadas}");

            return 0;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Erro ao vincular membros: " . $e->getMessage());
            return 1;
        }
    }
}
