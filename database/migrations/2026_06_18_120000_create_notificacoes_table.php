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
        Schema::create('mensagem', function (Blueprint $table) {
            $table->id('idt_mensagem');
            $table->unsignedBigInteger('usu_inclusao')->nullable();
            $table->string('nom_campanha', 150);
            $table->text('txt_mensagem');
            $table->char('tip_destinatario', 1)->default('T'); // A - Adimplentes, I - Inadimplentes, T - Todos
            $table->integer('qtd_impactados')->default(0);
            $table->timestamps();
        });

        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id('idt_notificacao');
            $table->foreignId('idt_membro')
                  ->constrained('membros', 'idt_membro')
                  ->onDelete('cascade');
            $table->foreignId('idt_mensagem')
                  ->nullable()
                  ->constrained('mensagem', 'idt_mensagem')
                  ->onDelete('cascade');
            $table->string('tip_canal', 50);
            $table->text('txt_conteudo');
            $table->boolean('ind_enviada')->default(false);
            $table->string('num_externo', 255)->nullable();
            $table->text('msg_erro')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
        Schema::dropIfExists('mensagem');
    }
};
