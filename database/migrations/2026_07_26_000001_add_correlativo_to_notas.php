<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDICE = 'notas_encargado_correlativo_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('notas', 'correlativo')) {
            Schema::table('notas', function (Blueprint $table) {
                $table->smallInteger('correlativo')->default(1)->after('encargado');
            });
        }

        // Los códigos MP repetidos del histórico se numeran por orden de creación
        // para que el índice único pueda crearse sin perder ninguna cotización.
        DB::statement(<<<'SQL'
            with numeradas as (
                select nronota,
                       row_number() over (
                           partition by upper(btrim(encargado))
                           order by nronota
                       ) as rn
                  from notas
                 where btrim(coalesce(encargado, '')) <> ''
            )
            update notas n
               set correlativo = numeradas.rn
              from numeradas
             where numeradas.nronota = n.nronota
               and numeradas.rn > 1
        SQL);

        // Parcial: toda cotización nace con encargado vacío y conviven varias así.
        DB::statement(sprintf(
            <<<'SQL'
                create unique index if not exists %s
                    on notas (upper(btrim(encargado)), correlativo)
                 where btrim(coalesce(encargado, '')) <> ''
            SQL,
            self::INDICE,
        ));
    }

    public function down(): void
    {
        DB::statement('drop index if exists '.self::INDICE);

        if (Schema::hasColumn('notas', 'correlativo')) {
            Schema::table('notas', function (Blueprint $table) {
                $table->dropColumn('correlativo');
            });
        }
    }
};
