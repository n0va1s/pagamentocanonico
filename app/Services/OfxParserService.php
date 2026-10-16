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
     * Processa o arquivo OFX e persiste os dados no banco, de forma idempotente.
     *
     * Integra as duas camadas de proteção contra duplicidade:
     *  - hash_arquivo (auditoria): curto-circuito quando o MESMO arquivo já foi
     *    importado na associação (Req 2.1), antes de qualquer lock/dedup;
     *  - identidade canônica por CHECKNUM (melhorias): dedup transação a
     *    transação, mesmo quando o mesmo lançamento vem em arquivos/recortes
     *    diferentes com FITID sintético divergente.
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

        // (b) Curto-circuito por hash idêntico na associação (camada 1 — Req 2.1),
        // ANTES de lock/dedup: se o MESMO arquivo já foi importado, nada muda.
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

        // (c) Busca/criação do OFX + dedup + resumos, tudo em UMA transação,
        // serializada por lock de contexto (associação + banco + conta).
        try {
            [$ofx, $status, $contadores] = $this->comLockDeContexto($idtAssociacao, $codBanco, $numConta, function () use (
                $idtAssociacao, $codBanco, $numConta, $datInicio, $datFim, $nomeOriginal, $hash, $conteudo
            ) {
                // Lock de serialização por associação (quando houver).
                if ($idtAssociacao) {
                    Associacao::whereKey($idtAssociacao)->lockForUpdate()->first();
                }

                // Chave lógica do OFX SEM período (dat_inicio/dat_fim são apenas
                // metadado): reimportar um recorte diferente do mesmo contexto
                // reconcilia no MESMO registro Ofx.
                $ofx = Ofx::withoutGlobalScope('associacao')
                    ->where('idt_associacao', $idtAssociacao)
                    ->whereRaw("COALESCE(cod_banco, '') = ?", [(string) ($codBanco ?? '')])
                    ->whereRaw("COALESCE(num_conta, '') = ?", [(string) ($numConta ?? '')])
                    ->lockForUpdate()
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
                    // Atualiza nome e hash do arquivo mais recente; preserva a
                    // chave lógica (banco/conta) e o período original como metadado.
                    $ofx->update([
                        'des_arquivo' => $nomeOriginal,
                        'hash_arquivo' => $hash,
                    ]);
                }

                $contadores = $this->salvarTransacoes($ofx, $conteudo);

                // Recalcula os totais direto do banco (reconciliação sem acúmulo).
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

                return [$ofx, $status, $contadores];
            });
        } catch (\Throwable $e) {
            Log::error("Erro ao processar OFX [{$nomeOriginal}]: ".$e->getMessage());
            throw $e;
        }

        // (d) resultadoImportacao com status + contadores (lido pela tela de upload).
        $ofx->resultadoImportacao = [
            'status' => $status,
            'novas' => $contadores['novas'],
            'jaExistentes' => $contadores['jaExistentes'],
            'fitidInvalidos' => $contadores['fitidInvalidos'],
        ];

        return $ofx;
    }

    /**
     * Executa o fluxo de importação dentro de UMA transação, serializando o
     * contexto (associação + banco + conta).
     *
     * Quando há associação, a própria linha de `associacoes` serve de ponto de
     * sincronização estável (lockForUpdate dentro da transação). Quando NÃO há
     * associação (idt_associacao nulo), não existe linha para travar e o OFX
     * ainda inexistente não pode ser bloqueado com lockForUpdate; então, em
     * bancos que suportam lock nomeado de sessão (MySQL `GET_LOCK`), adquire-se
     * um lock advisory na chave lógica `NULL|banco|conta` ANTES de abrir a
     * transação. Em SQLite (testes) a escrita já é serializada pelo próprio
     * banco, então o lock advisory é dispensado sem alterar o comportamento.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function comLockDeContexto(?int $idtAssociacao, ?string $codBanco, ?string $numConta, callable $callback)
    {
        // Com associação, o lockForUpdate na transação já serializa o contexto.
        if ($idtAssociacao) {
            return DB::transaction($callback);
        }

        $nomeLock = $this->nomeLockContexto($idtAssociacao, $codBanco, $numConta);

        if (! $this->suportaLockNomeado()) {
            return DB::transaction($callback);
        }

        if (! $this->adquirirLockNomeado($nomeLock)) {
            // Fail-closed: sem o lock confirmado, não abrir a transação para
            // não permitir importação concorrente sem serialização (timeout=0
            // ou erro=NULL no GET_LOCK). O chamador pode retentar a importação.
            throw new \RuntimeException(
                'Não foi possível obter o lock de importação do contexto OFX; '.
                'outra importação pode estar em andamento. Tente novamente.'
            );
        }

        try {
            return DB::transaction($callback);
        } finally {
            $this->liberarLockNomeado($nomeLock);
        }
    }

    /**
     * Nome determinístico do lock advisory para a chave lógica do OFX. MySQL
     * limita o nome a 64 caracteres, então usa-se um hash estável da chave.
     */
    private function nomeLockContexto(?int $idtAssociacao, ?string $codBanco, ?string $numConta): string
    {
        $chave = ($idtAssociacao ?? 'NULL').'|'.($codBanco ?? '').'|'.($numConta ?? '');

        return 'ofx_import_'.sha1($chave);
    }

    /**
     * Indica se a conexão suporta lock nomeado de sessão (apenas MySQL/MariaDB).
     */
    private function suportaLockNomeado(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /**
     * Adquire um lock nomeado de sessão (MySQL `GET_LOCK`).
     *
     * Retorna true somente quando o lock foi efetivamente adquirido (GET_LOCK
     * devolve 1). Em timeout (0) ou erro (NULL) retorna false, para que o
     * chamador não prossiga sem exclusão mútua.
     */
    private function adquirirLockNomeado(string $nome): bool
    {
        $resultado = DB::selectOne('SELECT GET_LOCK(?, ?) AS obtido', [$nome, 10]);

        return $resultado !== null && (int) $resultado->obtido === 1;
    }

    /**
     * Libera o lock nomeado de sessão (MySQL `RELEASE_LOCK`).
     */
    private function liberarLockNomeado(string $nome): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS liberado', [$nome]);
    }

    /**
     * Lê o arquivo e normaliza a codificação e quebras de linha.
     */
    private function lerArquivo(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);

        if (str_contains($conteudo, 'ENCODING:UTF-8') || mb_check_encoding($conteudo, 'UTF-8')) {
            // Já está em UTF-8
        } else {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }

        return str_replace(["\r\n", "\r"], "\n", $conteudo);
    }

    /**
     * Persiste as transações extraídas do conteúdo OFX com dedup por identidade
     * canônica (CHECKNUM normalizado ou chave de negócio) e aplicação das regras
     * de classificação da associação. Retorna os contadores da importação.
     *
     * @return array{novas:int, jaExistentes:int, fitidInvalidos:int}
     */
    private function salvarTransacoes(Ofx $ofx, string $conteudo): array
    {
        $blocos = $this->extrairBlocosTransacao($conteudo);

        $novas = 0;
        $jaExistentes = 0;
        $fitidInvalidos = 0;

        if (($ofx->num_conta ?? '') === '' || ($ofx->cod_banco ?? '') === '') {
            Log::warning('OFX sem Conta_Origem completa na associacao '.($ofx->idt_associacao ?? 'null')." - dedup escopada por banco/conta vazios (idt_ofx={$ofx->idt_ofx}).");
        }

        // Carrega uma única vez as regras ATIVAS da associação (com critérios).
        $regras = $this->carregarRegrasAtivas($ofx->idt_associacao);

        // Índice canônico das transações JÁ gravadas no contexto (associação +
        // banco + conta), lido UMA vez. Reúne tanto as identidades discriminantes
        // por CHECKNUM ("chk:<dígitos>", de num_transacao E num_check) quanto as
        // de negócio ("biz:data|valor|tipo|cpf").
        $identidadesExistentes = $this->carregarIndiceIdentidades($ofx);

        // Set em memória para dedup intra-arquivo pela identidade canônica.
        $vistasNoArquivo = [];

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

            // FITID ausente/vazio/nulo: lançamento sem identificador não entra.
            if ($fitid === '') {
                $fitidInvalidos++;

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
                $jaExistentes++;

                continue;
            }
            $vistasNoArquivo[$identidade] = true;

            // Dedup contra o banco (escopo associação + banco + conta) via índice
            // canônico em memória, montado uma única vez e atualizado a cada criação.
            if (isset($identidadesExistentes[$identidade])) {
                $jaExistentes++;

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

            // Atualiza o índice canônico com as identidades da transação recém
            // gravada (chk: de FITID/CHECKNUM discriminantes e biz: incondicional).
            foreach ($this->identidadesDeTransacao($fitid, $checknum, $datTransacao, $valor, $tipo, $cpf) as $id) {
                $identidadesExistentes[$id] = true;
            }

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

        return $this->identidadeNegocio($datTransacao, $valor, $tipo, $cpf);
    }

    /**
     * Chave de negócio canônica (fallback de dedup): data|valor|tipo|cpf.
     */
    private function identidadeNegocio(string $datTransacao, float $valor, ?string $tipo, ?string $cpf): string
    {
        return 'biz:'.implode('|', [
            $datTransacao,
            number_format($valor, 2, '.', ''),
            $tipo ?? '',
            $cpf ?? '',
        ]);
    }

    /**
     * Monta, com UMA leitura, o índice canônico das transações já gravadas no
     * contexto (associação + banco + conta), de modo que a dedup contra o banco
     * vira um lookup O(1) em memória.
     *
     * @return array<string, true>
     */
    private function carregarIndiceIdentidades(Ofx $ofx): array
    {
        $query = Transacao::withoutGlobalScope('associacao')
            ->whereHas('ofx', function ($q) use ($ofx) {
                $q->withoutGlobalScope('associacao')
                    ->where('idt_associacao', $ofx->idt_associacao)
                    ->whereRaw("COALESCE(cod_banco, '') = ?", [(string) ($ofx->cod_banco ?? '')])
                    ->whereRaw("COALESCE(num_conta, '') = ?", [(string) ($ofx->num_conta ?? '')]);
            })
            ->select(['num_transacao', 'num_check', 'dat_transacao', 'val_transacao', 'tip_transacao', 'num_cpf_pagador']);

        $indice = [];

        foreach ($query->cursor() as $t) {
            $data = $t->dat_transacao instanceof \DateTimeInterface
                ? $t->dat_transacao->format('Y-m-d')
                : (string) $t->dat_transacao;

            foreach ($this->identidadesDeTransacao(
                $t->num_transacao,
                $t->num_check,
                $data,
                (float) $t->val_transacao,
                $t->tip_transacao,
                $t->num_cpf_pagador,
            ) as $id) {
                $indice[$id] = true;
            }
        }

        return $indice;
    }

    /**
     * Deriva TODAS as identidades canônicas sob as quais uma transação
     * persistida pode ser reconhecida numa importação futura:
     *  - "chk:<dígitos>" para cada coluna discriminante (num_transacao=FITID e
     *    num_check=CHECKNUM); e
     *  - sempre a identidade de negócio "biz:data|valor|tipo|cpf".
     *
     * @return array<int, string>
     */
    private function identidadesDeTransacao(?string $numTransacao, ?string $numCheck, string $datTransacao, float $valor, ?string $tipo, ?string $cpf): array
    {
        $identidades = [
            $this->identidadeNegocio($datTransacao, $valor, $tipo, $cpf) => true,
        ];

        foreach ([$numTransacao, $numCheck] as $candidato) {
            if ($this->checknumDiscriminante($candidato)) {
                $identidades['chk:'.$this->normalizarDigitos($candidato)] = true;
            }
        }

        return array_keys($identidades);
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
     * Gera ou atualiza os resumos mensais agrupados por pessoa, de forma
     * idempotente e sem acúmulo (Req 5). Executa dentro da transação de processar().
     *
     * Base: estrutura da auditoria (agrupa sem-membro por chavePessoaSemMembro,
     * grava chv_pessoa/nom_pessoa, mescla colisões e consolida órfãos). Filtro
     * das melhorias: considera apenas CREDIT com ind_computa_receita=true, de
     * modo que lançamentos excluídos da receita (ex.: Rende Fácil) não entram.
     */
    private function gerarResumosMensais(Ofx $ofx): void
    {
        $associacao = Associacao::find($ofx->idt_associacao);
        $taxaVigentePadrao = $associacao ? $associacao->getTaxaVigenteEm() : null;
        $valTaxaPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_taxa : 0.0;
        $valAnualPadrao = $taxaVigentePadrao ? (float) $taxaVigentePadrao->val_anual : 0.0;

        // Crédito canônico = tip_transacao CREDIT (alinhado ao dashboard e ao
        // val_total) e computável na receita (ignora o que foi excluído por regra).
        $transacoes = $ofx->transacoes()
            ->where('tip_transacao', 'CREDIT')
            ->where('ind_computa_receita', true)
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

                // Validação de período (Req 5.4).
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
                    // Mesclagem em colisão da UNIQUE (idt_ofx, idt_membro, num_ano, num_mes).
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

        // Consolidação de órfãos sem-membro (migração parcial via atualizarPagamentos).
        $this->consolidarOrfaosSemMembro($ofx);
    }

    /**
     * Resolve o resumo com membro para o período, mesclando eventuais colisões da
     * UNIQUE (idt_ofx, idt_membro, num_ano, num_mes): mantém o de menor idt_resumo,
     * reatribui as transações e remove os excedentes, recalculando sem acúmulo.
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

        // Mescla: mantém o menor id, reatribui transações dos excedentes e remove-os.
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
     * Remove resumos sem-membro que ficaram órfãos após a migração de idt_membro,
     * respeitando as salvaguardas: nunca remove chave não-discriminante
     * ('nao_identificado'/'Não identificado'); só remove quando todas as transações
     * do grupo migraram para um único membro cujo resumo cobre a quantidade.
     */
    private function consolidarOrfaosSemMembro(Ofx $ofx): void
    {
        $orfaos = Resumo::withoutGlobalScopes()
            ->where('idt_ofx', $ofx->idt_ofx)
            ->whereNull('idt_membro')
            ->get();

        foreach ($orfaos as $orfao) {
            // Salvaguarda 1: nunca remover chave não-discriminante.
            if ($orfao->chv_pessoa === 'nao_identificado' || $orfao->nom_pessoa === 'Não identificado') {
                continue;
            }

            // Transações ainda apontando para o resumo órfão.
            $transacoesOrfao = Transacao::withoutGlobalScopes()
                ->where('idt_resumo', $orfao->idt_resumo)
                ->get();

            // Se ainda há transações sem membro, o órfão representa elas: preservar.
            $aindaSemMembro = $transacoesOrfao->whereNull('idt_membro')->count();
            if ($aindaSemMembro > 0) {
                continue;
            }

            // Membros para os quais as transações migraram.
            $membrosDestino = $transacoesOrfao->pluck('idt_membro')->filter()->unique()->values();

            // Salvaguarda 2: só remover se migrou integralmente para um único membro.
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

            // Reatribui as transações remanescentes ao resumo com membro e remove o órfão.
            Transacao::withoutGlobalScopes()
                ->where('idt_resumo', $orfao->idt_resumo)
                ->update(['idt_resumo' => $resumoComMembro->idt_resumo]);

            $orfao->delete();
        }
    }

    /**
     * Chave de idempotência estável para uma pessoa sem membro vinculado.
     * Precedência: cpf -> descrição útil -> name cru -> literal nao_identificado.
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
     * Extrai o CPF/CNPJ (apenas dígitos) do campo MEMO.
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
