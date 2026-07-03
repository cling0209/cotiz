<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_mp_corrida_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corrida_id')->constrained('nota_mp_corridas')->cascadeOnDelete();
            $table->integer('nronota');
            $table->string('codigo_proceso', 40);
            $table->string('empresa', 200)->nullable();
            $table->boolean('exito')->default(false);
            $table->string('mensaje', 500)->nullable();
            $table->string('estado_mp_glosa', 120)->nullable();
            $table->string('resultado_propio', 20)->nullable();
            $table->string('rut_ganador', 12)->nullable();
            $table->string('razon_social_ganador', 200)->nullable();
            $table->boolean('cambio')->default(false);
            $table->timestamps();

            $table->unique(['corrida_id', 'nronota']);
            $table->index(['corrida_id', 'exito']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_mp_corrida_detalle');
    }
};
