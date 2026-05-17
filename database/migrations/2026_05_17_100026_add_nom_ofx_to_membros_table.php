<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membros', function (Blueprint $table) {
            // Nome exato como aparece no MEMO do extrato OFX.
            // Quando preenchido, tem prioridade sobre nom_membro no matching.
            $table->string('nom_ofx')->nullable()->after('nom_membro');
        });
    }

    public function down(): void
    {
        Schema::table('membros', function (Blueprint $table) {
            $table->dropColumn('nom_ofx');
        });
    }
};
