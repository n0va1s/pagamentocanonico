<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Limpa as tabelas legadas do módulo de notificações anterior se existirem
        Schema::dropIfExists('notificacoes');
        Schema::dropIfExists('mensagem');

        Schema::create('mensagem', function (Blueprint $table) {
            $table->id('idt_mensagem');
            $table->foreignId('idt_associacao')
                ->constrained('associacoes', 'idt_associacao')
                ->onDelete('cascade');
            $table->foreignId('usu_inclusao')
                ->constrained('users', 'id')
                ->onDelete('cascade');
            $table->string('nom_campanha', 150);
            $table->text('txt_mensagem');
            $table->char('tip_destinatario', 1); // A - Todos os Associados, D - Adimplentes, I - Inadimplentes
            $table->integer('qtd_impactados')->default(0);
            $table->timestamps();
        });

        Schema::create('mensagem_envio', function (Blueprint $table) {
            $table->id('idt_mensagem_envio');
            $table->foreignId('idt_mensagem')
                ->constrained('mensagem', 'idt_mensagem')
                ->onDelete('cascade');
            $table->string('nom_destinatario', 150);
            $table->string('tel_destinatario', 20);
            $table->string('nom_responsavel', 150)->nullable();
            $table->boolean('ind_enviado')->default(false);
            $table->timestamp('dat_envio')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagem_envio');
        Schema::dropIfExists('mensagem');
    }
};
