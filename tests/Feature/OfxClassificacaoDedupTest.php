<?php

use App\Enums\EfeitoRegra;
use App\Models\Associacao;
use App\Models\Ofx;
use App\Models\RegraClassificacao;
use App\Models\Resumo;
use App\Models\Transacao;
use App\Services\OfxParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Monta um arquivo OFX mínimo com os blocos de transação informados e grava num
 * temporário para processar. Cada bloco é um array parcial de tags STMTTRN.
 *
 * @param  array<int, array<string, string>>  $blocos
 */
function escreverOfxTemporario(array $blocos, string $bankId = '77', string $acctId = '99999-9'): string
{
    $linhasBlocos = '';
    foreach ($blocos as $i => $b) {
        $tipo = $b['TRNTYPE'] ?? 'CREDIT';
        $data = $b['DTPOSTED'] ?? '20260901000000[-3:BRT]';
        $valor = $b['TRNAMT'] ?? '10.00';
        $fitid = $b['FITID'] ?? ('FIT'.$i);
        $checknum = array_key_exists('CHECKNUM', $b) ? "<CHECKNUM>{$b['CHECKNUM']}" : '';
        $name = $b['NAME'] ?? 'Pix - Recebido';
        $memo = $b['MEMO'] ?? '01/09 17:29 Teste';

        $linhasBlocos .= <<<BLOCO
            <STMTTRN>
              <TRNTYPE>{$tipo}
              <DTPOSTED>{$data}
              <TRNAMT>{$valor}
              <FITID>{$fitid}
              {$checknum}
              <NAME>{$name}
              <MEMO>{$memo}
            </STMTTRN>

        BLOCO;
    }

    $conteudo = <<<OFX
    OFXHEADER:100
    DATA:OFXSGML
    VERSION:102
    ENCODING:UTF-8
    <OFX>
      <BANKMSGSRSV1>
        <STMTTRNRS>
          <STMTRS>
            <BANKACCTFROM>
              <BANKID>{$bankId}
              <ACCTID>{$acctId}
            </BANKACCTFROM>
            <BANKTRANLIST>
              <DTSTART>20260825000000[-3:BRT]
              <DTEND>20260930000000[-3:BRT]
    {$linhasBlocos}
            </BANKTRANLIST>
          </STMTRS>
        </STMTTRNRS>
      </BANKMSGSRSV1>
    </OFX>
    OFX;

    $caminho = tempnam(sys_get_temp_dir(), 'ofx_test_').'.ofx';
    file_put_contents($caminho, $conteudo);

    return $caminho;
}

/**
 * Cria uma regra ativa com um único critério para a associação informada.
 */
function criarRegra(Associacao $assoc, EfeitoRegra $efeito, string $tipMatch, string $valMatch, bool $ativo = true): RegraClassificacao
{
    $regra = RegraClassificacao::create([
        'idt_associacao' => $assoc->idt_associacao,
        'nom_regra' => "{$tipMatch}:{$valMatch}",
        'efeito' => $efeito,
        'ind_ativo' => $ativo,
    ]);

    $regra->criterios()->create(['tip_match' => $tipMatch, 'val_match' => $valMatch]);

    return $regra;
}

/**
 * Cria uma associação e a regra Rende Fácil (CHECKNUM 9903 -> EXCLUI_TUDO).
 */
function criarContextoClassificacao(): Associacao
{
    $associacao = Associacao::create([
        'nom_associacao' => 'Associação Teste',
        'tel_contato' => '11999990000',
        'des_chave_pix' => 'pix@teste.com',
        'dat_inicio_cobranca' => '2026-01-01',
    ]);

    $regra = RegraClassificacao::create([
        'idt_associacao' => $associacao->idt_associacao,
        'nom_regra' => 'Rende Fácil',
        'efeito' => EfeitoRegra::EXCLUI_TUDO,
        'ind_ativo' => true,
    ]);

    $regra->criterios()->create([
        'tip_match' => 'CHECKNUM',
        'val_match' => '9903',
    ]);

    return $associacao;
}

test('reimportar o mesmo arquivo OFX é idempotente', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;
    $arquivo = base_path('scripts/202609.ofx');

    $ofx1 = $service->processar($arquivo, '202609.ofx', $associacao->idt_associacao);

    $ofxCount1 = Ofx::count();
    $transacaoCount1 = Transacao::count();
    $resumoCount1 = Resumo::count();

    $ofx2 = $service->processar($arquivo, '202609.ofx', $associacao->idt_associacao);

    // Mesmo registro Ofx (chave lógica associação+banco+conta, sem período).
    expect($ofx2->idt_ofx)->toBe($ofx1->idt_ofx);

    // Contagens estáveis: 0 transações novas, 0 Ofx novos, sem resumos duplicados.
    expect(Ofx::count())->toBe($ofxCount1);
    expect(Transacao::count())->toBe($transacaoCount1);
    expect(Resumo::count())->toBe($resumoCount1);
});

test('recorte sobreposto reconcilia no mesmo Ofx sem duplicar transações', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    $ofxSet = $service->processar(base_path('scripts/202609.ofx'), '202609.ofx', $associacao->idt_associacao);
    $transacaoCountSet = Transacao::count();

    // 202608 cobre 29/07..31/08 (recorte sobreposto); mesmo contexto (banco+conta).
    $ofxAgo = $service->processar(base_path('scripts/202608.ofx'), '202608.ofx', $associacao->idt_associacao);

    // Deve reconciliar no MESMO registro Ofx.
    expect($ofxAgo->idt_ofx)->toBe($ofxSet->idt_ofx);
    expect(Ofx::count())->toBe(1);

    // João Paulo (CPF 85236250110) em setembro/2026 soma 40,00 — não dobra.
    $somaJoaoPauloSet = Transacao::withoutGlobalScope('associacao')
        ->where('num_cpf_pagador', '85236250110')
        ->whereYear('dat_transacao', 2026)
        ->whereMonth('dat_transacao', 9)
        ->sum('val_transacao');

    expect((float) $somaJoaoPauloSet)->toBe(40.00);

    // Reimportar setembro novamente não cria transações novas.
    $antes = Transacao::count();
    $service->processar(base_path('scripts/202609.ofx'), '202609.ofx', $associacao->idt_associacao);
    expect(Transacao::count())->toBe($antes);
});

test('transações Rende Fácil ficam fora da computação mas continuam gravadas', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    $service->processar(base_path('scripts/202609.ofx'), '202609.ofx', $associacao->idt_associacao);

    $rendeFacil = Transacao::withoutGlobalScope('associacao')
        ->where('num_check', '9903')
        ->get();

    // Rende Fácil continua gravado (nada ignorado na importação).
    expect($rendeFacil->count())->toBeGreaterThan(0);

    // Todas marcadas como não computáveis em receita e despesa.
    expect($rendeFacil->every(fn ($t) => $t->ind_computa_receita === false))->toBeTrue();
    expect($rendeFacil->every(fn ($t) => $t->ind_computa_despesa === false))->toBeTrue();
});

test('resumos de receita não incluem Rende Fácil', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    $ofx = $service->processar(base_path('scripts/202609.ofx'), '202609.ofx', $associacao->idt_associacao);

    // Nenhuma transação não computável de receita deve estar vinculada a um resumo.
    $naoComputaveisComResumo = Transacao::withoutGlobalScope('associacao')
        ->where('idt_ofx', $ofx->idt_ofx)
        ->where('ind_computa_receita', false)
        ->whereNotNull('idt_resumo')
        ->count();

    expect($naoComputaveisComResumo)->toBe(0);

    // O crédito Rende Fácil (240,00) não deve inflar nenhum resumo de receita.
    $somaResumos = Resumo::withoutGlobalScope('associacao')
        ->where('idt_ofx', $ofx->idt_ofx)
        ->sum('val_total');

    $somaReceitaComputavel = Transacao::withoutGlobalScope('associacao')
        ->where('idt_ofx', $ofx->idt_ofx)
        ->where('val_transacao', '>', 0)
        ->where('ind_computa_receita', true)
        ->sum('val_transacao');

    expect((float) $somaResumos)->toBe((float) $somaReceitaComputavel);
});

// -----------------------------------------------------------------------------
// Finding 1 — normalização "somente dígitos" na dedup contra o banco
// -----------------------------------------------------------------------------

test('dedup por CHECKNUM casa separadores variados em num_transacao e num_check', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    // Pré-grava uma transação com CHECKNUM com separadores diferentes nas duas
    // colunas: espaço no num_transacao e parênteses/pontos no num_check.
    $ofx = Ofx::create([
        'idt_associacao' => $associacao->idt_associacao,
        'des_arquivo' => 'seed.ofx',
        'cod_banco' => '77',
        'num_conta' => '99999-9',
        'dat_inicio' => '2026-09-01',
        'dat_fim' => '2026-09-30',
        'qtd_transacao' => 0,
        'val_total' => 0,
    ]);

    Transacao::create([
        'idt_ofx' => $ofx->idt_ofx,
        'num_transacao' => '12 34',          // espaço -> normaliza para 1234
        'dat_transacao' => '2026-09-01',
        'tip_transacao' => 'CREDIT',
        'val_transacao' => 10.00,
        'num_check' => '(56) 78.',           // separadores -> normaliza para 5678
    ]);

    // Agora importa dois blocos cujos CHECKNUMs, só-dígitos, batem com cada coluna.
    $arquivo = escreverOfxTemporario([
        ['CHECKNUM' => '12-34', 'TRNAMT' => '10.00', 'FITID' => 'A1'],
        ['CHECKNUM' => '5.6.7.8', 'TRNAMT' => '10.00', 'FITID' => 'A2'],
    ]);

    $service->processar($arquivo, 'novo.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    // Nenhuma transação nova: ambas deduplicadas contra as colunas persistidas.
    expect(Transacao::withoutGlobalScope('associacao')->count())->toBe(1);
});

// -----------------------------------------------------------------------------
// Finding 2 — critério CHECKNUM vazio após normalização não casa
// -----------------------------------------------------------------------------

test('critério CHECKNUM sem dígitos não exclui transações sem CHECKNUM', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    // Regra com CHECKNUM = "ABC" (normaliza para vazio). Não deve casar nada.
    criarRegra($associacao, EfeitoRegra::EXCLUI_TUDO, 'CHECKNUM', 'ABC');

    $arquivo = escreverOfxTemporario([
        ['TRNAMT' => '10.00', 'FITID' => 'SEMCHK'], // sem CHECKNUM
    ]);

    $service->processar($arquivo, 'semchk.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    $t = Transacao::withoutGlobalScope('associacao')->firstOrFail();

    // Como o alvo normalizado é vazio, a regra não casa: flags permanecem true.
    expect($t->ind_computa_receita)->toBeTrue();
    expect($t->ind_computa_despesa)->toBeTrue();
});

// -----------------------------------------------------------------------------
// Finding 4 — matriz de regras: efeitos isolados, lógica E, inativa, defaults
// -----------------------------------------------------------------------------

test('EXCLUI_RECEITA zera só a flag de receita', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    criarRegra($associacao, EfeitoRegra::EXCLUI_RECEITA, 'NOM_PESSOA', 'FORNECEDOR X');

    $arquivo = escreverOfxTemporario([
        ['TRNAMT' => '10.00', 'FITID' => 'R1', 'NAME' => 'Fornecedor X'],
    ]);
    $service->processar($arquivo, 'r.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    $t = Transacao::withoutGlobalScope('associacao')->firstOrFail();
    expect($t->ind_computa_receita)->toBeFalse();
    expect($t->ind_computa_despesa)->toBeTrue();
});

test('EXCLUI_DESPESA zera só a flag de despesa', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    criarRegra($associacao, EfeitoRegra::EXCLUI_DESPESA, 'TIPO', 'DEBIT');

    $arquivo = escreverOfxTemporario([
        ['TRNTYPE' => 'DEBIT', 'TRNAMT' => '-10.00', 'FITID' => 'D1', 'NAME' => 'Pagamento'],
    ]);
    $service->processar($arquivo, 'd.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    $t = Transacao::withoutGlobalScope('associacao')->firstOrFail();
    expect($t->ind_computa_despesa)->toBeFalse();
    expect($t->ind_computa_receita)->toBeTrue();
});

test('regra com dois critérios só casa quando ambos batem (lógica E)', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    // Regra E: NOM_PESSOA contém "ACME" E TIPO = DEBIT.
    $regra = RegraClassificacao::create([
        'idt_associacao' => $associacao->idt_associacao,
        'nom_regra' => 'Acme débito',
        'efeito' => EfeitoRegra::EXCLUI_TUDO,
        'ind_ativo' => true,
    ]);
    $regra->criterios()->createMany([
        ['tip_match' => 'NOM_PESSOA', 'val_match' => 'ACME'],
        ['tip_match' => 'TIPO', 'val_match' => 'DEBIT'],
    ]);

    $arquivo = escreverOfxTemporario([
        // Casa ambos -> excluída.
        ['TRNTYPE' => 'DEBIT', 'TRNAMT' => '-10.00', 'FITID' => 'E1', 'NAME' => 'ACME LTDA'],
        // Casa só o nome (tipo CREDIT) -> NÃO excluída.
        ['TRNTYPE' => 'CREDIT', 'TRNAMT' => '10.00', 'FITID' => 'E2', 'NAME' => 'ACME LTDA'],
    ]);
    $service->processar($arquivo, 'e.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    $casaAmbos = Transacao::withoutGlobalScope('associacao')->where('num_transacao', 'E1')->firstOrFail();
    $casaUm = Transacao::withoutGlobalScope('associacao')->where('num_transacao', 'E2')->firstOrFail();

    expect($casaAmbos->ind_computa_receita)->toBeFalse();
    expect($casaAmbos->ind_computa_despesa)->toBeFalse();
    expect($casaUm->ind_computa_receita)->toBeTrue();
    expect($casaUm->ind_computa_despesa)->toBeTrue();
});

test('regra inativa não afeta as flags', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    criarRegra($associacao, EfeitoRegra::EXCLUI_TUDO, 'NOM_PESSOA', 'INATIVA', ativo: false);

    $arquivo = escreverOfxTemporario([
        ['TRNAMT' => '10.00', 'FITID' => 'I1', 'NAME' => 'Inativa Teste'],
    ]);
    $service->processar($arquivo, 'i.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    $t = Transacao::withoutGlobalScope('associacao')->firstOrFail();
    expect($t->ind_computa_receita)->toBeTrue();
    expect($t->ind_computa_despesa)->toBeTrue();
});

test('sem regra casando as flags ficam no default verdadeiro', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    criarRegra($associacao, EfeitoRegra::EXCLUI_TUDO, 'NOM_PESSOA', 'OUTRO NOME');

    $arquivo = escreverOfxTemporario([
        ['TRNAMT' => '10.00', 'FITID' => 'N1', 'NAME' => 'Nome Diferente'],
    ]);
    $service->processar($arquivo, 'n.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    $t = Transacao::withoutGlobalScope('associacao')->firstOrFail();
    expect($t->ind_computa_receita)->toBeTrue();
    expect($t->ind_computa_despesa)->toBeTrue();
});

// -----------------------------------------------------------------------------
// Finding 4 — smoke das consultas consumidoras (dashboard / despesas)
// -----------------------------------------------------------------------------

test('consulta de despesas exclui débitos não computáveis', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    // Regra que exclui despesa dos débitos "TARIFA".
    criarRegra($associacao, EfeitoRegra::EXCLUI_DESPESA, 'NOM_PESSOA', 'TARIFA');

    $arquivo = escreverOfxTemporario([
        ['TRNTYPE' => 'DEBIT', 'TRNAMT' => '-10.00', 'FITID' => 'DESP1', 'NAME' => 'Pagamento normal'],
        ['TRNTYPE' => 'DEBIT', 'TRNAMT' => '-5.00', 'FITID' => 'DESP2', 'NAME' => 'TARIFA banco'],
    ]);
    $service->processar($arquivo, 'desp.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    // Mesma query da tela de despesas: DEBIT + ind_computa_despesa = true.
    $despesasVisiveis = Transacao::withoutGlobalScope('associacao')
        ->where('tip_transacao', 'DEBIT')
        ->where('ind_computa_despesa', true)
        ->pluck('num_transacao')
        ->all();

    expect($despesasVisiveis)->toContain('DESP1');
    expect($despesasVisiveis)->not->toContain('DESP2');
});

test('consulta de receita do dashboard exclui créditos não computáveis', function () {
    $associacao = criarContextoClassificacao();
    $service = new OfxParserService;

    // Rende Fácil (9903) já é EXCLUI_TUDO no contexto; adiciona um crédito normal.
    $arquivo = escreverOfxTemporario([
        ['TRNTYPE' => 'CREDIT', 'TRNAMT' => '40.00', 'FITID' => 'REC1', 'CHECKNUM' => '12345', 'NAME' => 'Pix - Recebido'],
        ['TRNTYPE' => 'CREDIT', 'TRNAMT' => '240.00', 'FITID' => 'REC2', 'CHECKNUM' => '9903', 'NAME' => 'Rende Facil'],
    ]);
    $service->processar($arquivo, 'rec.ofx', $associacao->idt_associacao);
    @unlink($arquivo);

    // Mesma semântica do dashboard: receita só com ind_computa_receita = true.
    $totalReceita = Transacao::withoutGlobalScope('associacao')
        ->where('val_transacao', '>', 0)
        ->where('ind_computa_receita', true)
        ->sum('val_transacao');

    expect((float) $totalReceita)->toBe(40.00);
});
