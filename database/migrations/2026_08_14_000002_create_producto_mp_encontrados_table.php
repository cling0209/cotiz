<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_mp_encontrados', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40);
            $table->string('nombre_ca', 500)->nullable();
            $table->string('organismo', 200)->nullable();
            $table->unsignedSmallInteger('region')->nullable();
            $table->string('nombre_region', 80)->nullable();
            $table->string('codigo_producto_mp', 80)->nullable();
            $table->string('descripcion_mp', 500);
            $table->string('prod_item', 50);
            $table->string('prod_nombre', 200)->nullable();
            $table->string('frase', 200);
            $table->string('frase_norm', 200);
            $table->string('origen_detalle', 20)->default('mp');
            $table->timestampTz('fecha_publicacion')->nullable();
            $table->timestampTz('fecha_cierre')->nullable();
            $table->date('fecha_busqueda');
            $table->timestamps();

            $table->unique(
                ['codigo', 'codigo_producto_mp', 'prod_item', 'frase_norm'],
                'producto_mp_encontrados_match_uidx',
            );
            $table->index(['fecha_busqueda', 'prod_item']);
            $table->index('codigo');
            $table->index('frase_norm');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_mp_encontrados');
    }
};
