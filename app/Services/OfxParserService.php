<?php

namespace App\Services;

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
     * Processa o arquivo OFX e persiste os dados no banco.
     */
    public function processar(string $caminhoArquivo, string $nomeOriginal, ?int $idtAssociacao = null): Ofx
    {
        $conteudo = $this->lerArquivo($caminhoArquivo);

        $codBanco = $this->extrairTag($conteudo, 'BANKID');
        $numConta = $this->extrairTag($conteudo, 'ACCTID');
        $datInicio = $this->converterDataOfx($this->extrairTag($conteudo, 'DTSTART'));
        $datFim = $this->converterDataOfx($this->extrairTag($conteudo, 'DTEND'));

        // Busca OFX existente pela chave lógica (conta e período)
        $ofx = Ofx::where('idt_associacao', $idtAssociacao)
            ->where('num_conta', $numConta)
            ->where('dat_inicio', $datInicio)
            ->where('dat_fim', $datFim)
            ->first();

        if (!$ofx) {
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

        DB::beginTransaction();

        try {
            $this->salvarTransacoes($ofx, $conteudo);

            // Recalcula os totais direto do banco (evita zerar se reimportar arquivo já existente)
            $qtd = Transacao::where('idt_ofx', $ofx->idt_ofx)->count();
            $val = Transacao::where('idt_ofx', $ofx->idt_ofx)->where('tip_transacao', 'CREDIT')->sum('val_transacao');

            $ofx->update([
                'qtd_transacao' => $qtd,
                'val_total' => (float) $val,
            ]);

            $this->gerarResumosMensais($ofx);

            DB::commit();

            return $ofx;

        } catch (\Exception $e) {
            DB::rollBack();
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

            if (Transacao::where('num_transacao', $fitid)->exists()) {
                continue;
            }

            $cpf = $this->extrairCpfDoMemo($bloco['MEMO'] ?? null);
            $membro = null;
            if ($cpf) {
                $membro = \App\Models\Membro::withoutGlobalScopes()
                    ->when($ofx->idt_associacao, fn($q) => $q->where('idt_associacao', $ofx->idt_associacao))
                    ->where(function($query) use ($cpf) {
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

            $valorTotal += $valor;
            $quantidade++;
        }

        return ['quantidade' => $quantidade, 'valor_total' => $valorTotal];
    }

    /**
     * Gera ou atualiza os resumos mensais agrupados por pessoa.
     */
    private function gerarResumosMensais(Ofx $ofx): void
    {
        $associacao = \App\Models\Associacao::find($ofx->idt_associacao);
        $taxaVigentePadrao = $associacao ? $associacao->getTaxaVigenteEm() : null;
        $valTaxaPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_taxa : 0.0;
        $valAnualPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_anual : 0.0;

        $transacoes = $ofx->transacoes()
            ->where('val_transacao', '>', 0) // apenas créditos/recebimentos
            ->get();

        $porPessoa = $transacoes->groupBy(function ($t) {
            return $t->idt_membro ? 'membro_' . $t->idt_membro : 'nome_' . $t->des_transacao;
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
