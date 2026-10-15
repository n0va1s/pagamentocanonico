<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resumos', function (Blueprint $table) {
            // Rotulo humano exibivel no relatorio (MEMO limpo). Apenas exibicao.
            $table->string('nom_pessoa')->nullable()->after('idt_membro');
            // Chave de idempotencia normalizada para pessoas sem membro vinculado.
            $table->string('chv_pessoa', 191)->nullable()->after('nom_pessoa');
            // Index composto para agrupar/consultar resumos sem-membro por periodo.
            $table->index(['idt_ofx', 'chv_pessoa', 'num_ano', 'num_mes']);
        });
    }

    public function down(): void
    {
        Schema::table('resumos', function (Blueprint $table) {
            $table->dropIndex(['idt_ofx', 'chv_pessoa', 'num_ano', 'num_mes']);
            $table->dropColumn(['nom_pessoa', 'chv_pessoa']);
        });
    }
};
