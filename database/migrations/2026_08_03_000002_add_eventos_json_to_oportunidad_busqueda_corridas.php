<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_busqueda_corridas', function (Blueprint $table) {
            $table->json('eventos_json')->nullable()->after('errores_json');
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad_busqueda_corridas', function (Blueprint $table) {
            $table->dropColumn('eventos_json');
        });
    }
};
