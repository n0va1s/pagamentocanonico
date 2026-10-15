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
