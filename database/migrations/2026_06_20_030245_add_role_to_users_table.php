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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(\App\Enums\Perfil::MEMBRO->value)->after('email');
            $table->foreignId('idt_membro')->nullable()->after('role')->constrained('membros', 'idt_membro')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['idt_membro']);
            $table->dropColumn(['role', 'idt_membro']);
        });
    }
};
