<?php

use App\Models\Associacao;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use App\Services\OfxParserService;

/**
 * Gera uma fixture OFX minima determinista em arquivo temporario.
 *
 * @param  array<int, array{fitid:string, valor:string, tipo?:string, memo?:string, name?:string, data?:string}>  $transacoes
 */
function gerarOfxTemp(array $transacoes, string $banco = '001', string $conta = '12345-6', string $dtstart = '20261101', string $dtend = '20261130'): string
{
    $blocos = '';
    foreach ($transacoes as $t) {
        $tipo = $t['tipo'] ?? 'CREDIT';
        $data = $t['data'] ?? '20261115';
        $memo = $t['memo'] ?? 'PAGAMENTO';
        $name = $t['name'] ?? 'PAGADOR';
        $blocos .= "<STMTTRN><TRNTYPE>{$tipo}<DTPOSTED>{$data}<TRNAMT>{$t['valor']}<FITID>{$t['fitid']}<NAME>{$name}<MEMO>{$memo}</STMTTRN>\n";
    }

    $conteudo = "OFXHEADER:100\nENCODING:UTF-8\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><BANKID>{$banco}<ACCTID>{$conta}</BANKACCTFROM><BANKTRANLIST><DTSTART>{$dtstart}<DTEND>{$dtend}\n{$blocos}</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";

    $caminho = tempnam(sys_get_temp_dir(), 'ofx').'.ofx';
    file_put_contents($caminho, $conteudo);

    return $caminho;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/*.ofx') as $f) {
        @unlink($f);
    }
});

// Teste 2: idempotencia de resumos sem-membro -- reproduz R$80 vs R$40.
test('reimportar o mesmo periodo nao duplica resumo de pagador sem membro (R$40 nao vira R$80)', function () {
    $assoc = Associacao::factory()->create();
    $parser = app(OfxParserService::class);

    $arquivo = gerarOfxTemp([
        ['fitid' => 'TX001', 'valor' => '40.00', 'memo' => 'PAGADOR SEM MEMBRO', 'name' => 'JOAO'],
    ]);

    $parser->processar($arquivo, 'extrato1.ofx', $assoc->idt_associacao);
    // Segunda importacao do MESMO conteudo (hash igual -> curto-circuito).
    $parser->processar($arquivo, 'extrato2.ofx', $assoc->idt_associacao);

    $resumos = Resumo::withoutGlobalScopes()
        ->whereHas('ofx', fn ($q) => $q->withoutGlobalScopes()->where('idt_associacao', $assoc->idt_associacao))
        ->get();

    expect($resumos)->toHaveCount(1);
    expect((float) $resumos->sum('val_total'))->toBe(40.00);
});

// Teste 2 (variante): dois pagadores sem membro, MEMO vira 'Nao identificado', CPFs distintos nao colapsam.
test('pagadores sem membro com CPFs distintos nao colapsam no mesmo resumo', function () {
    $assoc = Associacao::factory()->create();
    $ofx = Ofx::factory()->create([
        'idt_associacao' => $assoc->idt_associacao,
        'cod_banco' => '001',
        'num_conta' => '12345-6',
    ]);

    // Insercao direta de transacoes de credito, sem membro, mesma descricao mas CPFs distintos.
    Transacao::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'A1', 'dat_transacao' => '2026-11-10',
        'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00, 'des_transacao' => 'Não identificado',
        'num_cpf_pagador' => '11111111111',
    ]);
    Transacao::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'A2', 'dat_transacao' => '2026-11-11',
        'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00, 'des_transacao' => 'Não identificado',
        'num_cpf_pagador' => '22222222222',
    ]);

    $metodo = new ReflectionMethod(OfxParserService::class, 'gerarResumosMensais');
    $metodo->setAccessible(true);
    $metodo->invoke(app(OfxParserService::class), $ofx->fresh());

    $resumos = Resumo::withoutGlobalScopes()->where('idt_ofx', $ofx->idt_ofx)->get();
    expect($resumos)->toHaveCount(2);
});

// Teste 2b: equivalencia do criterio canonico de credito (tip_transacao=CREDIT).
test('gerarResumosMensais usa tip_transacao CREDIT como criterio', function () {
    $assoc = Associacao::factory()->create();
    $ofx = Ofx::factory()->create(['idt_associacao' => $assoc->idt_associacao, 'cod_banco' => '001', 'num_conta' => '1-1']);

    // (i) CREDIT com valor positivo -> entra
    Transacao::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'C1', 'dat_transacao' => '2026-11-05',
        'tip_transacao' => 'CREDIT', 'val_transacao' => 100.00, 'des_transacao' => 'ALICE', 'num_cpf_pagador' => '33333333333',
    ]);
    // (ii) tip nulo com valor positivo -> NAO entra
    Transacao::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'C2', 'dat_transacao' => '2026-11-06',
        'tip_transacao' => null, 'val_transacao' => 50.00, 'des_transacao' => 'BOB', 'num_cpf_pagador' => '44444444444',
    ]);
    // (iii) DEBIT -> NAO entra
    Transacao::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'C3', 'dat_transacao' => '2026-11-07',
        'tip_transacao' => 'DEBIT', 'val_transacao' => 70.00, 'des_transacao' => 'CAROL', 'num_cpf_pagador' => '55555555555',
    ]);

    $metodo = new ReflectionMethod(OfxParserService::class, 'gerarResumosMensais');
    $metodo->setAccessible(true);
    $metodo->invoke(app(OfxParserService::class), $ofx->fresh());

    $resumos = Resumo::withoutGlobalScopes()->where('idt_ofx', $ofx->idt_ofx)->get();
    expect($resumos)->toHaveCount(1);
    expect((float) $resumos->sum('val_total'))->toBe(100.00);
});

// Teste 1: reconciliacao incremental (mesmo periodo/conta, conteudo diferente).
test('reconciliacao incremental adiciona apenas transacoes novas sem acumular', function () {
    $assoc = Associacao::factory()->create();
    $parser = app(OfxParserService::class);

    $arq1 = gerarOfxTemp([
        ['fitid' => 'R1', 'valor' => '40.00', 'name' => 'A'],
        ['fitid' => 'R2', 'valor' => '60.00', 'name' => 'B'],
    ]);
    $ofx1 = $parser->processar($arq1, 'parte1.ofx', $assoc->idt_associacao);
    expect($ofx1->resultadoImportacao['status'])->toBe('criado');
    expect($ofx1->resultadoImportacao['novas'])->toBe(2);

    // Segundo arquivo: mesmo periodo/conta, 1 repetida (R2) + 1 nova (R3).
    $arq2 = gerarOfxTemp([
        ['fitid' => 'R2', 'valor' => '60.00', 'name' => 'B'],
        ['fitid' => 'R3', 'valor' => '30.00', 'name' => 'C'],
    ]);
    $ofx2 = $parser->processar($arq2, 'parte2.ofx', $assoc->idt_associacao);

    expect(Ofx::withoutGlobalScopes()->where('idt_associacao', $assoc->idt_associacao)->count())->toBe(1);
    expect($ofx2->idt_ofx)->toBe($ofx1->idt_ofx);
    expect($ofx2->resultadoImportacao['status'])->toBe('reconciliado');
    expect($ofx2->resultadoImportacao['novas'])->toBe(1);
    expect($ofx2->resultadoImportacao['jaExistentes'])->toBe(1);

    $total = Transacao::withoutGlobalScopes()->where('idt_ofx', $ofx1->idt_ofx)->count();
    expect($total)->toBe(3);
    expect((float) $ofx2->fresh()->val_total)->toBe(130.00);
});

// Teste 3: dedup de FITID escopada por associacao e conta.
test('dedup de FITID e escopada por associacao e conta', function () {
    $assocA = Associacao::factory()->create();
    $assocB = Associacao::factory()->create();
    $parser = app(OfxParserService::class);

    // Mesmo FITID em associacoes distintas -> coexistem.
    $arqA = gerarOfxTemp([['fitid' => 'DUP', 'valor' => '10.00']], '001', '111-1');
    $arqB = gerarOfxTemp([['fitid' => 'DUP', 'valor' => '10.00']], '001', '111-1');
    $parser->processar($arqA, 'a.ofx', $assocA->idt_associacao);
    $parser->processar($arqB, 'b.ofx', $assocB->idt_associacao);

    expect(Transacao::withoutGlobalScopes()->where('num_transacao', 'DUP')->count())->toBe(2);
});

// Teste 3 (variante): repeticao de FITID dentro do mesmo arquivo conta como 1.
test('FITID repetido no mesmo arquivo gera uma unica transacao', function () {
    $assoc = Associacao::factory()->create();
    $parser = app(OfxParserService::class);

    $arq = gerarOfxTemp([
        ['fitid' => 'SAME', 'valor' => '10.00'],
        ['fitid' => 'SAME', 'valor' => '10.00'],
    ]);
    $ofx = $parser->processar($arq, 'repete.ofx', $assoc->idt_associacao);

    expect(Transacao::withoutGlobalScopes()->where('idt_ofx', $ofx->idt_ofx)->where('num_transacao', 'SAME')->count())->toBe(1);
    expect($ofx->resultadoImportacao['jaExistentes'])->toBe(1);
});

// Teste 4: curto-circuito por hash + idtAssociacao null + falha de hash_file.
test('reimportar arquivo identico dispara curto-circuito por hash (status ja_atualizado)', function () {
    $assoc = Associacao::factory()->create();
    $parser = app(OfxParserService::class);

    $arq = gerarOfxTemp([['fitid' => 'H1', 'valor' => '25.00']]);
    $ofx1 = $parser->processar($arq, 'orig.ofx', $assoc->idt_associacao);
    expect($ofx1->hash_arquivo)->not->toBeNull();

    $ofx2 = $parser->processar($arq, 'renomeado.ofx', $assoc->idt_associacao);
    expect($ofx2->resultadoImportacao['status'])->toBe('ja_atualizado');
    expect($ofx2->des_arquivo)->toBe('renomeado.ofx');
    expect(Transacao::withoutGlobalScopes()->where('idt_ofx', $ofx1->idt_ofx)->count())->toBe(1);
});

test('curto-circuito por hash funciona com idtAssociacao null', function () {
    $parser = app(OfxParserService::class);
    $arq = gerarOfxTemp([['fitid' => 'N1', 'valor' => '15.00']]);

    $o1 = $parser->processar($arq, 'n1.ofx', null);
    $o2 = $parser->processar($arq, 'n2.ofx', null);

    expect($o2->idt_ofx)->toBe($o1->idt_ofx);
    expect(Ofx::withoutGlobalScopes()->whereNull('idt_associacao')->count())->toBe(1);
});

test('falha ao ler arquivo para hash interrompe sem persistir OFX', function () {
    $parser = app(OfxParserService::class);
    expect(fn () => $parser->processar('/caminho/inexistente/x.ofx', 'x.ofx', null))
        ->toThrow(RuntimeException::class);
    expect(Ofx::withoutGlobalScopes()->count())->toBe(0);
});
