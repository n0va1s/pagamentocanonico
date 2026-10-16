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
        Schema::create('associacoes', function (Blueprint $table) {
            $table->id('idt_associacao');
            $table->string('nom_associacao');
            $table->string('tel_contato')->nullable();
            $table->string('des_chave_pix')->nullable();
            $table->decimal('val_taxa', 10, 2)->nullable();
            $table->decimal('val_anual', 10, 2)->nullable();
            $table->date('dat_inicio_cobranca')->nullable()->default('2025-01-01');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('regras_classificacao', function (Blueprint $table) {
            $table->id('idt_regra');
            $table->foreignId('idt_associacao')->constrained('associacoes', 'idt_associacao')->onDelete('cascade');
            $table->string('nom_regra');
            $table->string('efeito');
            $table->boolean('ind_ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('regras_classificacao_criterios', function (Blueprint $table) {
            $table->id('idt_criterio');
            $table->foreignId('idt_regra')->constrained('regras_classificacao', 'idt_regra')->onDelete('cascade');
            $table->string('tip_match');
            $table->string('val_match');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regras_classificacao_criterios');
        Schema::dropIfExists('regras_classificacao');
        Schema::dropIfExists('associacoes');
    }
};
