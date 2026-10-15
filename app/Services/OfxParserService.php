<?php

namespace App\Services;

use App\Models\Associacao;
use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfxParserService
{
    private const NOMES_MESES = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    /**
     * Processa o arquivo OFX e persiste os dados no banco, de forma idempotente
     * (reconciliacao incremental do periodo). Assinatura publica travada.
     */
    public function processar(string $caminhoArquivo, string $nomeOriginal, ?int $idtAssociacao = null): Ofx
    {
        // (a) Hash primeiro, antes de qualquer escrita no banco (Req 1.5).
        $hash = @hash_file('sha256', $caminhoArquivo);
        if ($hash === false) {
            Log::error("Nao foi possivel ler o arquivo OFX para gerar a assinatura: {$nomeOriginal}");
            throw new \RuntimeException('Nao foi possivel ler o arquivo OFX para gerar a assinatura.');
        }

        $conteudo = $this->lerArquivo($caminhoArquivo);

        $codBanco = $this->extrairTag($conteudo, 'BANKID');
        $numConta = $this->extrairTag($conteudo, 'ACCTID');
        $datInicio = $this->converterDataOfx($this->extrairTag($conteudo, 'DTSTART'));
        $datFim = $this->converterDataOfx($this->extrairTag($conteudo, 'DTEND'));

        // (b) Curto-circuito por hash identico na associacao (Req 2.1).
        $ofxHashIgual = Ofx::withoutGlobalScopes()
            ->when(
                $idtAssociacao === null,
                fn ($q) => $q->whereNull('idt_associacao'),
                fn ($q) => $q->where('idt_associacao', $idtAssociacao)
            )
            ->where('hash_arquivo', $hash)
            ->first();

        if ($ofxHashIgual) {
            $ofxHashIgual->update(['des_arquivo' => $nomeOriginal]);
            $ofxHashIgual->resultadoImportacao = [
                'status' => 'ja_atualizado',
                'novas' => 0,
                'jaExistentes' => 0,
                'fitidInvalidos' => 0,
            ];

            return $ofxHashIgual;
        }

        // (c) Reuso/criacao do Registro_OFX pela chave logica (assoc, Conta_Origem, periodo).
        $ofx = Ofx::withoutGlobalScopes()
            ->when(
                $idtAssociacao === null,
                fn ($q) => $q->whereNull('idt_associacao'),
                fn ($q) => $q->where('idt_associacao', $idtAssociacao)
            )
            ->whereRaw("COALESCE(cod_banco, '') = ?", [(string) ($codBanco ?? '')])
            ->whereRaw("COALESCE(num_conta, '') = ?", [(string) ($numConta ?? '')])
            ->where('dat_inicio', $datInicio)
            ->where('dat_fim', $datFim)
            ->first();

        $status = 'reconciliado';
        if (! $ofx) {
            $status = 'criado';
            $ofx = Ofx::create([
                'idt_associacao' => $idtAssociacao,
                'des_arquivo' => $nomeOriginal,
                'hash_arquivo' => $hash,
                'cod_banco' => $codBanco,
                'num_conta' => $numConta,
                'dat_inicio' => $datInicio,
                'dat_fim' => $datFim,
                'qtd_transacao' => 0,
                'val_total' => 0,
            ]);
        } else {
            $ofx->update([
                'des_arquivo' => $nomeOriginal,
                'hash_arquivo' => $hash,
            ]);
        }

        // (d) Transacao de banco por arquivo (atomica).
        DB::beginTransaction();

        try {
            $contadores = $this->salvarTransacoes($ofx, $conteudo);

            // Recalcula os totais direto do banco (reconciliacao sem acumulo - Req 2.3).
            $qtd = Transacao::withoutGlobalScopes()->where('idt_ofx', $ofx->idt_ofx)->count();
            $val = Transacao::withoutGlobalScopes()
                ->where('idt_ofx', $ofx->idt_ofx)
                ->where('tip_transacao', 'CREDIT')
                ->sum('val_transacao');

            $ofx->update([
                'qtd_transacao' => $qtd,
                'val_total' => (float) $val,
            ]);

            $this->gerarResumosMensais($ofx);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Erro ao processar OFX [{$nomeOriginal}]: ".$e->getMessage());
            throw $e;
        }

        // (e) resultadoImportacao com status + contadores (lido pela tela de upload).
        $ofx->resultadoImportacao = [
            'status' => $status,
            'novas' => $contadores['novas'],
            'jaExistentes' => $contadores['jaExistentes'],
            'fitidInvalidos' => $contadores['fitidInvalidos'],
        ];

        return $ofx;
    }

    /**
     * Le o arquivo e normaliza a codificacao e quebras de linha.
     */
    private function lerArquivo(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);

        if (str_contains($conteudo, 'ENCODING:UTF-8') || mb_check_encoding($conteudo, 'UTF-8')) {
            // Ja esta em UTF-8
        } else {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }

        return str_replace(["\r\n", "\r"], "\n", $conteudo);
    }

    /**
     * Persiste as transacoes extraidas do conteudo OFX com dedup escopada por
     * associacao + Conta_Origem (Req 3) e retorna os contadores da importacao.
     *
     * @return array{novas:int, jaExistentes:int, fitidInvalidos:int}
     */
    private function salvarTransacoes(Ofx $ofx, string $conteudo): array
    {
        $blocos = $this->extrairBlocosTransacao($conteudo);

        $novas = 0;
        $jaExistentes = 0;
        $fitidInvalidos = 0;

        // Dedup intra-arquivo em memoria (Req 3.5).
        $fitidsVistos = [];

        if (($ofx->num_conta ?? '') === '' || ($ofx->cod_banco ?? '') === '') {
            Log::warning('OFX sem Conta_Origem completa na associacao '.($ofx->idt_associacao ?? 'null')." - dedup escopada por banco/conta vazios (idt_ofx={$ofx->idt_ofx}).");
        }

        foreach ($blocos as $bloco) {
            $datTransacao = $this->converterDataOfx($bloco['DTPOSTED'] ?? null);

            if (! $datTransacao) {
                continue;
            }

            $fitid = trim($bloco['FITID'] ?? '');
            $name = trim($bloco['NAME'] ?? '');

            // Ignora registros informacionais de saldo.
            if (str_contains(strtolower($name), 'saldo anterior') ||
                str_contains(strtolower($name), 'saldo do dia') ||
                str_contains(strtolower($name), 'saldo anterior/atual')) {
                continue;
            }

            // FITID ausente/vazio/nulo (Req 3.4).
            if ($fitid === '') {
                $fitidInvalidos++;

                continue;
            }

            // Dedup intra-arquivo (Req 3.5).
            if (isset($fitidsVistos[$fitid])) {
                $jaExistentes++;

                continue;
            }

            // Dedup escopada por associacao + Conta_Origem (Req 3.1-3.3).
            $fitidExiste = Transacao::withoutGlobalScopes()
                ->where('num_transacao', $fitid)
                ->whereHas('ofx', function ($q) use ($ofx) {
                    $q->withoutGlobalScopes();
                    $ofx->idt_associacao === null
                        ? $q->whereNull('idt_associacao')
                        : $q->where('idt_associacao', $ofx->idt_associacao);
                    $q->whereRaw("COALESCE(cod_banco, '') = ?", [(string) ($ofx->cod_banco ?? '')]);
                    $q->whereRaw("COALESCE(num_conta, '') = ?", [(string) ($ofx->num_conta ?? '')]);
                })
                ->exists();

            if ($fitidExiste) {
                $jaExistentes++;
                $fitidsVistos[$fitid] = true;

                continue;
            }

            $valor = (float) str_replace(',', '.', $bloco['TRNAMT'] ?? '0');

            $cpf = $this->extrairCpfDoMemo($bloco['MEMO'] ?? null);
            $membro = null;
            if ($cpf) {
                $membro = Membro::withoutGlobalScopes()
                    ->when($ofx->idt_associacao, fn ($q) => $q->where('idt_associacao', $ofx->idt_associacao))
                    ->where(function ($query) use ($cpf) {
                        $query->whereRaw("REPLACE(REPLACE(num_cpf_membro, '.', ''), '-', '') = ?", [$cpf]);
                        if (strlen($cpf) === 14 && str_starts_with($cpf, '000')) {
                            $query->orWhereRaw("REPLACE(REPLACE(num_cpf_membro, '.', ''), '-', '') = ?", [substr($cpf, 3)]);
                        }
                    })->first();
            }

            Transacao::create([
                'idt_ofx' => $ofx->idt_ofx,
                'idt_membro' => $membro ? $membro->idt_membro : null,
                'num_transacao' => $fitid,
                'dat_transacao' => $datTransacao,
                'tip_transacao' => $bloco['TRNTYPE'] ?? null,
                'val_transacao' => $valor,
                'des_transacao' => $this->limparDescricao($bloco['MEMO'] ?? null),
                'num_check' => $bloco['CHECKNUM'] ?? null,
                'nom_pessoa' => $bloco['NAME'] ?? null,
                'num_cpf_pagador' => $cpf,
            ]);

            $fitidsVistos[$fitid] = true;
            $novas++;
        }

        if ($fitidInvalidos > 0) {
            Log::warning("Importacao do OFX idt_ofx={$ofx->idt_ofx}: {$fitidInvalidos} lancamento(s) ignorado(s) por FITID ausente/vazio.");
        }

        return [
            'novas' => $novas,
            'jaExistentes' => $jaExistentes,
            'fitidInvalidos' => $fitidInvalidos,
        ];
    }

    /**
     * Gera ou atualiza os resumos mensais agrupados por pessoa, de forma
     * idempotente e sem acumulo (Req 5). Executa dentro da transacao de processar().
     */
    private function gerarResumosMensais(Ofx $ofx): void
    {
        $associacao = Associacao::find($ofx->idt_associacao);
        $taxaVigentePadrao = $associacao ? $associacao->getTaxaVigenteEm() : null;
        $valTaxaPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_taxa : 0.0;
        $valAnualPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_anual : 0.0;

        // Credito canonico = tip_transacao CREDIT (alinhado ao dashboard e ao val_total).
        $transacoes = $ofx->transacoes()
            ->where('tip_transacao', 'CREDIT')
            ->get();

        $porPessoa = $transacoes->groupBy(function ($t) {
            return $t->idt_membro ? 'membro_'.$t->idt_membro : 'chv_'.$this->chavePessoaSemMembro($t);
        });

        foreach ($porPessoa as $transacoesPessoa) {
            $primeiraTransacao = $transacoesPessoa->first();
            $idtMembro = $primeiraTransacao->idt_membro;
            $chvPessoa = $idtMembro ? null : $this->chavePessoaSemMembro($primeiraTransacao);
            $nomPessoa = $idtMembro ? null : ($primeiraTransacao->des_transacao ?: 'Não identificado');

            $porMes = $transacoesPessoa->groupBy(
                fn ($t) => $t->dat_transacao->format('Y').'-'.$t->dat_transacao->format('n')
            );

            foreach ($porMes as $anoMes => $itens) {
                [$ano, $mes] = array_map('intval', explode('-', $anoMes));

                // Validacao de periodo (Req 5.4).
                if ($mes < 1 || $mes > 12 || $ano < 1900 || $ano > 2100) {
                    Log::warning("Resumo ignorado por periodo invalido (ano={$ano}, mes={$mes}) no idt_ofx={$ofx->idt_ofx}.");

                    continue;
                }

                $dataRef = sprintf('%04d-%02d-01', $ano, $mes);
                $taxaVigente = $associacao ? $associacao->getTaxaVigenteEm($dataRef) : null;
                $valTaxa = $taxaVigente ? (float) $taxaVigente->val_taxa : $valTaxaPadrao;
                $valAnual = $taxaVigente ? (float) $taxaVigente->val_anual : $valAnualPadrao;

                $total = (float) $itens->sum('val_transacao');
                $qtd = $itens->count();

                $indPago = false;
                if ($total > 0) {
                    if (($valTaxa > 0 && $total >= $valTaxa) || ($valAnual > 0 && $total >= $valAnual)) {
                        $indPago = true;
                    } elseif ($valTaxa <= 0 && $valAnual <= 0) {
                        $indPago = true;
                    }
                }

                $valores = [
                    'nom_mes' => self::NOMES_MESES[$mes] ?? "Mês {$mes}",
                    'val_total' => $total,
                    'qtd_transacao' => $qtd,
                    'ind_pago' => $indPago,
                ];

                if ($idtMembro) {
                    // Mesclagem em colisao da UNIQUE (idt_ofx, idt_membro, num_ano, num_mes).
                    $resumo = $this->resolverResumoComMembro($ofx, $idtMembro, $ano, $mes, $valores);
                } else {
                    $resumo = Resumo::updateOrCreate(
                        [
                            'idt_ofx' => $ofx->idt_ofx,
                            'chv_pessoa' => $chvPessoa,
                            'num_ano' => $ano,
                            'num_mes' => $mes,
                        ],
                        array_merge($valores, [
                            'idt_membro' => null,
                            'nom_pessoa' => $nomPessoa,
                        ])
                    );
                }

                foreach ($itens as $item) {
                    if ($item->idt_resumo !== $resumo->idt_resumo) {
                        $item->update(['idt_resumo' => $resumo->idt_resumo]);
                    }
                }
            }
        }

        // Consolidacao de orfaos sem-membro (migracao parcial via atualizarPagamentos).
        $this->consolidarOrfaosSemMembro($ofx);
    }

    /**
     * Resolve o resumo com membro para o periodo, mesclando eventuais colisoes da
     * UNIQUE (idt_ofx, idt_membro, num_ano, num_mes): mantem o de menor idt_resumo,
     * reatribui as transacoes e remove os excedentes, recalculando sem acumulo.
     *
     * @param  array<string, mixed>  $valores
     */
    private function resolverResumoComMembro(Ofx $ofx, int $idtMembro, int $ano, int $mes, array $valores): Resumo
    {
        $candidatos = Resumo::withoutGlobalScopes()
            ->where('idt_ofx', $ofx->idt_ofx)
            ->where('idt_membro', $idtMembro)
            ->where('num_ano', $ano)
            ->where('num_mes', $mes)
            ->orderBy('idt_resumo')
            ->get();

        if ($candidatos->count() <= 1) {
            return Resumo::updateOrCreate(
                [
                    'idt_ofx' => $ofx->idt_ofx,
                    'idt_membro' => $idtMembro,
                    'num_ano' => $ano,
                    'num_mes' => $mes,
                ],
                array_merge($valores, ['chv_pessoa' => null, 'nom_pessoa' => null])
            );
        }

        // Mescla: mantem o menor id, reatribui transacoes dos excedentes e remove-os.
        $mantido = $candidatos->first();
        $excedentes = $candidatos->slice(1);

        foreach ($excedentes as $excedente) {
            Transacao::withoutGlobalScopes()
                ->where('idt_resumo', $excedente->idt_resumo)
                ->update(['idt_resumo' => $mantido->idt_resumo]);
            $excedente->delete();
        }

        $mantido->update(array_merge($valores, ['chv_pessoa' => null, 'nom_pessoa' => null]));

        return $mantido;
    }

    /**
     * Remove resumos sem-membro que ficaram orfaos apos a migracao de idt_membro,
     * respeitando as salvaguardas: nunca remove chave nao-discriminante
     * ('nao_identificado'/'Não identificado'); so remove quando todas as transacoes
     * do grupo migraram para um unico membro cujo resumo cobre a quantidade.
     */
    private function consolidarOrfaosSemMembro(Ofx $ofx): void
    {
        $orfaos = Resumo::withoutGlobalScopes()
            ->where('idt_ofx', $ofx->idt_ofx)
            ->whereNull('idt_membro')
            ->get();

        foreach ($orfaos as $orfao) {
            // Salvaguarda 1: nunca remover chave nao-discriminante.
            if ($orfao->chv_pessoa === 'nao_identificado' || $orfao->nom_pessoa === 'Não identificado') {
                continue;
            }

            // Transacoes ainda apontando para o resumo orfao.
            $transacoesOrfao = Transacao::withoutGlobalScopes()
                ->where('idt_resumo', $orfao->idt_resumo)
                ->get();

            // Se ainda ha transacoes sem membro, o orfao representa elas: preservar.
            $aindaSemMembro = $transacoesOrfao->whereNull('idt_membro')->count();
            if ($aindaSemMembro > 0) {
                continue;
            }

            // Membros para os quais as transacoes migraram.
            $membrosDestino = $transacoesOrfao->pluck('idt_membro')->filter()->unique()->values();

            // Salvaguarda 2: so remover se migrou integralmente para um unico membro.
            if ($membrosDestino->count() !== 1) {
                continue;
            }

            $idtMembro = (int) $membrosDestino->first();
            $resumoComMembro = Resumo::withoutGlobalScopes()
                ->where('idt_ofx', $ofx->idt_ofx)
                ->where('idt_membro', $idtMembro)
                ->where('num_ano', $orfao->num_ano)
                ->where('num_mes', $orfao->num_mes)
                ->first();

            if (! $resumoComMembro || (int) $resumoComMembro->qtd_transacao < (int) $orfao->qtd_transacao) {
                continue;
            }

            // Reatribui as transacoes remanescentes ao resumo com membro e remove o orfao.
            Transacao::withoutGlobalScopes()
                ->where('idt_resumo', $orfao->idt_resumo)
                ->update(['idt_resumo' => $resumoComMembro->idt_resumo]);

            $orfao->delete();
        }
    }

    /**
     * Chave de idempotencia estavel para uma pessoa sem membro vinculado.
     * Precedencia: cpf -> descricao util -> name cru -> literal nao_identificado.
     */
    private function chavePessoaSemMembro(Transacao $t): string
    {
        $cpf = $t->num_cpf_pagador ? preg_replace('/\D/', '', $t->num_cpf_pagador) : '';
        if ($cpf !== '') {
            return 'cpf:'.$cpf;
        }

        $desc = trim((string) $t->des_transacao);
        if ($desc !== '' && $desc !== 'Não identificado') {
            $norm = preg_replace('/\s+/', ' ', mb_strtolower($desc));

            return 'desc:'.$norm;
        }

        $name = trim((string) $t->nom_pessoa);
        if ($name !== '') {
            $norm = preg_replace('/\s+/', ' ', mb_strtolower($name));

            return 'name:'.$norm;
        }

        return 'nao_identificado';
    }

    /**
     * Extrai todos os blocos <STMTTRN> do conteudo OFX.
     *
     * @return array<int, array<string, string|null>>
     */
    private function extrairBlocosTransacao(string $conteudo): array
    {
        $blocos = [];
        $campos = ['TRNTYPE', 'DTPOSTED', 'TRNAMT', 'FITID', 'CHECKNUM', 'NAME', 'MEMO'];

        if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/s', $conteudo, $matches)) {
            foreach ($matches[1] as $bloco) {
                $transacao = [];
                foreach ($campos as $campo) {
                    $transacao[$campo] = $this->extrairTag($bloco, $campo);
                }
                $blocos[] = $transacao;
            }
        }

        return $blocos;
    }

    /**
     * Extrai o valor de uma tag SGML/OFX (ex: <BANKID>001).
     */
    private function extrairTag(string $conteudo, string $tag): ?string
    {
        if (preg_match("/<{$tag}>([^<\n]+)/i", $conteudo, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Converte data no formato OFX (YYYYMMDD[HHMMSS]) para Y-m-d.
     */
    private function converterDataOfx(?string $data): ?string
    {
        if (empty($data)) {
            return null;
        }

        $data = preg_replace('/\[.*?\]/', '', $data);
        $dataLimpa = substr($data, 0, 8);

        if (strlen($dataLimpa) === 8 && ctype_digit($dataLimpa)) {
            $ano = substr($dataLimpa, 0, 4);
            $mes = substr($dataLimpa, 4, 2);
            $dia = substr($dataLimpa, 6, 2);

            if (checkdate((int) $mes, (int) $dia, (int) $ano)) {
                return "{$ano}-{$mes}-{$dia}";
            }
        }

        return null;
    }

    /**
     * Limpa o campo MEMO removendo ruidos comuns do Banco do Brasil.
     */
    private function limparDescricao(?string $descricao): ?string
    {
        if (empty($descricao)) {
            return null;
        }

        $descricao = preg_replace('/\s+/', ' ', trim($descricao));
        $descricao = preg_replace('/^\d{2}\/\d{2}(?:\s+\d{2}:\d{2})?\s+/', '', $descricao);
        $descricao = preg_replace('/^(?:\d|[\.\-\/]){11,18}\s+/', '', $descricao);
        $descricao = preg_replace('/\bAG\s*\d+\s*CC\s*[\d\-]+\b/i', '', $descricao);
        $descricao = preg_replace('/\bPIX\s*-\s*ENVIADO\b/i', '', $descricao);
        $descricao = preg_replace('/\bPIX\s*RECEBIDO\b/i', '', $descricao);
        $descricao = preg_replace('/\bTED\s*-\s*\d+\b/i', '', $descricao);
        $descricao = preg_replace('/\bDOC\s*-\s*\d+\b/i', '', $descricao);

        return trim($descricao) ?: 'Não identificado';
    }

    /**
     * Extrai o CPF/CNPJ (apenas digitos) do campo MEMO.
     */
    public function extrairCpfDoMemo(?string $memo): ?string
    {
        if (empty($memo)) {
            return null;
        }

        $memo = preg_replace('/\s+/', ' ', trim($memo));
        $memoSemData = preg_replace('/^\d{2}\/\d{2}(?:\s+\d{2}:\d{2})?\s+/', '', $memo);

        if (preg_match('/^((?:\d|[\.\-\/]){11,18})/', $memoSemData, $matches)) {
            $digits = preg_replace('/\D/', '', $matches[1]);

            if (strlen($digits) === 14 && str_starts_with($digits, '000')) {
                $digits = substr($digits, 3);
            }

            return $digits;
        }

        return null;
    }
}
