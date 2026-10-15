<?php

namespace App\Services;

use App\Exceptions\AutorizacaoAuditoriaException;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servico de auditoria de duplicidades. Stateless; opera sempre dentro do
 * escopo de UMA associacao, recebida explicitamente (contrato int, nunca nulo).
 * Todas as consultas usam withoutGlobalScopes() + filtro explicito por associacao.
 */
class AuditoriaService
{
    /**
     * Detecta duplicidades da associacao e retorna o relatorio consolidado.
     *
     * @return array{ofx_reimportados: Collection, transacoes_duplicadas: Collection, resumos_duplicados: Collection, total_excedente: float}
     */
    public function detectar(int $idtAssociacao): array
    {
        $transacoesDuplicadas = $this->detectarTransacoesDuplicadas($idtAssociacao);
        $resumosDuplicados = $this->detectarResumosDuplicados($idtAssociacao);
        $ofxReimportados = $this->detectarOfxReimportados($idtAssociacao);

        $totalExcedente = round(
            (float) $transacoesDuplicadas->sum('valor_excedente')
            + (float) $resumosDuplicados->sum('valor_excedente'),
            2
        );

        return [
            'ofx_reimportados' => $ofxReimportados,
            'transacoes_duplicadas' => $transacoesDuplicadas,
            'resumos_duplicados' => $resumosDuplicados,
            'total_excedente' => $totalExcedente,
        ];
    }

    /**
     * Conta grupos de duplicidade pendentes (transacoes + resumos) por agregacao leve.
     */
    public function contarPendentes(int $idtAssociacao): int
    {
        $gruposTransacoes = Transacao::withoutGlobalScopes()
            ->join('ofx', 'transacoes.idt_ofx', '=', 'ofx.idt_ofx')
            ->where('ofx.idt_associacao', $idtAssociacao)
            ->whereNotNull('transacoes.num_transacao')
            ->where('transacoes.num_transacao', '!=', '')
            ->selectRaw("COALESCE(ofx.cod_banco, '') as cb")
            ->selectRaw("COALESCE(ofx.num_conta, '') as nc")
            ->addSelect('transacoes.num_transacao')
            ->groupBy(DB::raw("COALESCE(ofx.cod_banco, '')"), DB::raw("COALESCE(ofx.num_conta, '')"), 'transacoes.num_transacao')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();

        $gruposResumos = Resumo::withoutGlobalScopes()
            ->join('ofx', 'resumos.idt_ofx', '=', 'ofx.idt_ofx')
            ->where('ofx.idt_associacao', $idtAssociacao)
            ->selectRaw('resumos.idt_ofx as io')
            ->selectRaw('COALESCE(resumos.idt_membro, -1) as cm')
            ->selectRaw("COALESCE(resumos.chv_pessoa, '') as cp")
            ->selectRaw('resumos.num_ano as na')
            ->selectRaw('resumos.num_mes as nm')
            ->groupBy('resumos.idt_ofx', DB::raw('COALESCE(resumos.idt_membro, -1)'), DB::raw("COALESCE(resumos.chv_pessoa, '')"), 'resumos.num_ano', 'resumos.num_mes')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();

        return $gruposTransacoes + $gruposResumos;
    }

    /**
     * Detecta grupos de Transacao_Duplicada (mesmo FITID na mesma assoc + Conta_Origem).
     */
    private function detectarTransacoesDuplicadas(int $idtAssociacao): Collection
    {
        $grupos = Transacao::withoutGlobalScopes()
            ->join('ofx', 'transacoes.idt_ofx', '=', 'ofx.idt_ofx')
            ->where('ofx.idt_associacao', $idtAssociacao)
            ->whereNotNull('transacoes.num_transacao')
            ->where('transacoes.num_transacao', '!=', '')
            ->selectRaw("COALESCE(ofx.cod_banco, '') as cod_banco")
            ->selectRaw("COALESCE(ofx.num_conta, '') as num_conta")
            ->addSelect('transacoes.num_transacao as num_transacao')
            ->selectRaw('COUNT(*) as total')
            ->groupBy(DB::raw("COALESCE(ofx.cod_banco, '')"), DB::raw("COALESCE(ofx.num_conta, '')"), 'transacoes.num_transacao')
            ->havingRaw('COUNT(*) >= 2')
            ->get();

        return $grupos->map(function ($grupo) use ($idtAssociacao) {
            $transacoes = Transacao::withoutGlobalScopes()
                ->join('ofx', 'transacoes.idt_ofx', '=', 'ofx.idt_ofx')
                ->where('ofx.idt_associacao', $idtAssociacao)
                ->where('transacoes.num_transacao', $grupo->num_transacao)
                ->whereRaw("COALESCE(ofx.cod_banco, '') = ?", [$grupo->cod_banco])
                ->whereRaw("COALESCE(ofx.num_conta, '') = ?", [$grupo->num_conta])
                ->orderBy('transacoes.idt_transacao')
                ->select('transacoes.*')
                ->get();

            // Excedentes = todas menos a de menor idt_transacao (a mantida).
            $excedentes = $transacoes->slice(1);
            $valorExcedente = round(
                (float) $excedentes->where('tip_transacao', 'CREDIT')->sum('val_transacao'),
                2
            );

            return (object) [
                'tipo' => 'transacao',
                'cod_banco' => $grupo->cod_banco,
                'num_conta' => $grupo->num_conta,
                'num_transacao' => $grupo->num_transacao,
                'total' => (int) $grupo->total,
                'excedentes' => $transacoes->count() - 1,
                'valor_excedente' => $valorExcedente,
                'registros' => $transacoes,
                'chave_grupo' => $this->montarChaveTransacao($idtAssociacao, $grupo->cod_banco, $grupo->num_conta, $grupo->num_transacao),
            ];
        })->values();
    }

    /**
     * Detecta grupos de Resumo_Duplicado (mesma pessoa, idt_ofx, ano, mes).
     */
    private function detectarResumosDuplicados(int $idtAssociacao): Collection
    {
        $grupos = Resumo::withoutGlobalScopes()
            ->join('ofx', 'resumos.idt_ofx', '=', 'ofx.idt_ofx')
            ->where('ofx.idt_associacao', $idtAssociacao)
            ->addSelect('resumos.idt_ofx as idt_ofx')
            ->selectRaw('COALESCE(resumos.idt_membro, -1) as chave_membro')
            ->selectRaw("COALESCE(resumos.chv_pessoa, '') as chave_pessoa")
            ->addSelect('resumos.num_ano as num_ano')
            ->addSelect('resumos.num_mes as num_mes')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('resumos.idt_ofx', DB::raw('COALESCE(resumos.idt_membro, -1)'), DB::raw("COALESCE(resumos.chv_pessoa, '')"), 'resumos.num_ano', 'resumos.num_mes')
            ->havingRaw('COUNT(*) >= 2')
            ->get();

        return $grupos->map(function ($grupo) {
            $query = Resumo::withoutGlobalScopes()
                ->where('idt_ofx', $grupo->idt_ofx)
                ->where('num_ano', $grupo->num_ano)
                ->where('num_mes', $grupo->num_mes)
                ->orderBy('idt_resumo');

            if ((int) $grupo->chave_membro === -1) {
                $query->whereNull('idt_membro')
                    ->whereRaw("COALESCE(chv_pessoa, '') = ?", [$grupo->chave_pessoa]);
                $chavePessoa = 'p:'.$grupo->chave_pessoa;
            } else {
                $query->where('idt_membro', (int) $grupo->chave_membro);
                $chavePessoa = 'm:'.(int) $grupo->chave_membro;
            }

            $resumos = $query->get();
            $mantido = $resumos->first();
            $valorExcedente = round(
                (float) $resumos->sum('val_total') - (float) ($mantido->val_total ?? 0),
                2
            );

            return (object) [
                'tipo' => 'resumo',
                'idt_ofx' => (int) $grupo->idt_ofx,
                'chave_pessoa' => $chavePessoa,
                'num_ano' => (int) $grupo->num_ano,
                'num_mes' => (int) $grupo->num_mes,
                'total' => (int) $grupo->total,
                'excedentes' => $resumos->count() - 1,
                'valor_excedente' => $valorExcedente,
                'registros' => $resumos,
                'chave_grupo' => $this->montarChaveResumo($grupo->idt_ofx, $chavePessoa, $grupo->num_ano, $grupo->num_mes),
            ];
        })->values();
    }

    /**
     * Sinaliza OFX reimportados (hash identico) e OFX de periodo redundante
     * (mesma Conta_Origem, hash diferente, periodos sobrepostos). Apenas informativo.
     */
    private function detectarOfxReimportados(int $idtAssociacao): Collection
    {
        $sinalizacoes = collect();

        // (a) Mesmo arquivo reimportado: hash_arquivo nao nulo repetido.
        $gruposHash = Ofx::withoutGlobalScopes()
            ->where('idt_associacao', $idtAssociacao)
            ->whereNotNull('hash_arquivo')
            ->select('hash_arquivo')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hash_arquivo')
            ->havingRaw('COUNT(*) >= 2')
            ->get();

        foreach ($gruposHash as $grupo) {
            $registros = Ofx::withoutGlobalScopes()
                ->where('idt_associacao', $idtAssociacao)
                ->where('hash_arquivo', $grupo->hash_arquivo)
                ->orderBy('idt_ofx')
                ->get();

            $sinalizacoes->push((object) [
                'tipo' => 'ofx_reimportado',
                'motivo' => 'Arquivo reimportado',
                'registros' => $registros,
            ]);
        }

        // (b) OFX de periodo redundante: mesma Conta_Origem, hash diferente, periodos sobrepostos.
        $ofxs = Ofx::withoutGlobalScopes()
            ->where('idt_associacao', $idtAssociacao)
            ->get();

        $porConta = $ofxs->groupBy(fn ($o) => (string) ($o->cod_banco ?? '').'|'.(string) ($o->num_conta ?? ''));

        foreach ($porConta as $grupoConta) {
            $lista = $grupoConta->values();
            for ($i = 0; $i < $lista->count(); $i++) {
                for ($j = $i + 1; $j < $lista->count(); $j++) {
                    $a = $lista[$i];
                    $b = $lista[$j];

                    if ($a->hash_arquivo !== null && $b->hash_arquivo !== null && $a->hash_arquivo === $b->hash_arquivo) {
                        continue; // ja coberto por (a)
                    }

                    // Todas as quatro datas precisam ser nao nulas.
                    if (! $a->dat_inicio || ! $a->dat_fim || ! $b->dat_inicio || ! $b->dat_fim) {
                        continue;
                    }

                    $aIni = $a->dat_inicio;
                    $aFim = $a->dat_fim;
                    $bIni = $b->dat_inicio;
                    $bFim = $b->dat_fim;

                    // Intersecao nao vazia: A.inicio <= B.fim AND B.inicio <= A.fim.
                    if ($aIni <= $bFim && $bIni <= $aFim) {
                        $sinalizacoes->push((object) [
                            'tipo' => 'ofx_periodo_redundante',
                            'motivo' => 'OFX de período redundante',
                            'registros' => collect([$a, $b]),
                        ]);
                    }
                }
            }
        }

        return $sinalizacoes->values();
    }

    /**
     * Corrige um grupo de transacoes duplicadas: mantem a de menor id e remove as demais,
     * recalculando os totais dos OFX afetados. Atomico, com revalidacao de associacao.
     */
    public function corrigirTransacoesDuplicadas(int $idtAssociacao, string $chaveGrupo, int $idtUsuario): void
    {
        $partes = explode('|', $chaveGrupo);
        if (count($partes) !== 5 || $partes[0] !== 'tx') {
            Log::warning("Chave de grupo de transacao malformada: {$chaveGrupo}");
            throw new AutorizacaoAuditoriaException('Formato de grupo de duplicidade inválido.');
        }

        $assocChave = (int) $partes[1];
        $codBanco = rawurldecode($partes[2]);
        $numConta = rawurldecode($partes[3]);
        $numTransacao = rawurldecode($partes[4]);

        if ($assocChave !== $idtAssociacao) {
            Log::warning("Tentativa de corrigir transacoes de outra associacao (chave={$assocChave}, contexto={$idtAssociacao}).");
            throw new AutorizacaoAuditoriaException;
        }

        DB::transaction(function () use ($idtAssociacao, $codBanco, $numConta, $numTransacao, $idtUsuario) {
            $transacoes = Transacao::withoutGlobalScopes()
                ->join('ofx', 'transacoes.idt_ofx', '=', 'ofx.idt_ofx')
                ->where('ofx.idt_associacao', $idtAssociacao)
                ->where('transacoes.num_transacao', $numTransacao)
                ->whereRaw("COALESCE(ofx.cod_banco, '') = ?", [$codBanco])
                ->whereRaw("COALESCE(ofx.num_conta, '') = ?", [$numConta])
                ->orderBy('transacoes.idt_transacao')
                ->select('transacoes.*')
                ->get();

            if ($transacoes->count() < 2) {
                return;
            }

            $excedentes = $transacoes->slice(1);
            $idsRemovidos = $excedentes->pluck('idt_transacao')->all();
            $ofxAfetados = $transacoes->pluck('idt_ofx')->unique()->values()->all();

            Transacao::withoutGlobalScopes()->whereIn('idt_transacao', $idsRemovidos)->delete();

            $this->recalcularOfx($ofxAfetados);

            Log::info('Correcao de transacoes duplicadas aplicada.', [
                'operacao' => 'corrigirTransacoesDuplicadas',
                'idt_associacao' => $idtAssociacao,
                'num_transacao' => $numTransacao,
                'ids_removidos' => $idsRemovidos,
                'idt_ofx_afetados' => $ofxAfetados,
                'idt_usuario' => $idtUsuario,
            ]);
        });
    }

    /**
     * Corrige um grupo de resumos duplicados: mantem o de menor id e remove os demais,
     * recalculando os totais dos OFX afetados. Atomico, com revalidacao de associacao.
     */
    public function corrigirResumosDuplicados(int $idtAssociacao, string $chaveGrupo, int $idtUsuario): void
    {
        $partes = explode('|', $chaveGrupo);
        if (count($partes) !== 5 || $partes[0] !== 'rs') {
            Log::warning("Chave de grupo de resumo malformada: {$chaveGrupo}");
            throw new AutorizacaoAuditoriaException('Formato de grupo de duplicidade inválido.');
        }

        $idtOfx = (int) $partes[1];
        $chavePessoa = rawurldecode($partes[2]);
        $numAno = (int) $partes[3];
        $numMes = (int) $partes[4];

        // Revalidacao de associacao: o OFX precisa pertencer a associacao do contexto.
        $ofx = Ofx::withoutGlobalScopes()->where('idt_ofx', $idtOfx)->first();
        if (! $ofx || (int) $ofx->idt_associacao !== $idtAssociacao) {
            Log::warning("Tentativa de corrigir resumos de outra associacao (idt_ofx={$idtOfx}, contexto={$idtAssociacao}).");
            throw new AutorizacaoAuditoriaException;
        }

        DB::transaction(function () use ($idtOfx, $chavePessoa, $numAno, $numMes, $idtAssociacao, $idtUsuario) {
            $query = Resumo::withoutGlobalScopes()
                ->where('idt_ofx', $idtOfx)
                ->where('num_ano', $numAno)
                ->where('num_mes', $numMes)
                ->orderBy('idt_resumo');

            if (str_starts_with($chavePessoa, 'm:')) {
                $query->where('idt_membro', (int) substr($chavePessoa, 2));
            } else {
                $valChv = substr($chavePessoa, 2);
                $query->whereNull('idt_membro')->whereRaw("COALESCE(chv_pessoa, '') = ?", [$valChv]);
            }

            $resumos = $query->get();
            if ($resumos->count() < 2) {
                return;
            }

            $excedentes = $resumos->slice(1);
            $idsRemovidos = $excedentes->pluck('idt_resumo')->all();

            Resumo::withoutGlobalScopes()->whereIn('idt_resumo', $idsRemovidos)->delete();

            $this->recalcularOfx([$idtOfx]);

            Log::info('Correcao de resumos duplicados aplicada.', [
                'operacao' => 'corrigirResumosDuplicados',
                'idt_associacao' => $idtAssociacao,
                'idt_ofx' => $idtOfx,
                'ids_removidos' => $idsRemovidos,
                'idt_usuario' => $idtUsuario,
            ]);
        });
    }

    /**
     * Recalcula qtd_transacao e val_total (soma CREDIT) dos OFX informados.
     *
     * @param  array<int, int>  $idtOfxs
     */
    private function recalcularOfx(array $idtOfxs): void
    {
        foreach (array_unique($idtOfxs) as $idtOfx) {
            $qtd = Transacao::withoutGlobalScopes()->where('idt_ofx', $idtOfx)->count();
            $val = Transacao::withoutGlobalScopes()
                ->where('idt_ofx', $idtOfx)
                ->where('tip_transacao', 'CREDIT')
                ->sum('val_transacao');

            Ofx::withoutGlobalScopes()->where('idt_ofx', $idtOfx)->update([
                'qtd_transacao' => $qtd,
                'val_total' => (float) $val,
            ]);
        }
    }

    private function montarChaveTransacao(int $idtAssociacao, string $codBanco, string $numConta, string $numTransacao): string
    {
        return implode('|', [
            'tx',
            $idtAssociacao,
            rawurlencode($codBanco),
            rawurlencode($numConta),
            rawurlencode($numTransacao),
        ]);
    }

    private function montarChaveResumo(int $idtOfx, string $chavePessoa, int $numAno, int $numMes): string
    {
        return implode('|', [
            'rs',
            $idtOfx,
            rawurlencode($chavePessoa),
            $numAno,
            $numMes,
        ]);
    }
}
