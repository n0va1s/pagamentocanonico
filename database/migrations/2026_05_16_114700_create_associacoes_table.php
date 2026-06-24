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
             $table->decimal('val_taxa', 10, 2)->nullable()->after('des_chave_pix');
            $table->decimal('val_anual', 10, 2)->nullable()->after('val_taxa');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('associacoes');
    }
};
