<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_agil_procesos', function (Blueprint $table) {
            $table->string('codigo', 40)->primary();
            $table->string('nombre', 500)->nullable();
            $table->string('estado_codigo', 40)->nullable();
            $table->string('estado_glosa', 120)->nullable();
            $table->string('organismo', 200)->nullable();
            $table->string('rut_organismo', 12)->nullable()->index();
            $table->unsignedTinyInteger('region')->nullable()->index();
            $table->bigInteger('monto_presupuesto_clp')->nullable();
            $table->timestampTz('fecha_publicacion')->nullable();
            $table->timestampTz('fecha_cierre')->nullable();
            $table->timestampTz('fecha_ultimo_cambio')->nullable()->index();
            $table->unsignedSmallInteger('cantidad_productos')->default(0);
            $table->unsignedSmallInteger('total_ofertas')->default(0);
            $table->string('rut_ganador', 12)->nullable()->index();
            $table->timestampTz('sincronizado_en')->nullable();
            $table->timestamps();
        });

        Schema::create('compra_agil_lineas_mercado', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proceso', 40)->index();
            $table->string('codigo_producto_mp', 50)->nullable()->index();
            $table->string('nombre_producto', 500)->nullable();
            $table->decimal('cantidad', 14, 4)->nullable();
            $table->string('unidad_medida', 20)->nullable();
            $table->bigInteger('precio_ganador_unitario')->nullable();
            $table->string('prod_item', 50)->nullable()->index();
            $table->timestampTz('fecha_proceso')->nullable()->index();
            $table->timestamps();

            $table->foreign('codigo_proceso')
                ->references('codigo')
                ->on('compra_agil_procesos')
                ->cascadeOnDelete();
        });

        Schema::create('compra_agil_benchmarks', function (Blueprint $table) {
            $table->string('prod_item', 50)->primary();
            $table->unsignedInteger('observaciones')->default(0);
            $table->bigInteger('precio_mercado_mediana')->nullable();
            $table->bigInteger('precio_mercado_min')->nullable();
            $table->bigInteger('precio_mercado_max')->nullable();
            $table->timestampTz('ultima_observacion')->nullable();
            $table->timestamps();
        });

        Schema::create('compra_agil_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('inicio');
            $table->timestampTz('fin')->nullable();
            $table->unsignedInteger('listados')->default(0);
            $table->unsignedInteger('detalles')->default(0);
            $table->unsignedInteger('procesos_nuevos')->default(0);
            $table->string('estado', 20)->default('ok');
            $table->text('mensaje')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_agil_sync_logs');
        Schema::dropIfExists('compra_agil_benchmarks');
        Schema::dropIfExists('compra_agil_lineas_mercado');
        Schema::dropIfExists('compra_agil_procesos');
    }
};
