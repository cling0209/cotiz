<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de envío OC manual: solo día (sin hora). MP sigue con timestamp en seguimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notas', 'fecha_envio_oc')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notas ALTER COLUMN fecha_envio_oc TYPE date USING (fecha_envio_oc::date)');
        } elseif ($driver === 'sqlite') {
            // Tests: columna ya acepta fechas sin hora en el cast del modelo.
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('notas', 'fecha_envio_oc')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notas ALTER COLUMN fecha_envio_oc TYPE timestamp(0) with time zone USING (fecha_envio_oc::timestamp with time zone)');
        }
    }
};
