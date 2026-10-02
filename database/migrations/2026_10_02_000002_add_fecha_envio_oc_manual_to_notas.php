<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de envío OC ingresada al aceptar la cotización (manual).
 * Si está vacía, la fecha efectiva puede ser nota_mp_seguimientos.oc_fecha_envio (API MP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->timestampTz('fecha_envio_oc')->nullable()->after('ocompra_registrada_en');
            $table->string('fecha_envio_oc_usuario', 50)->nullable()->after('fecha_envio_oc');
            $table->timestampTz('fecha_envio_oc_registrada_en')->nullable()->after('fecha_envio_oc_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn(['fecha_envio_oc', 'fecha_envio_oc_usuario', 'fecha_envio_oc_registrada_en']);
        });
    }
};
