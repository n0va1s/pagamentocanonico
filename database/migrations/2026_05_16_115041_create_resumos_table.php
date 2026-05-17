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
            $table->foreignId('idt_ofx')->constrained()->onDelete('cascade');
            $table->string('nom_pessoa'); // Nome extraído do MEMO
            $table->integer('num_ano');
            $table->integer('num_mes'); // 1-12
            $table->string('nom_mes', 10); // Jan, Fev, Mar...
            $table->decimal('val_total', 15, 2)->default(0);
            $table->integer('num_transacao')->default(0);
            $table->boolean('ind_pago')->default(false);
            $table->timestamps();

            $table->unique(['idt_ofx', 'nom_pessoa', 'num_ano', 'num_mes']);
            $table->index(['idt_ofx', 'nom_pessoa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resumos');
    }
};
