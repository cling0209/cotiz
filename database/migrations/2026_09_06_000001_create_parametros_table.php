<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametros', function (Blueprint $table) {
            $table->string('clave', 80)->primary();
            $table->string('nombre', 160);
            $table->text('valor')->nullable();
            $table->string('descripcion', 500)->nullable();
            $table->timestamps();
        });

        $pagoDefault = '10000';
        $now = now();

        DB::table('parametros')->insert([
            'clave' => 'pago_cotizacion_realizada',
            'nombre' => 'Valor por cotización realizada',
            'valor' => $pagoDefault,
            'descripcion' => 'Monto fijo (pago) por cada cotización en el reporte de Comisiones.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros');
    }
};
