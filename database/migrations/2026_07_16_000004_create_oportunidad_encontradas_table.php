<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_encontradas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40);
            $table->string('nombre', 500)->nullable();
            $table->string('organismo', 500)->nullable();
            $table->string('rut_organismo', 20)->nullable();
            $table->unsignedTinyInteger('region')->nullable();
            $table->string('nombre_region', 100)->nullable();
            $table->string('comuna', 120)->nullable();
            $table->unsignedBigInteger('monto_presupuesto_clp')->nullable();
            $table->string('moneda', 10)->nullable();
            $table->timestampTz('fecha_publicacion')->nullable();
            $table->timestampTz('fecha_cierre')->nullable();
            $table->string('estado_codigo', 40)->nullable();
            $table->string('estado_glosa', 120)->nullable();
            $table->json('palabras_coinciden')->nullable();
            $table->date('fecha_busqueda');
            $table->unsignedSmallInteger('indice_region_config')->default(999);
            $table->foreignId('found_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['codigo', 'fecha_busqueda']);
            $table->index(['fecha_busqueda', 'indice_region_config']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_encontradas');
    }
};
