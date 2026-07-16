<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_busqueda_corridas', function (Blueprint $table) {
            $table->id();
            $table->string('usuario', 50);
            $table->date('fecha_busqueda');
            $table->timestampTz('inicio');
            $table->timestampTz('fin')->nullable();
            $table->string('estado', 20)->default('running');
            $table->unsignedInteger('total_pasos')->default(0);
            $table->unsignedInteger('pasos_procesados')->default(0);
            $table->unsignedInteger('pasos_fallidos')->default(0);
            $table->unsignedInteger('oportunidades_encontradas')->default(0);
            $table->json('plan_json');
            $table->json('errores_json')->nullable();
            $table->text('mensaje')->nullable();
            $table->timestamps();

            $table->index(['estado', 'fecha_busqueda']);
            $table->index('inicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_busqueda_corridas');
    }
};
