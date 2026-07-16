<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumos', function (Blueprint $table) {
            $table->id('idt_resumo');
            $table->foreignId('idt_ofx')->constrained('ofx', 'idt_ofx')->onDelete('cascade');
            $table->foreignId('idt_membro')->nullable()->constrained('membros', 'idt_membro')->nullOnDelete();
            $table->integer('num_ano');
            $table->integer('num_mes'); // 1-12
            $table->string('nom_mes', 10); // Jan, Fev, Mar...
            $table->decimal('val_total', 15, 2)->default(0);
            $table->integer('qtd_transacao')->default(0);
            $table->boolean('ind_pago')->default(false);
            $table->timestamps();

            $table->unique(['idt_ofx', 'idt_membro', 'num_ano', 'num_mes']);
            $table->index(['idt_ofx', 'idt_membro']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resumos');
    }
};
