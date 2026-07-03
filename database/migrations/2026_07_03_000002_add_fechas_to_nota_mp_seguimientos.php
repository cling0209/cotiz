<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->timestampTz('fecha_publicacion')->nullable()->after('organismo');
            $table->timestampTz('fecha_cierre')->nullable()->after('fecha_publicacion');
            $table->timestampTz('fecha_ultimo_cambio')->nullable()->after('fecha_cierre');
            $table->timestampTz('fecha_cancelacion')->nullable()->after('fecha_ultimo_cambio');
        });
    }

    public function down(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_publicacion',
                'fecha_cierre',
                'fecha_ultimo_cambio',
                'fecha_cancelacion',
            ]);
        });
    }
};
