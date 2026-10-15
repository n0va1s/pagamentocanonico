<?php

namespace App\Services;

use App\Enums\EfeitoRegra;
use App\Models\Associacao;
use App\Models\Membro;
use App\Models\Ofx;
use App\Models\RegraClassificacao;
use App\Models\Resumo;
use App\Models\Transacao;
use Illuminate\Support\Collection;
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
     * CHECKNUMs que não identificam unicamente uma transação (ex.: conta da
     * aplicação Rende Fácil, que repete em vários lançamentos). Para esses, a
     * dedup recai sobre a chave de negócio (data+valor+tipo+cpf).
     */
    private const CHECKNUMS_NAO_DISCRIMINANTES = ['9903'];

    /**
     * Processa o arquivo OFX e persiste os dados no banco.
     */
    public function processar(string $caminhoArquivo, string $nomeOriginal, ?int $idtAssociacao = null): Ofx
    {
        $conteudo = $this->lerArquivo($caminhoArquivo);

        $codBanco = $this->extrairTag($conteudo, 'BANKID');
        $numConta = $this->extrairTag($conteudo, 'ACCTID');
        $datInicio = $this->converterDataOfx($this->extrairTag($conteudo, 'DTSTART'));
        $datFim = $this->converterDataOfx($this->extrairTag($conteudo, 'DTEND'));

        // Todo o fluxo (busca/criação do OFX, dedup e resumos) corre em UMA
        // transação. Importações concorrentes do mesmo contexto são serializadas
        // por um lock na associação (lockForUpdate), evitando que dois processos
        // observem a ausência do OFX e criem registros duplicados, ou que pares
        // exists()/create() de transações se intercalem.
        try {
            return DB::transaction(function () use (
                $idtAssociacao, $codBanco, $numConta, $datInicio, $datFim, $nomeOriginal, $conteudo
            ) {
                // Lock de serialização por associação (quando houver). A associação
                // sempre existe para imports com idt_associacao, então serve de
                // ponto de sincronização estável para a chave lógica do OFX.
                if ($idtAssociacao) {
                    Associacao::whereKey($idtAssociacao)->lockForUpdate()->first();
                }

                // Busca OFX existente pela chave lógica (associação + banco + conta).
                // O período (dat_inicio/dat_fim) é só metadado: reimportar um recorte
                // diferente do mesmo contexto deve reconciliar no MESMO registro Ofx.
                $ofx = Ofx::withoutGlobalScope('associacao')
                    ->where('idt_associacao', $idtAssociacao)
                    ->where('cod_banco', $codBanco)
                    ->where('num_conta', $numConta)
                    ->lockForUpdate()
                    ->first();

                if (! $ofx) {
                    $ofx = Ofx::create([
                        'idt_associacao' => $idtAssociacao,
                        'des_arquivo' => $nomeOriginal,
                        'cod_banco' => $codBanco,
                        'num_conta' => $numConta,
                        'dat_inicio' => $datInicio,
                        'dat_fim' => $datFim,
                        'qtd_transacao' => 0,
                        'val_total' => 0,
                    ]);
                } else {
                    // Atualiza o nome do arquivo para o mais recente
                    $ofx->update(['des_arquivo' => $nomeOriginal]);
                }

                $this->salvarTransacoes($ofx, $conteudo);

                // Recalcula os totais direto do banco (evita zerar se reimportar arquivo já existente)
                $qtd = Transacao::where('idt_ofx', $ofx->idt_ofx)->count();
                $val = Transacao::where('idt_ofx', $ofx->idt_ofx)->where('tip_transacao', 'CREDIT')->sum('val_transacao');

                $ofx->update([
                    'qtd_transacao' => $qtd,
                    'val_total' => (float) $val,
                ]);

                $this->gerarResumosMensais($ofx);

                return $ofx;
            });
        } catch (\Exception $e) {
            Log::error("Erro ao processar OFX [{$nomeOriginal}]: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Lê o arquivo e normaliza a codificação e quebras de linha.
     */
    private function lerArquivo(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);

        // Verifica a codificação declarada ou o charset do arquivo
        if (str_contains($conteudo, 'ENCODING:UTF-8') || mb_check_encoding($conteudo, 'UTF-8')) {
            // Já está em UTF-8
        } else {
            // Assume ISO-8859-1 para outros arquivos do Banco do Brasil
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }

        return str_replace(["\r\n", "\r"], "\n", $conteudo);
    }

    /**
     * Persiste as transações extraídas do conteúdo OFX e retorna os totais.
     *
     * @return array{quantidade: int, valor_total: float}
     */
    private function salvarTransacoes(Ofx $ofx, string $conteudo): array
    {
        $blocos = $this->extrairBlocosTransacao($conteudo);
        $valorTotal = 0.0;
        $quantidade = 0;

        // Carrega uma única vez as regras ATIVAS da associação (com critérios).
        $regras = $this->carregarRegrasAtivas($ofx->idt_associacao);

        // Set em memória para dedup intra-arquivo pela identidade canônica.
        $vistasNoArquivo = [];

        foreach ($blocos as $bloco) {
            $datTransacao = $this->converterDataOfx($bloco['DTPOSTED'] ?? null);

            if (! $datTransacao) {
                continue;
            }

            $fitid = trim($bloco['FITID'] ?? '');
            $name = trim($bloco['NAME'] ?? '');

            // Ignora registros informacionais de saldo e transações sem ID único
            if ($fitid === '' ||
                str_contains(strtolower($name), 'saldo anterior') ||
                str_contains(strtolower($name), 'saldo do dia') ||
                str_contains(strtolower($name), 'saldo anterior/atual')) {
                continue;
            }

            $valor = (float) str_replace(',', '.', $bloco['TRNAMT'] ?? '0');
            $tipo = $bloco['TRNTYPE'] ?? null;
            $checknum = $bloco['CHECKNUM'] ?? null;

            $cpf = $this->extrairCpfDoMemo($bloco['MEMO'] ?? null);

            // Identidade canônica da transação (dedup intra-arquivo e vs. banco).
            $identidade = $this->identidadeCanonica($checknum, $datTransacao, $valor, $tipo, $cpf);

            // Dedup intra-arquivo.
            if (isset($vistasNoArquivo[$identidade])) {
                continue;
            }
            $vistasNoArquivo[$identidade] = true;

            // Dedup contra o banco (escopo associação + banco + conta).
            if ($this->existeNoBanco($ofx, $checknum, $datTransacao, $valor, $tipo, $cpf)) {
                continue;
            }

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

            $desTransacao = $this->limparDescricao($bloco['MEMO'] ?? null);
            $nomPessoa = $bloco['NAME'] ?? null;

            // Aplica as regras de classificação ativas da associação.
            $flags = $this->aplicarRegras($regras, $nomPessoa, $desTransacao, $checknum, $tipo);

            Transacao::create([
                'idt_ofx' => $ofx->idt_ofx,
                'idt_membro' => $membro ? $membro->idt_membro : null,
                'num_transacao' => $fitid,
                'dat_transacao' => $datTransacao,
                'tip_transacao' => $tipo,
                'val_transacao' => $valor,
                'des_transacao' => $desTransacao,
                'num_check' => $checknum,
                'nom_pessoa' => $nomPessoa,
                'num_cpf_pagador' => $cpf,
                'ind_computa_receita' => $flags['ind_computa_receita'],
                'ind_computa_despesa' => $flags['ind_computa_despesa'],
            ]);

            $valorTotal += $valor;
            $quantidade++;
        }

        return ['quantidade' => $quantidade, 'valor_total' => $valorTotal];
    }

    /**
     * Normaliza um valor mantendo apenas os dígitos (ex.: CHECKNUM com separador
     * de milhar "50.552.236.870.722" -> "50552236870722").
     */
    private function normalizarDigitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor);
    }

    /**
     * Indica se o CHECKNUM é discriminante (identifica unicamente a transação).
     */
    private function checknumDiscriminante(?string $checknum): bool
    {
        $normalizado = $this->normalizarDigitos($checknum);

        return $normalizado !== '' && ! in_array($normalizado, self::CHECKNUMS_NAO_DISCRIMINANTES, true);
    }

    /**
     * Calcula a identidade canônica de uma transação:
     *  a) CHECKNUM normalizado, se existir e for discriminante;
     *  b) senão, chave de negócio data|valor|tipo|cpf (mínimo data+valor+tipo).
     */
    private function identidadeCanonica(?string $checknum, string $datTransacao, float $valor, ?string $tipo, ?string $cpf): string
    {
        if ($this->checknumDiscriminante($checknum)) {
            return 'chk:'.$this->normalizarDigitos($checknum);
        }

        return 'biz:'.implode('|', [
            $datTransacao,
            number_format($valor, 2, '.', ''),
            $tipo ?? '',
            $cpf ?? '',
        ]);
    }

    /**
     * Verifica se a transação já existe no banco, no escopo associação+banco+conta.
     * Para CHECKNUM discriminante, casa o CHECKNUM normalizado contra num_transacao
     * E num_check já gravados (ambos normalizados). Caso contrário, usa a chave de
     * negócio data+valor+tipo+cpf.
     */
    private function existeNoBanco(Ofx $ofx, ?string $checknum, string $datTransacao, float $valor, ?string $tipo, ?string $cpf): bool
    {
        $escopo = fn ($q) => $q->where('idt_associacao', $ofx->idt_associacao)
            ->where('cod_banco', $ofx->cod_banco)
            ->where('num_conta', $ofx->num_conta);

        $query = Transacao::withoutGlobalScope('associacao')
            ->whereHas('ofx', function ($q) use ($escopo) {
                $q->withoutGlobalScope('associacao');
                $escopo($q);
            });

        if ($this->checknumDiscriminante($checknum)) {
            $alvo = $this->normalizarDigitos($checknum);

            // Compara em PHP para aplicar a mesma normalização "somente dígitos"
            // do alvo contra num_transacao E num_check gravados. Usar REPLACE em
            // SQL só cobriria alguns separadores e divergiria do alvo; aqui
            // qualquer separador (espaço, parênteses etc.) é tratado igual.
            return $query
                ->select(['num_transacao', 'num_check'])
                ->cursor()
                ->contains(function ($t) use ($alvo) {
                    return $this->normalizarDigitos($t->num_transacao) === $alvo
                        || $this->normalizarDigitos($t->num_check) === $alvo;
                });
        }

        return $query
            ->whereDate('dat_transacao', $datTransacao)
            ->where('val_transacao', $valor)
            ->where('tip_transacao', $tipo)
            ->when(
                $cpf !== null,
                fn ($q) => $q->where('num_cpf_pagador', $cpf),
                fn ($q) => $q->whereNull('num_cpf_pagador'),
            )
            ->exists();
    }

    /**
     * Carrega as regras de classificação ativas da associação, com critérios.
     *
     * @return Collection<int, RegraClassificacao>
     */
    private function carregarRegrasAtivas(?int $idtAssociacao)
    {
        if (! $idtAssociacao) {
            return collect();
        }

        return RegraClassificacao::with('criterios')
            ->where('idt_associacao', $idtAssociacao)
            ->where('ind_ativo', true)
            ->get();
    }

    /**
     * Avalia as regras ativas e retorna os flags de computação da transação.
     * Uma regra casa só se TODOS os seus critérios casam (lógica E).
     *
     * @param  Collection<int, RegraClassificacao>  $regras
     * @return array{ind_computa_receita: bool, ind_computa_despesa: bool}
     */
    private function aplicarRegras($regras, ?string $nomPessoa, ?string $desTransacao, ?string $checknum, ?string $tipo): array
    {
        $flags = [
            'ind_computa_receita' => true,
            'ind_computa_despesa' => true,
        ];

        foreach ($regras as $regra) {
            $criterios = $regra->criterios;

            if ($criterios->isEmpty()) {
                continue;
            }

            $casaTodos = $criterios->every(
                fn ($criterio) => $this->criterioCasa($criterio, $nomPessoa, $desTransacao, $checknum, $tipo)
            );

            if (! $casaTodos) {
                continue;
            }

            $efeito = $regra->efeito;

            if ($efeito === EfeitoRegra::EXCLUI_RECEITA || $efeito === EfeitoRegra::EXCLUI_TUDO) {
                $flags['ind_computa_receita'] = false;
            }

            if ($efeito === EfeitoRegra::EXCLUI_DESPESA || $efeito === EfeitoRegra::EXCLUI_TUDO) {
                $flags['ind_computa_despesa'] = false;
            }
        }

        return $flags;
    }

    /**
     * Avalia um único critério contra os dados da transação.
     */
    private function criterioCasa($criterio, ?string $nomPessoa, ?string $desTransacao, ?string $checknum, ?string $tipo): bool
    {
        $valMatch = (string) $criterio->val_match;

        return match ($criterio->tip_match) {
            'NOM_PESSOA' => $nomPessoa !== null
                && str_contains(mb_strtolower($nomPessoa), mb_strtolower($valMatch)),
            'DES_TRANSACAO' => $desTransacao !== null
                && str_contains(mb_strtolower($desTransacao), mb_strtolower($valMatch)),
            'CHECKNUM' => $this->checknumCriterioCasa($valMatch, $checknum),
            'TIPO' => $tipo === $valMatch,
            default => false,
        };
    }

    /**
     * Compara um critério CHECKNUM contra o CHECKNUM da transação por igualdade
     * normalizada ("somente dígitos"). Um alvo sem dígitos (ex.: "ABC") normaliza
     * para vazio e NÃO deve casar transações sem CHECKNUM: nesse caso recusa.
     */
    private function checknumCriterioCasa(string $valMatch, ?string $checknum): bool
    {
        $alvo = $this->normalizarDigitos($valMatch);

        if ($alvo === '') {
            return false;
        }

        return $this->normalizarDigitos($checknum) === $alvo;
    }

    /**
     * Gera ou atualiza os resumos mensais agrupados por pessoa.
     */
    private function gerarResumosMensais(Ofx $ofx): void
    {
        $associacao = Associacao::find($ofx->idt_associacao);
        $taxaVigentePadrao = $associacao ? $associacao->getTaxaVigenteEm() : null;
        $valTaxaPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_taxa : 0.0;
        $valAnualPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_anual : 0.0;

        // Recalcula os resumos avulsos (sem membro) do zero: eles são agrupados
        // por descrição e não têm discriminante na chave única do resumo, então
        // apagá-los antes de recriar evita duplicação no reimport.
        Resumo::withoutGlobalScope('associacao')
            ->where('idt_ofx', $ofx->idt_ofx)
            ->whereNull('idt_membro')
            ->delete();

        $transacoes = $ofx->transacoes()
            ->where('val_transacao', '>', 0) // apenas créditos/recebimentos
            ->where('ind_computa_receita', true) // ignora o que foi excluído da receita
            ->get();

        $porPessoa = $transacoes->groupBy(function ($t) {
            return $t->idt_membro ? 'membro_'.$t->idt_membro : 'nome_'.$t->des_transacao;
        });

        foreach ($porPessoa as $chave => $transacoesPessoa) {
            $primeiraTransacao = $transacoesPessoa->first();
            $idtMembro = $primeiraTransacao->idt_membro;

            $porMes = $transacoesPessoa->groupBy(
                fn ($t) => $t->dat_transacao->format('Y').'-'.$t->dat_transacao->format('n')
            );

            foreach ($porMes as $anoMes => $itens) {
                [$ano, $mes] = explode('-', $anoMes);

                $dataRef = sprintf('%04d-%02d-01', (int) $ano, (int) $mes);
                $taxaVigente = $associacao ? $associacao->getTaxaVigenteEm($dataRef) : null;

                $valTaxa = $taxaVigente ? (float) $taxaVigente->val_taxa : $valTaxaPadrao;
                $valAnual = $taxaVigente ? (float) $taxaVigente->val_anual : $valAnualPadrao;

                $total = $itens->sum('val_transacao');

                $indPago = false;
                if ($total > 0) {
                    if (($valTaxa > 0 && $total >= $valTaxa) || ($valAnual > 0 && $total >= $valAnual)) {
                        $indPago = true;
                    } elseif ($valTaxa <= 0 && $valAnual <= 0) {
                        $indPago = true;
                    }
                }

                if ($idtMembro) {
                    $resumo = Resumo::updateOrCreate(
                        [
                            'idt_ofx' => $ofx->idt_ofx,
                            'idt_membro' => $idtMembro,
                            'num_ano' => (int) $ano,
                            'num_mes' => (int) $mes,
                        ],
                        [
                            'nom_mes' => self::NOMES_MESES[(int) $mes] ?? "Mês {$mes}",
                            'val_total' => $total,
                            'qtd_transacao' => $itens->count(),
                            'ind_pago' => $indPago,
                        ]
                    );
                } else {
                    // Avulsos (sem membro): como o agrupamento é por descrição e
                    // não há coluna para discriminá-los na chave única do resumo,
                    // os resumos avulsos deste OFX são recalculados do zero no
                    // início deste método (ver limparResumosAvulsos), de modo que
                    // o create aqui não dobra valores no reimport.
                    $resumo = Resumo::create([
                        'idt_ofx' => $ofx->idt_ofx,
                        'idt_membro' => null,
                        'num_ano' => (int) $ano,
                        'num_mes' => (int) $mes,
                        'nom_mes' => self::NOMES_MESES[(int) $mes] ?? "Mês {$mes}",
                        'val_total' => $total,
                        'qtd_transacao' => $itens->count(),
                        'ind_pago' => $indPago,
                    ]);
                }

                foreach ($itens as $item) {
                    $item->update(['idt_resumo' => $resumo->idt_resumo]);
                }
            }
        }
    }

    /**
     * Extrai todos os blocos <STMTTRN> do conteúdo OFX.
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

        // Remove fuso horário (ex: [-3:BRT])
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
     * Limpa o campo MEMO removendo ruídos comuns do Banco do Brasil.
     */
    private function limparDescricao(?string $descricao): ?string
    {
        if (empty($descricao)) {
            return null;
        }

        $descricao = preg_replace('/\s+/', ' ', trim($descricao));

        // Limpa data e hora iniciais do MEMO (ex: "18/03 18:53 " ou "18/03 ")
        $descricao = preg_replace('/^\d{2}\/\d{2}(?:\s+\d{2}:\d{2})?\s+/', '', $descricao);

        // Limpa CPF/CNPJ/documentos no início do MEMO (ex: "00005789252141 " ou "33.683.111/0001-07 ")
        $descricao = preg_replace('/^(?:\d|[\.\-\/]){11,18}\s+/', '', $descricao);

        // Códigos de agência/conta (ex: "AG 1234 CC 56789-0")
        $descricao = preg_replace('/\bAG\s*\d+\s*CC\s*[\d\-]+\b/i', '', $descricao);

        // Prefixos de operação PIX/TED/DOC
        $descricao = preg_replace('/\bPIX\s*-\s*ENVIADO\b/i', '', $descricao);
        $descricao = preg_replace('/\bPIX\s*RECEBIDO\b/i', '', $descricao);
        $descricao = preg_replace('/\bTED\s*-\s*\d+\b/i', '', $descricao);
        $descricao = preg_replace('/\bDOC\s*-\s*\d+\b/i', '', $descricao);

        return trim($descricao) ?: 'Não identificado';
    }

    /**
     * Extrai o CPF/CNPJ (apenas dígitos) do campo MEMO.
     */
    public function extrairCpfDoMemo(?string $memo): ?string
    {
        if (empty($memo)) {
            return null;
        }

        $memo = preg_replace('/\s+/', ' ', trim($memo));

        // Limpa data e hora iniciais do MEMO (ex: "18/03 18:53 " ou "18/03 ")
        $memoSemData = preg_replace('/^\d{2}\/\d{2}(?:\s+\d{2}:\d{2})?\s+/', '', $memo);

        // O CPF/CNPJ deve ser o próximo bloco de caracteres (11 a 18 chars com pontuação)
        if (preg_match('/^((?:\d|[\.\-\/]){11,18})/', $memoSemData, $matches)) {
            $digits = preg_replace('/\D/', '', $matches[1]);

            // Se o CPF vier com zeros adicionais à esquerda (14 dígitos começando com 000)
            if (strlen($digits) === 14 && str_starts_with($digits, '000')) {
                $digits = substr($digits, 3);
            }

            return $digits;
        }

        return null;
    }
}
