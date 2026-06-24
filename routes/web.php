<?php

use App\Http\Controllers\OfxUploadController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    
    Volt::route('/dashboard', 'pages.dashboard')->name('dashboard');

    Route::middleware('role:admin,diretor')->group(function () {
        
        Volt::route('/aprovacoes', 'pages.aprovacoes')->name('aprovacoes');

        Volt::route('/mensagens', 'mensagens.index')->name('mensagens.index');
        Volt::route('/mensagens/criar', 'mensagens.create')->name('mensagens.create');
        Volt::route('/mensagens/{mensagem}', 'mensagens.show')->name('mensagens.show');

        Route::get('/upload', [OfxUploadController::class, 'show'])->name('upload');
        Route::post('/upload', [OfxUploadController::class, 'store'])->name('upload.store');

        Volt::route('/membros', 'pages.membros.index')->name('membros.index');
        Volt::route('/membros/novo', 'pages.membros.create')->name('membros.create');
        Volt::route('/membros/{membro}/editar', 'pages.membros.edit')->name('membros.edit');

        Route::middleware('role:admin')->group(function () {
            Volt::route('/associacoes', 'pages.associacoes')->name('associacoes.index');
        });
    });

});

require __DIR__.'/settings.php';
