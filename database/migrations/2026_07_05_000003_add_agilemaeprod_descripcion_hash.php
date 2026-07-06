<?php

use App\Services\MaeprodBusquedaSimilitudService;
use App\Support\AgileDescripcion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agilemaeprod', function (Blueprint $table) {
            $table->string('descripcion_norm_hash', 32)->nullable()->unique();
            $table->string('prod_codigo_categoria_mp', 50)->nullable();
        });

        $this->rellenarHashesExistentes();
    }

    public function down(): void
    {
        Schema::table('agilemaeprod', function (Blueprint $table) {
            $table->dropColumn(['descripcion_norm_hash', 'prod_codigo_categoria_mp']);
        });
    }

    private function rellenarHashesExistentes(): void
    {
        if (! Schema::hasTable('agilemaeprod')) {
            return;
        }

        $busqueda = app(MaeprodBusquedaSimilitudService::class);
        $usados = [];

        $filas = DB::table('agilemaeprod')
            ->whereNull('descripcion_norm_hash')
            ->whereNotNull('prod_descripcion_agile')
            ->get(['prod_item_agile', 'prod_descripcion_agile']);

        foreach ($filas as $fila) {
            $desc = trim((string) $fila->prod_descripcion_agile);
            if ($desc === '') {
                continue;
            }

            $norm = $busqueda->normalizarTexto(AgileDescripcion::normalizar($desc));
            if ($norm === '') {
                continue;
            }

            $hash = md5($norm);
            if (isset($usados[$hash])) {
                continue;
            }

            $usados[$hash] = true;

            DB::table('agilemaeprod')
                ->where('prod_item_agile', $fila->prod_item_agile)
                ->update(['descripcion_norm_hash' => $hash]);
        }
    }
};
