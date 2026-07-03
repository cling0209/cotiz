<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->unsignedInteger('total_notas')->default(0)->after('notas_con_cambio');
            $table->integer('nronota_actual')->nullable()->after('total_notas');
            $table->string('codigo_actual', 40)->nullable()->after('nronota_actual');
            $table->json('pendientes_json')->nullable()->after('codigo_actual');
        });
    }

    public function down(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->dropColumn(['total_notas', 'nronota_actual', 'codigo_actual', 'pendientes_json']);
        });
    }
};
