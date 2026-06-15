<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bases legacy o importadas pueden tener varchar(50) en prod_nombre;
     * el import Excel y la migración inicial usan 255.
     */
    public function up(): void
    {
        if (! Schema::hasTable('maeprod') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = [
            'prod_nombre' => 255,
            'prod_imagen' => 255,
            'prod_gramaje' => 120,
            'prod_familia' => 120,
            'prod_item_softland' => 50,
            'prod_user_upd' => 50,
        ];

        foreach ($columns as $column => $length) {
            $this->widenVarchar('maeprod', $column, $length);
        }
    }

    public function down(): void
    {
        // No revertir: podría truncar datos ya importados.
    }

    private function widenVarchar(string $table, string $column, int $length): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s TYPE varchar(%d)',
            $table,
            $column,
            $length,
        ));
    }
};
