<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\OfxUploadController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    // Rotas de Gestão (Apenas Administradores e Diretores)
    Route::middleware('role:admin,diretor')->group(function () {
        // Dashboard
        Volt::route('/dashboard', 'pages.dashboard')->name('dashboard');

        // Mensagens
        Volt::route('/mensagens', 'pages.mensagens')->name('mensagens');

        // OFX
        Route::get('/upload', [OfxUploadController::class, 'show'])->name('upload');
        Route::post('/upload', [OfxUploadController::class, 'store'])->name('upload.store');

        // Membros — 100% Volt
        Volt::route('/membros', 'pages.membros.index')->name('membros.index');
        Volt::route('/membros/novo', 'pages.membros.create')->name('membros.create');
        Volt::route('/membros/{membro}/editar', 'pages.membros.edit')->name('membros.edit');

        // Aliases para evitar erros com referências layout/dashboard antiga (members.*)
        Volt::route('/membros-alias', 'pages.membros.index')->name('members.index');
        Volt::route('/membros-novo-alias', 'pages.membros.create')->name('members.create');
        Volt::route('/membros-editar-alias/{membro}', 'pages.membros.edit')->name('members.edit');

        // Notificações
        Route::post('/notificacoes/testar', [NotificacaoController::class, 'testar'])->name('notificacoes.testar');

        // Contatos Recebidos
        Volt::route('/contato', 'pages.contato')->name('contato');
    });

    // Rotas de Associados (Acessíveis a todos os perfis autenticados)
    // Minha Associação
    Volt::route('/minha-associacao', 'pages.minha-associacao')->name('minha-associacao');

});

require __DIR__.'/settings.php';
