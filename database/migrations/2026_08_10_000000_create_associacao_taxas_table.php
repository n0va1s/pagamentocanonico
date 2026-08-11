<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('associacao_taxas', function (Blueprint $table) {
            $table->id('idt_associacao_taxa');
            $table->foreignId('idt_associacao')->constrained('associacoes', 'idt_associacao')->cascadeOnDelete();
            $table->decimal('val_taxa', 15, 2)->default(0);
            $table->decimal('val_anual', 15, 2)->default(0);
            $table->date('dat_inicio');
            $table->date('dat_fim')->nullable();
            $table->timestamps();

            $table->index(['idt_associacao', 'dat_inicio', 'dat_fim']);
        });

        // Migra as taxas atuais cadastradas em associacoes para a tabela historica
        $associacoes = DB::table('associacoes')->get();
        foreach ($associacoes as $assoc) {
            if ($assoc->val_taxa !== null || $assoc->val_anual !== null) {
                DB::table('associacao_taxas')->insert([
                    'idt_associacao' => $assoc->idt_associacao,
                    'val_taxa' => $assoc->val_taxa ?? 0,
                    'val_anual' => $assoc->val_anual ?? 0,
                    'dat_inicio' => '2024-01-01',
                    'dat_fim' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('associacao_taxas');
    }
};
