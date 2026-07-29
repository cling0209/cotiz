<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_palabra_clave_regiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('palabra_clave_id')
                ->constrained('oportunidad_palabras_clave')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('region_codigo');
            $table->timestamps();

            $table->unique(['palabra_clave_id', 'region_codigo']);
            $table->index('region_codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_palabra_clave_regiones');
    }
};
