<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de verificación del código OC de MP contra la cotización (para no volver a consultarla).
 *
 * Además separa el origen de los códigos copiados por 2026_09_30_000001:
 * - Aceptadas (botón «Aceptar»): el código es manual; se quita la copia de ocompra_mp.
 * - No aceptadas: el código lo escribió el proceso automático; queda solo en ocompra_mp
 *   (se vacía notas.ocompra) para que el proceso lo verifique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->string('ocompra_verificada_codigo', 20)->nullable()->after('ocompra_mp_resuelta_en');
            $table->string('ocompra_verificacion', 20)->nullable()->after('ocompra_verificada_codigo');
            $table->timestampTz('ocompra_verificada_en')->nullable()->after('ocompra_verificacion');
        });

        DB::statement(<<<'SQL'
            UPDATE nota_mp_seguimientos AS seg
            SET ocompra_mp = NULL, ocompra_mp_resuelta_en = NULL
            FROM notas AS n
            WHERE n.nronota = seg.nronota
              AND lower(trim(coalesce(n.estado, ''))) = 'aceptada'
              AND seg.ocompra_mp = upper(trim(n.ocompra))
              AND seg.ocompra_mp_resuelta_en IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE notas AS n
            SET ocompra = ''
            FROM nota_mp_seguimientos AS seg
            WHERE seg.nronota = n.nronota
              AND lower(trim(coalesce(n.estado, ''))) <> 'aceptada'
              AND trim(coalesce(n.ocompra, '')) <> ''
              AND seg.ocompra_mp = upper(trim(n.ocompra))
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE notas AS n
            SET ocompra = seg.ocompra_mp
            FROM nota_mp_seguimientos AS seg
            WHERE seg.nronota = n.nronota
              AND lower(trim(coalesce(n.estado, ''))) <> 'aceptada'
              AND trim(coalesce(n.ocompra, '')) = ''
              AND trim(coalesce(seg.ocompra_mp, '')) <> ''
        SQL);

        DB::statement(<<<'SQL'
            UPDATE nota_mp_seguimientos AS seg
            SET ocompra_mp = upper(trim(n.ocompra))
            FROM notas AS n
            WHERE n.nronota = seg.nronota
              AND lower(trim(coalesce(n.estado, ''))) = 'aceptada'
              AND trim(coalesce(n.ocompra, '')) <> ''
              AND seg.ocompra_mp IS NULL
        SQL);

        Schema::table('nota_mp_seguimientos', function (Blueprint $table) {
            $table->dropColumn(['ocompra_verificada_codigo', 'ocompra_verificacion', 'ocompra_verificada_en']);
        });
    }
};
