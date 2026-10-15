<?php

use App\Models\Associacao;
use App\Models\Membro;
use App\Models\Ofx;
use App\Models\Resumo;
use App\Models\Transacao;
use App\Models\User;
use App\Services\OfxParserService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Dispensa o manifest do Vite (assets nao compilados no ambiente de teste).
    $this->withoutVite();
});

function ofxComDuplicidade(int $idtAssociacao): Ofx
{
    $ofx = Ofx::factory()->create([
        'idt_associacao' => $idtAssociacao,
        'cod_banco' => '001',
        'num_conta' => '12345-6',
        'dat_inicio' => '2026-11-01',
        'dat_fim' => '2026-11-30',
    ]);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'D1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'num_transacao' => 'D1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);

    return $ofx;
}

// Teste 7: acesso por papel.
test('diretor acessa a tela de auditoria', function () {
    $diretor = User::factory()->diretor()->create();
    $this->actingAs($diretor);
    $this->get(route('auditoria'))->assertOk();
});

test('membro comum nao acessa a tela de auditoria', function () {
    $membro = User::factory()->membro()->create();
    $this->actingAs($membro);
    $this->get(route('auditoria'))->assertRedirect(route('dashboard'));
});

// Teste 7: multi-tenant -- diretor so enxerga suas proprias duplicidades.
test('diretor com associacao enxerga suas proprias duplicidades', function () {
    $diretor = User::factory()->diretor()->create();
    $assoc = $diretor->membro->idt_associacao;
    ofxComDuplicidade($assoc);

    $this->actingAs($diretor);
    $this->get(route('auditoria'))
        ->assertOk()
        ->assertSee('Transações duplicadas')
        ->assertSee('40,00');
});

test('usuario de associacao nao enxerga duplicidades de outra associacao', function () {
    $diretorA = User::factory()->diretor()->create();

    $assocB = Associacao::factory()->create();
    ofxComDuplicidade($assocB->idt_associacao);

    $this->actingAs($diretorA);
    $this->get(route('auditoria'))
        ->assertOk()
        ->assertSee('Nenhuma duplicidade encontrada');
});

// Atalho por associacao: admin abre a auditoria ja com a associacao da URL.
test('admin abre a auditoria ja com a associacao informada na URL', function () {
    $admin = User::factory()->admin()->create();
    $assoc = Associacao::factory()->create();
    ofxComDuplicidade($assoc->idt_associacao);

    $this->actingAs($admin);
    $this->get(route('auditoria', ['associacao' => $assoc->idt_associacao]))
        ->assertOk()
        ->assertSee('Transações duplicadas')
        ->assertSee('40,00');
});

// Enforcement: nao-admin ignora o parametro da URL e usa a propria associacao.
test('diretor ignora o parametro de associacao da URL (enforcement multi-tenant)', function () {
    $diretor = User::factory()->diretor()->create();

    $assocB = Associacao::factory()->create();
    ofxComDuplicidade($assocB->idt_associacao);

    $this->actingAs($diretor);
    // Tenta forcar a associacao B pela URL; deve ser ignorada.
    $this->get(route('auditoria', ['associacao' => $assocB->idt_associacao]))
        ->assertOk()
        ->assertSee('Nenhuma duplicidade encontrada');
});

// Teste 7b: diretor sem associacao vinculada.
test('diretor sem associacao vinculada ve aviso e nao vaza dados', function () {
    $assocB = Associacao::factory()->create();
    ofxComDuplicidade($assocB->idt_associacao);

    $diretor = User::factory()->diretor()->create();
    DB::table('users')->where('id', $diretor->id)->update(['idt_membro' => null]);
    $diretor->refresh();

    expect($diretor->getMembroAssociacaoId())->toBeNull();

    $this->actingAs($diretor);
    $this->get(route('auditoria'))
        ->assertOk()
        ->assertSee('não está vinculado a uma associação')
        ->assertDontSee('Transações duplicadas');
});

// Teste 8: indicador do dashboard.
test('dashboard mostra badge de duplicidades pendentes quando existem', function () {
    $diretor = User::factory()->diretor()->create();
    $assoc = $diretor->membro->idt_associacao;
    ofxComDuplicidade($assoc);

    $this->actingAs($diretor);
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('pendente(s) de auditoria');
});

test('dashboard oculta badge quando nao ha duplicidades', function () {
    $diretor = User::factory()->diretor()->create();

    $this->actingAs($diretor);
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('pendente(s) de auditoria');
});

// Teste 9 (b): orfao nao_identificado nunca e removido.
test('orfao nao_identificado nao e removido na consolidacao', function () {
    $assoc = Associacao::factory()->create();
    $ofx = Ofx::factory()->create(['idt_associacao' => $assoc->idt_associacao, 'cod_banco' => '001', 'num_conta' => '1-1']);

    $resumo = Resumo::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'nao_identificado',
        'nom_pessoa' => 'Não identificado', 'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov',
        'val_total' => 40.00, 'qtd_transacao' => 1, 'ind_pago' => true,
    ]);
    $membro = Membro::factory()->create(['idt_associacao' => $assoc->idt_associacao]);
    Transacao::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'idt_membro' => $membro->idt_membro, 'idt_resumo' => $resumo->idt_resumo,
        'num_transacao' => 'Z1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00,
    ]);

    $metodo = new ReflectionMethod(OfxParserService::class, 'consolidarOrfaosSemMembro');
    $metodo->setAccessible(true);
    $metodo->invoke(app(OfxParserService::class), $ofx->fresh());

    expect(Resumo::withoutGlobalScopes()->find($resumo->idt_resumo))->not->toBeNull();
});

// Teste 9 (a): orfao discriminante migrado integralmente a um membro e removido.
test('orfao discriminante migrado a um unico membro e consolidado', function () {
    $assoc = Associacao::factory()->create();
    $ofx = Ofx::factory()->create(['idt_associacao' => $assoc->idt_associacao, 'cod_banco' => '001', 'num_conta' => '1-1']);
    $membro = Membro::factory()->create(['idt_associacao' => $assoc->idt_associacao]);

    Resumo::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'idt_membro' => $membro->idt_membro, 'num_ano' => 2026, 'num_mes' => 11,
        'nom_mes' => 'Nov', 'val_total' => 40.00, 'qtd_transacao' => 1, 'ind_pago' => true,
    ]);
    $orfao = Resumo::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:777', 'nom_pessoa' => 'FULANO',
        'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 40.00, 'qtd_transacao' => 1, 'ind_pago' => true,
    ]);
    Transacao::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'idt_membro' => $membro->idt_membro, 'idt_resumo' => $orfao->idt_resumo,
        'num_transacao' => 'W1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00,
    ]);

    $metodo = new ReflectionMethod(OfxParserService::class, 'consolidarOrfaosSemMembro');
    $metodo->setAccessible(true);
    $metodo->invoke(app(OfxParserService::class), $ofx->fresh());

    expect(Resumo::withoutGlobalScopes()->find($orfao->idt_resumo))->toBeNull();
});

// Teste 9 (c): orfao migrado a dois membros distintos NAO e removido.
test('orfao migrado a dois membros diferentes nao e removido', function () {
    $assoc = Associacao::factory()->create();
    $ofx = Ofx::factory()->create(['idt_associacao' => $assoc->idt_associacao, 'cod_banco' => '001', 'num_conta' => '1-1']);
    $m1 = Membro::factory()->create(['idt_associacao' => $assoc->idt_associacao]);
    $m2 = Membro::factory()->create(['idt_associacao' => $assoc->idt_associacao]);

    $orfao = Resumo::withoutGlobalScopes()->create([
        'idt_ofx' => $ofx->idt_ofx, 'idt_membro' => null, 'chv_pessoa' => 'cpf:888', 'nom_pessoa' => 'BELTRANO',
        'num_ano' => 2026, 'num_mes' => 11, 'nom_mes' => 'Nov', 'val_total' => 80.00, 'qtd_transacao' => 2, 'ind_pago' => true,
    ]);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => $m1->idt_membro, 'idt_resumo' => $orfao->idt_resumo, 'num_transacao' => 'V1', 'dat_transacao' => '2026-11-10', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);
    Transacao::withoutGlobalScopes()->create(['idt_ofx' => $ofx->idt_ofx, 'idt_membro' => $m2->idt_membro, 'idt_resumo' => $orfao->idt_resumo, 'num_transacao' => 'V2', 'dat_transacao' => '2026-11-11', 'tip_transacao' => 'CREDIT', 'val_transacao' => 40.00]);

    $metodo = new ReflectionMethod(OfxParserService::class, 'consolidarOrfaosSemMembro');
    $metodo->setAccessible(true);
    $metodo->invoke(app(OfxParserService::class), $ofx->fresh());

    expect(Resumo::withoutGlobalScopes()->find($orfao->idt_resumo))->not->toBeNull();
});
