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

        $ofx = Ofx::create([
            'idt_associacao' => $idtAssociacao,
            'des_arquivo' => $nomeOriginal,
            'cod_banco' => $this->extrairTag($conteudo, 'BANKID'),
            'num_conta' => $this->extrairTag($conteudo, 'ACCTID'),
            'dat_inicio' => $this->converterDataOfx($this->extrairTag($conteudo, 'DTSTART')),
            'dat_fim' => $this->converterDataOfx($this->extrairTag($conteudo, 'DTEND')),
            'qtd_transacao' => 0,
            'val_total' => 0,
        ]);

        DB::beginTransaction();

        try {
            $totais = $this->salvarTransacoes($ofx, $conteudo);

            $ofx->update([
                'qtd_transacao' => $totais['quantidade'],
                'val_total' => $totais['valor_total'],
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

            Transacao::create([
                'idt_ofx' => $ofx->idt_ofx,
                'num_transacao' => $fitid,
                'dat_transacao' => $datTransacao,
                'tip_transacao' => $bloco['TRNTYPE'] ?? null,
                'val_transacao' => $valor,
                'des_transacao' => $this->limparDescricao($bloco['MEMO'] ?? null),
                'num_check' => $bloco['CHECKNUM'] ?? null,
                'nom_pessoa' => $bloco['NAME'] ?? null,
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
        $transacoes = $ofx->transacoes()
            ->where('val_transacao', '>', 0) // apenas créditos/recebimentos
            ->get();

        $porPessoa = $transacoes->groupBy('des_transacao');

        foreach ($porPessoa as $nomePessoa => $transacoesPessoa) {
            $porMes = $transacoesPessoa->groupBy(
                fn ($t) => $t->dat_transacao->format('Y').'-'.$t->dat_transacao->format('n')
            );

            foreach ($porMes as $anoMes => $itens) {
                [$ano, $mes] = explode('-', $anoMes);

                $total = $itens->sum('val_transacao');

                Resumo::updateOrCreate(
                    [
                        'idt_ofx' => $ofx->idt_ofx,
                        'nom_pessoa' => $nomePessoa,
                        'num_ano' => (int) $ano,
                        'num_mes' => (int) $mes,
                    ],
                    [
                        'nom_mes' => self::NOMES_MESES[(int) $mes] ?? "Mês {$mes}",
                        'val_total' => $total,
                        'num_transacao' => $itens->count(),
                        'ind_pago' => $total > 0,
                    ]
                );
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
}
