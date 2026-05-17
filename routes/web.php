<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\OfxUploadController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // OFX
    Route::get('/upload', [OfxUploadController::class, 'show'])->name('upload');
    Route::post('/upload', [OfxUploadController::class, 'store'])->name('upload.store');

    // Membros — 100% Volt, sem controller
    Volt::route('/membros', 'pages.membros.index')->name('membros.index');
    Volt::route('/membros/novo', 'pages.membros.create')->name('membros.create');
    Volt::route('/membros/{membro}/editar', 'pages.membros.edit')->name('membros.edit');

    // Notificações
    Route::post('/notificacoes/testar', [NotificacaoController::class, 'testar'])->name('notificacoes.testar');

});

require __DIR__.'/settings.php';
