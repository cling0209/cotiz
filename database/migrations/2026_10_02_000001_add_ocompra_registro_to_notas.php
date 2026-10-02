<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién y cuándo ingresó el código OC manual (notas.ocompra).
 * Los códigos existentes quedan sin usuario/fecha: no hay registro de su origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->string('ocompra_usuario', 50)->nullable()->after('ocompra');
            $table->timestampTz('ocompra_registrada_en')->nullable()->after('ocompra_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn(['ocompra_usuario', 'ocompra_registrada_en']);
        });
    }
};
