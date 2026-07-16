<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Volt::route('/dashboard', 'pages.dashboard')->name('dashboard');

    Route::middleware('role:admin,diretor')->group(function () {

        Volt::route('/aprovacoes', 'pages.aprovacoes')->name('aprovacoes');
        Volt::route('/orcamentos', 'pages.orcamentos.index')->name('orcamentos');

        Volt::route('/mensagens', 'mensagens.index')->name('mensagens.index');
        Volt::route('/mensagens/criar', 'mensagens.create')->name('mensagens.create');
        Volt::route('/mensagens/{mensagem}', 'mensagens.show')->name('mensagens.show');

        Volt::route('/upload', 'pages.ofx.upload')->name('upload');

        Volt::route('/membros', 'pages.membros.index')->name('membros.index');
        Volt::route('/membros/novo', 'pages.membros.create')->name('membros.create');
        Volt::route('/membros/{membro}/editar', 'pages.membros.edit')->name('membros.edit');

        Route::middleware('role:admin')->group(function () {
            Volt::route('/associacoes', 'pages.associacoes.index')->name('associacoes.index');
            Volt::route('/associacoes/nova', 'pages.associacoes.create')->name('associacoes.create');
            Volt::route('/associacoes/{associacao}/editar', 'pages.associacoes.edit')->name('associacoes.edit');
        });
    });

});

require __DIR__.'/settings.php';

Route::get('/limpar-tudo', function () {
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');

    return 'Todos os caches foram limpos com sucesso!';
});

Route::get('/otimizar', function () {
    Artisan::call('optimize');

    return 'Aplicação otimizada com sucesso! (Configurações e rotas foram cacheadas)';
});

Route::get('/storage-link', function () {
    Artisan::call('storage:link');

    return 'Link simbólico do storage criado com sucesso!';
});
