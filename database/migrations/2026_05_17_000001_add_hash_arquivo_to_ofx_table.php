<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ofx', function (Blueprint $table) {
            // Assinatura SHA-256 do arquivo (hexdigest = 64 caracteres).
            // Nullable: registros legados nao possuem hash (Requisito 1.2).
            // Index simples (nao unique): a reimportacao nao e bloqueada;
            // o mesmo hash pode aparecer em mais de um registro e e apenas sinalizado.
            $table->string('hash_arquivo', 64)->nullable()->after('des_arquivo');
            $table->index('hash_arquivo');
        });
    }

    public function down(): void
    {
        Schema::table('ofx', function (Blueprint $table) {
            $table->dropIndex(['hash_arquivo']);
            $table->dropColumn('hash_arquivo');
        });
    }
};
