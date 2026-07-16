<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notasdetalle')) {
            return;
        }

        if (! Schema::hasColumn('notasdetalle', 'prod_descripcion_maestro')) {
            Schema::table('notasdetalle', function (Blueprint $table) {
                $table->string('prod_descripcion_maestro', 500)->nullable()->after('prod_descripcion_agile');
            });
        }

        // Líneas existentes: copiar descripción Agile como maestro editable inicial.
        DB::table('notasdetalle')
            ->whereNull('prod_descripcion_maestro')
            ->whereNotNull('prod_descripcion_agile')
            ->where('prod_descripcion_agile', '<>', '')
            ->update([
                'prod_descripcion_maestro' => DB::raw('prod_descripcion_agile'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('notasdetalle') && Schema::hasColumn('notasdetalle', 'prod_descripcion_maestro')) {
            Schema::table('notasdetalle', function (Blueprint $table) {
                $table->dropColumn('prod_descripcion_maestro');
            });
        }
    }
};
