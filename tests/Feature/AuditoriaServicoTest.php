<?php

use App\Exceptions\AutorizacaoAuditoriaException;
use App\Models\Associacao;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use App\Services\AuditoriaService;

function criarOfx(int $idtAssociacao, string $banco = '001', string $conta = '12345-6', ?string $hash = null, ?string $ini = '2026-11-01', ?string $fim = '2026-11-30'): Ofx
{
    return Ofx::factory()->create([
        'idt_associacao' => $idtAssociacao,
        'cod_banco' => $banco,
        'num_conta' => $conta,
        'hash_arquivo' => $hash,
        'dat_inicio' => $ini,
        'dat_fim' => $fim,
    ]);
}

// Teste 5: deteccao de duplicidades + valor_excedente + total_excedente.
test('detecta transacoes e resumos duplicados e calcula o total excedente', function () {
    $assoc = Associacao::factory()->create();
    $ofx = criarOfx($assoc->idt_associacao);

    // Transacao duplicada (mesmo FITID, mesma conta): 2 registros CREDIT de 40.
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'DUP1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'DUP1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);

    // Resumo duplicado (mesma pessoa sem membro, mesmo idt_ofx/ano/mes).
    Resumo::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:123', 'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 40.00, 'qtd_transacao' => 1, 'ind_pago' => true]);
    Resumo::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:123', 'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 40.00, 'qtd_transacao' => 1, 'ind_pago' => true]);

    $relatorio = app(AuditoriaService::class)->detectar($assoc->idt_associacao);

    expect($relatorio['transacoes_duplicadas'])->toHaveCount(1);
    expect($relatorio['resumos_duplicados'])->toHaveCount(1);
    expect($relatorio['transacoes_duplicadas']->first()->valor_excedente)->toBe(40.00);
    expect($relatorio['resumos_duplicados']->first()->valor_excedente)->toBe(40.00);
    expect($relatorio['total_excedente'])->toBe(80.00);
});

test('sem duplicidades retorna colecoes vazias e total 0', function () {
    $assoc = Associacao::factory()->create();
    $ofx = criarOfx($assoc->idt_associacao);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'UNICA', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);

    $relatorio = app(AuditoriaService::class)->detectar($assoc->idt_associacao);
    expect($relatorio['transacoes_duplicadas'])->toHaveCount(0);
    expect($relatorio['resumos_duplicados'])->toHaveCount(0);
    expect($relatorio['total_excedente'])->toBe(0.0);
});

test('sinaliza OFX reimportado com hash identico', function () {
    $assoc = Associacao::factory()->create();
    criarOfx($assoc->idt_associacao, '001', '1-1', 'HASHIGUAL');
    criarOfx($assoc->idt_associacao, '001', '1-1', 'HASHIGUAL');

    $relatorio = app(AuditoriaService::class)->detectar($assoc->idt_associacao);
    expect($relatorio['ofx_reimportados']->isNotEmpty())->toBeTrue();
});

// Teste 6: correcao atomica.
test('corrigir transacoes duplicadas mantem a de menor id e recalcula o OFX', function () {
    $assoc = Associacao::factory()->create();
    $ofx = criarOfx($assoc->idt_associacao);

    $t1 = Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'C1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);
    $t2 = Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'C1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);

    $servico = app(AuditoriaService::class);
    $relatorio = $servico->detectar($assoc->idt_associacao);
    $chave = $relatorio['transacoes_duplicadas']->first()->chave_grupo;

    $servico->corrigirTransacoesDuplicadas($assoc->idt_associacao, $chave, 1);

    expect(Transacao::withoutGlobalScopes()->where('idt_ofx', $ofx->idt_ofx)->count())->toBe(1);
    expect(Transacao::withoutGlobalScopes()->find($t1->idt_transacao))->not->toBeNull();
    expect(Transacao::withoutGlobalScopes()->find($t2->idt_transacao))->toBeNull();
    expect((float) $ofx->fresh()->val_total)->toBe(40.00);
});

test('corrigir resumos duplicados mantem o de menor id', function () {
    $assoc = Associacao::factory()->create();
    $ofx = criarOfx($assoc->idt_associacao);

    $r1 = Resumo::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:9', 'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 40.00, 'qtd_transacao' => 1, 'ind_pago' => true]);
    $r2 = Resumo::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:9', 'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 40.00, 'qtd_transacao' => 1, 'ind_pago' => true]);

    $servico = app(AuditoriaService::class);
    $chave = $servico->detectar($assoc->idt_associacao)['resumos_duplicados']->first()->chave_grupo;
    $servico->corrigirResumosDuplicados($assoc->idt_associacao, $chave, 1);

    expect(Resumo::withoutGlobalScopes()->where('idt_ofx', $ofx->idt_ofx)->count())->toBe(1);
    expect(Resumo::withoutGlobalScopes()->find($r1->idt_resumo))->not->toBeNull();
    expect(Resumo::withoutGlobalScopes()->find($r2->idt_resumo))->toBeNull();
});

// Teste 7: multi-tenant do servico.
test('corrigir grupo de outra associacao e recusado sem alterar dados', function () {
    $assocA = Associacao::factory()->create();
    $assocB = Associacao::factory()->create();
    $ofxA = criarOfx($assocA->idt_associacao);

    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofxA->idt_ofx, 'num_transacao' => 'X1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofxA->idt_ofx, 'num_transacao' => 'X1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);

    $servico = app(AuditoriaService::class);
    $chave = $servico->detectar($assocA->idt_associacao)['transacoes_duplicadas']->first()->chave_grupo;

    // Tenta corrigir no contexto da associacao B (nao dona).
    expect(fn () => $servico->corrigirTransacoesDuplicadas($assocB->idt_associacao, $chave, 1))
        ->toThrow(AutorizacaoAuditoriaException::class);

    expect(Transacao::withoutGlobalScopes()->where('idt_ofx', $ofxA->idt_ofx)->count())->toBe(2);
});

test('contarPendentes conta grupos de transacoes e resumos duplicados', function () {
    $assoc = Associacao::factory()->create();
    $ofx = criarOfx($assoc->idt_associacao);

    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'P1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 10.00]);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'P1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 10.00]);
    Resumo::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:5', 'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 10.00, 'qtd_transacao' => 1, 'ind_pago' => true]);
    Resumo::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:5', 'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 10.00, 'qtd_transacao' => 1, 'ind_pago' => true]);

    expect(app(AuditoriaService::class)->contarPendentes($assoc->idt_associacao))->toBe(2);
});
