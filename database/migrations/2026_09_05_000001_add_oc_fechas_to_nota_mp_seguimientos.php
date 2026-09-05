<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->timestampTz('oc_fecha_envio')->nullable()->after('id_orden_compra');
            $table->timestampTz('oc_fecha_creacion')->nullable()->after('oc_fecha_envio');
            $table->timestampTz('oc_fecha_aceptacion')->nullable()->after('oc_fecha_creacion');
            $table->string('oc_estado', 60)->nullable()->after('oc_fecha_aceptacion');
        });
    }

    public function down(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->dropColumn([
                'oc_fecha_envio',
                'oc_fecha_creacion',
                'oc_fecha_aceptacion',
                'oc_estado',
            ]);
        });
    }
};
