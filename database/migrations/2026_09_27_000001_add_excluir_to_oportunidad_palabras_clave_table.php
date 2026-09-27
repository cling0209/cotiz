<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_palabras_clave', function (Blueprint $table) {
            $table->text('excluir')->nullable()->after('frase');
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad_palabras_clave', function (Blueprint $table) {
            $table->dropColumn('excluir');
        });
    }
};
