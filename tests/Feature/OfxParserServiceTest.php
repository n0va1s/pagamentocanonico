<?php

use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use App\Services\OfxParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ofx parser service processes BB OFX file correctly', function () {
    $filePath = base_path('scripts/202609.ofx');

    // Ensure sample file exists before running test
    expect(file_exists($filePath))->toBeTrue();

    $service = new OfxParserService;
    $ofx = $service->processar($filePath, '202609.ofx');

    // Assert Ofx model is correctly saved
    expect($ofx)->toBeInstanceOf(Ofx::class);
    expect($ofx->des_arquivo)->toBe('202609.ofx');
    expect($ofx->cod_banco)->toBe('1');
    expect($ofx->num_conta)->toBe('32443-4');
    expect($ofx->dat_inicio->format('Y-m-d'))->toBe('2026-08-25');
    expect($ofx->dat_fim->format('Y-m-d'))->toBe('2026-09-30');

    // Assert all 35 statement transactions are persisted (no balance rows in file)
    expect(Transacao::count())->toBe(35);
    expect($ofx->qtd_transacao)->toBe(35);

    // Assert that Saldo Anterior or Saldo do dia are not in database
    expect(Transacao::where('nom_pessoa', 'like', '%Saldo%')->count())->toBe(0);
    expect(Transacao::whereNull('num_transacao')->orWhere('num_transacao', '')->count())->toBe(0);

    // Assert that dynamic encoding correctly handled accentuation (e.g. "BB Rende Fácil")
    $rendeFacil = Transacao::where('nom_pessoa', 'BB Rende Fácil')->first();
    expect($rendeFacil)->not->toBeNull();
    expect($rendeFacil->nom_pessoa)->toBe('BB Rende Fácil');

    // Assert that payer names are correctly cleaned from starting date/time and document numbers
    $pixRoberto = Transacao::where('num_check', '11729024928721')->first();
    expect($pixRoberto)->not->toBeNull();
    expect($pixRoberto->des_transacao)->toBe('ROBERTO LINDAU');

    $pixJoao = Transacao::where('num_check', '50552236870722')->first();
    expect($pixJoao)->not->toBeNull();
    expect($pixJoao->des_transacao)->toBe('JOAO PAULO');
    expect($pixJoao->num_cpf_pagador)->toBe('85236250110');

    // Assert that monthly summaries (credits only) are correctly generated
    expect(Resumo::count())->toBeGreaterThan(0);

    // The single CREDIT of 440,00 (Paulo Eduardo) must appear as a summary
    $resumoPaulo = Resumo::all()->first(fn ($r) => (float) $r->val_total === 440.00);
    expect($resumoPaulo)->not->toBeNull();
    expect($resumoPaulo->qtd_transacao)->toBe(1);
});

test('ofx parser service does not duplicate ofx records when importing the same file twice', function () {
    $filePath = base_path('scripts/202609.ofx');
    $service = new OfxParserService;

    $ofx1 = $service->processar($filePath, 'Extrato1.ofx');
    $ofx2 = $service->processar($filePath, 'Extrato2.ofx');

    // Both should point to the same DB record
    expect($ofx1->idt_ofx)->toBe($ofx2->idt_ofx);
    expect(Ofx::count())->toBe(1);

    // Totals should remain correct (idempotent reimport)
    expect($ofx2->qtd_transacao)->toBe(35);
    expect(Transacao::count())->toBe(35);

    // Check if filename was updated
    expect($ofx2->des_arquivo)->toBe('Extrato2.ofx');
});
