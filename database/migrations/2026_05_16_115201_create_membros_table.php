<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membros', function (Blueprint $table) {
            $table->id('idt_membro');
            $table->string('nom_membro');
            $table->string('nom_ofx')->nullable(); // Nome exato como aparece no extrato OFX. Quando preenchido, tem prioridade sobre nom_membro no matching.
            $table->string('eml_membro')->unique();
            $table->string('tel_membro', 20)->nullable();
            $table->string('end_logradouro', 150)->nullable();       // Bairro/Complemento
            $table->string('end_mumero', 20)->nullable();         // Número/Apartamento
            $table->string('end_complemento', 150)->nullable();      // Rua/Avenida
            $table->string('tip_associado', 50)->default('membro'); // Tipo de associação
            $table->string('des_telegram_chat_id', 50)->nullable();
            $table->boolean('ind_notificar_whatsapp')->default(true);
            $table->boolean('ind_notificar_email')->default(true);
            $table->boolean('ind_notificar_telegram')->default(false);

            $table->timestamps();

            $table->index('tip_associado');
            $table->index('nom_membro');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membros');
    }
};
