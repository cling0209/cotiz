<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Código OC (AG) resuelto desde Mercado Público, separado de notas.ocompra (manual).
 * Copia los códigos existentes para revalidarlos con compra-agil:revalidar-ocompra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->string('ocompra_mp', 20)->nullable()->after('id_orden_compra');
            $table->timestampTz('ocompra_mp_resuelta_en')->nullable()->after('ocompra_mp');
        });

        DB::statement(<<<'SQL'
            UPDATE nota_mp_seguimientos AS seg
            SET ocompra_mp = upper(trim(n.ocompra))
            FROM notas AS n
            WHERE n.nronota = seg.nronota
              AND trim(coalesce(n.ocompra, '')) <> ''
              AND seg.ocompra_mp IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->dropColumn(['ocompra_mp', 'ocompra_mp_resuelta_en']);
        });
    }
};
