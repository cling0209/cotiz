<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_oferta_lineas', function (Blueprint $table) {
            $table->index(['oferta_id', 'id'], 'nota_mp_oferta_lineas_oferta_pos_idx');
        });

        Schema::table('notasdetalle', function (Blueprint $table) {
            $table->index('prod_item', 'notasdetalle_prod_item_idx');
        });

        $fecha = 'COALESCE(fecha_cierre, fecha_cierre_segundo_llamado, fecha_cierre_primer_llamado)';
        DB::statement("CREATE INDEX IF NOT EXISTS nota_mp_seguimientos_cierre_efectivo_idx ON nota_mp_seguimientos (({$fecha}))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS nota_mp_seguimientos_cierre_efectivo_idx');

        Schema::table('notasdetalle', function (Blueprint $table) {
            $table->dropIndex('notasdetalle_prod_item_idx');
        });

        Schema::table('nota_mp_oferta_lineas', function (Blueprint $table) {
            $table->dropIndex('nota_mp_oferta_lineas_oferta_pos_idx');
        });
    }
};
