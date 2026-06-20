<?php

use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use App\Services\OfxParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ofx parser service processes BB OFX file correctly', function () {
    $filePath = base_path('docs/ofx/Extrato conta corrente - 032026.ofx');

    // Ensure sample file exists before running test
    expect(file_exists($filePath))->toBeTrue();

    $service = new OfxParserService();
    $ofx = $service->processar($filePath, 'Extrato conta corrente - 032026.ofx');

    // Assert Ofx model is correctly saved
    expect($ofx)->toBeInstanceOf(Ofx::class);
    expect($ofx->des_arquivo)->toBe('Extrato conta corrente - 032026.ofx');
    expect($ofx->cod_banco)->toBe('1');
    expect($ofx->num_conta)->toBe('27676');
    expect($ofx->dat_inicio->format('Y-m-d'))->toBe('2026-03-31');
    expect($ofx->dat_fim->format('Y-m-d'))->toBe('2026-06-18');

    // Assert informational balance rows are ignored, leaving exactly 45 transactions
    expect(Transacao::count())->toBe(45);
    expect($ofx->qtd_transacao)->toBe(45);

    // Assert that Saldo Anterior or Saldo do dia are not in database
    expect(Transacao::where('nom_pessoa', 'like', '%Saldo%')->count())->toBe(0);
    expect(Transacao::whereNull('num_transacao')->orWhere('num_transacao', '')->count())->toBe(0);

    // Assert that dynamic encoding correctly handled accentuation (e.g. "Transferência Periódica")
    $transferencia = Transacao::where('nom_pessoa', 'Transferência Periódica')->first();
    expect($transferencia)->not->toBeNull();
    expect($transferencia->nom_pessoa)->toBe('Transferência Periódica');

    // Assert that payer names are correctly cleaned from starting date/time and document numbers
    $pixHelena = Transacao::where('num_transacao', '181.853.329.955.092')->first();
    expect($pixHelena)->not->toBeNull();
    expect($pixHelena->des_transacao)->toBe('HELENA PATR');

    $pixGraca = Transacao::where('num_transacao', '191.633.419.375.751')->first();
    expect($pixGraca)->not->toBeNull();
    expect($pixGraca->des_transacao)->toBe('MARIA DA GRACA');

    // Assert that monthly summaries (credits only) are correctly generated
    expect(Resumo::count())->toBeGreaterThan(0);

    // Helena summary
    $resumoHelena = Resumo::where('nom_pessoa', 'HELENA PATR')->first();
    expect($resumoHelena)->not->toBeNull();
    expect((float)$resumoHelena->val_total)->toBe(8.40);
    expect($resumoHelena->num_transacao)->toBe(1);

    // Maria da Graca summary
    $resumoGraca = Resumo::where('nom_pessoa', 'MARIA DA GRACA')->first();
    expect($resumoGraca)->not->toBeNull();
    expect((float)$resumoGraca->val_total)->toBe(50.00);
    expect($resumoGraca->num_transacao)->toBe(1);

    // Servico Federal de summary
    $resumoServico = Resumo::where('nom_pessoa', 'SERVICO FEDERAL DE')->first();
    expect($resumoServico)->not->toBeNull();
    expect((float)$resumoServico->val_total)->toBe(14830.99);
    expect($resumoServico->num_transacao)->toBe(2);
});
