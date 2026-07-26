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

        $this->numerarHistorico();

        // Parcial: toda cotización nace con encargado vacío y conviven varias así.
        DB::statement(sprintf(
            <<<'SQL'
                create unique index if not exists %s
                    on notas (upper(%s(encargado)), correlativo)
                 where %s(coalesce(encargado, '')) <> ''
            SQL,
            self::INDICE,
            $this->funcionTrim(),
            $this->funcionTrim(),
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

    /**
     * Los códigos MP repetidos del histórico se numeran por orden de creación para
     * que el índice único pueda crearse sin perder ninguna cotización.
     *
     * Va en PHP y no en SQL porque `update ... from` y `btrim` son de PostgreSQL,
     * y las pruebas corren sobre SQLite.
     */
    private function numerarHistorico(): void
    {
        $vistos = [];

        DB::table('notas')
            ->select('nronota', 'encargado')
            ->orderBy('nronota')
            ->chunk(1000, function ($notas) use (&$vistos) {
                foreach ($notas as $nota) {
                    $codigo = strtoupper(trim((string) $nota->encargado));
                    if ($codigo === '') {
                        continue;
                    }

                    $correlativo = ($vistos[$codigo] ?? 0) + 1;
                    $vistos[$codigo] = $correlativo;

                    if ($correlativo === 1) {
                        continue;
                    }

                    DB::table('notas')
                        ->where('nronota', $nota->nronota)
                        ->update(['correlativo' => $correlativo]);
                }
            });
    }

    private function funcionTrim(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'btrim' : 'trim';
    }
};
