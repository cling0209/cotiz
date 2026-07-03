<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_mp_corridas', function (Blueprint $table) {
            $table->id();
            $table->string('usuario', 50);
            $table->timestampTz('inicio');
            $table->timestampTz('fin')->nullable();
            $table->unsignedInteger('notas_procesadas')->default(0);
            $table->unsignedInteger('notas_con_cambio')->default(0);
            $table->string('estado', 20)->default('running');
            $table->text('mensaje')->nullable();
            $table->timestamps();

            $table->index(['inicio']);
        });

        Schema::create('nota_mp_seguimientos', function (Blueprint $table) {
            $table->integer('nronota')->primary();
            $table->string('codigo_proceso', 40)->index();
            $table->string('estado_mp_codigo', 40)->nullable();
            $table->string('estado_mp_glosa', 120)->nullable();
            $table->string('organismo', 200)->nullable();
            $table->string('rut_ganador', 12)->nullable()->index();
            $table->string('razon_social_ganador', 200)->nullable();
            $table->unsignedBigInteger('id_orden_compra')->nullable();
            $table->bigInteger('monto_total_ganador')->nullable();
            $table->string('resultado_propio', 20)->nullable();
            $table->boolean('finalizado')->default(false)->index();
            $table->string('ultimo_usuario', 50)->nullable();
            $table->timestampTz('ultimo_consultado_en')->nullable();
            $table->foreignId('ultima_corrida_id')->nullable()->constrained('nota_mp_corridas')->nullOnDelete();
            $table->timestamps();

            $table->foreign('nronota')->references('nronota')->on('notas')->cascadeOnDelete();
        });

        Schema::create('nota_mp_ofertas', function (Blueprint $table) {
            $table->id();
            $table->integer('nronota')->index();
            $table->unsignedBigInteger('id_cotizacion_mp')->nullable();
            $table->string('rut_proveedor', 15)->nullable();
            $table->string('razon_social', 200)->nullable();
            $table->boolean('proveedor_seleccionado')->default(false);
            $table->bigInteger('monto_total')->nullable();
            $table->boolean('es_propio')->default(false);
            $table->boolean('inadmisible')->default(false);
            $table->unsignedBigInteger('id_oc')->nullable();
            $table->timestamps();

            $table->foreign('nronota')->references('nronota')->on('nota_mp_seguimientos')->cascadeOnDelete();
        });

        Schema::create('nota_mp_oferta_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_id')->constrained('nota_mp_ofertas')->cascadeOnDelete();
            $table->string('codigo_producto', 50)->nullable();
            $table->string('nombre_producto', 500)->nullable();
            $table->string('descripcion', 500)->nullable();
            $table->decimal('cantidad', 14, 4)->nullable();
            $table->bigInteger('precio_unitario')->nullable();
            $table->bigInteger('monto_total')->nullable();
            $table->timestamps();
        });

        Schema::create('nota_mp_corrida_cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corrida_id')->constrained('nota_mp_corridas')->cascadeOnDelete();
            $table->integer('nronota');
            $table->string('codigo_proceso', 40);
            $table->string('estado_anterior', 40)->nullable();
            $table->string('estado_nuevo', 40)->nullable();
            $table->string('resultado_propio', 20)->nullable();
            $table->string('rut_ganador', 12)->nullable();
            $table->string('razon_social_ganador', 200)->nullable();
            $table->timestamps();

            $table->index(['corrida_id', 'nronota']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_mp_corrida_cambios');
        Schema::dropIfExists('nota_mp_oferta_lineas');
        Schema::dropIfExists('nota_mp_ofertas');
        Schema::dropIfExists('nota_mp_seguimientos');
        Schema::dropIfExists('nota_mp_corridas');
    }
};
