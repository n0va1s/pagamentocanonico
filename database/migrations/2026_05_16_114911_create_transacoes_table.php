<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transacoes', function (Blueprint $table) {
            $table->id('idt_transacao');
            $table->foreignId('idt_ofx')->constrained()->onDelete('cascade');
            $table->string('num_transacao')->nullable(); // ID único da transação no OFX
            $table->date('dat_transacao'); // DTPOSTED
            $table->string('tip_transacao', 20)->nullable(); // TRNTYPE (DEBIT/CREDIT)
            $table->decimal('val_transacao', 15, 2); // TRNAMT
            $table->string('des_transacao')->nullable(); // MEMO (nome/descrição)
            $table->string('num_check')->nullable(); // CHECKNUM
            $table->string('nom_pessoa')->nullable(); // NAME (se disponível)
            $table->timestamps();

            $table->index(['idt_ofx', 'dat_transacao']);
            $table->index('des_transacao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transacoes');
    }
};
