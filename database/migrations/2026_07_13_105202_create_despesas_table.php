<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('despesas', function (Blueprint $table) {
            $table->id('idt_despesa');
            $table->foreignId('idt_transacao')->constrained('transacoes', 'idt_transacao')->cascadeOnDelete();
            $table->string('arq_despesa_1'); // Required
            $table->string('arq_despesa_2')->nullable(); // Optional
            $table->string('arq_despesa_3')->nullable(); // Optional
            $table->string('arq_pagamento')->nullable(); // Optional (Comprovante de pagamento)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('despesas');
    }
};
