<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->unsignedSmallInteger('convocatoria_estado')->nullable()->after('fecha_cancelacion');
            $table->string('convocatoria_descripcion', 120)->nullable()->after('convocatoria_estado');
            $table->timestampTz('fecha_cierre_primer_llamado')->nullable()->after('convocatoria_descripcion');
            $table->timestampTz('fecha_cierre_segundo_llamado')->nullable()->after('fecha_cierre_primer_llamado');
        });
    }

    public function down(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->dropColumn([
                'convocatoria_estado',
                'convocatoria_descripcion',
                'fecha_cierre_primer_llamado',
                'fecha_cierre_segundo_llamado',
            ]);
        });
    }
};
