<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated admin or director users can visit the dashboard', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('authenticated member users can visit the dashboard', function () {
    $user = User::factory()->membro()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('unapproved member sees pending approval alert on dashboard', function () {
    $user = User::factory()->membro()->create();
    // By default, the member created has ind_aprovado = false.
    expect($user->membro->ind_aprovado)->toBeFalse();

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Aprovação Pendente');
    $response->assertSee('Sua vinculação à associação');
    $response->assertSee('está aguardando aprovação');
});

test('approved member with pending payments sees debtor status on dashboard', function () {
    // Create an approved member
    $user = User::factory()->membro()->create();
    $membro = $user->membro;
    $membro->update(['ind_aprovado' => true]);

    // Create an OFX import and an unpaid Resumo for this member
    $ofx = \App\Models\Ofx::factory()->create(['idt_associacao' => $membro->idt_associacao]);
    \App\Models\Resumo::factory()->create([
        'idt_ofx' => $ofx->idt_ofx,
        'nom_pessoa' => $membro->nomeParaMatchingOfx(),
        'ind_pago' => false,
        'val_total' => 150.00,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Pendente de pagamento');
    $response->assertSee('Contribuições pendentes');
    $response->assertSee('R$ 150,00');
});
