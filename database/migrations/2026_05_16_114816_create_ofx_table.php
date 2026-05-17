<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofx', function (Blueprint $table) {
            $table->id('idt_ofx');
            $table->string('des_arquivo');
            $table->string('cod_banco')->nullable(); // Código do banco
            $table->string('num_conta')->nullable(); // Número da conta
            $table->date('dat_inicio')->nullable(); // DTSTART
            $table->date('dat_fim')->nullable(); // DTEND
            $table->integer('qtd_transacao')->default(0);
            $table->decimal('val_total', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ofx');
    }
};
