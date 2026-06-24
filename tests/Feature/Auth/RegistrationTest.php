<?php

use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $assoc = \App\Models\Associacao::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'idt_associacao' => $assoc->idt_associacao,
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'role' => \App\Enums\Perfil::MEMBRO->value,
    ]);

    $this->assertDatabaseHas('membros', [
        'eml_membro' => 'test@example.com',
        'idt_associacao' => $assoc->idt_associacao,
        'ind_aprovado' => false,
        'usu_autorizador' => null,
    ]);
});
