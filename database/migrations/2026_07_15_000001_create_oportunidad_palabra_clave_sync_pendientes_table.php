<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_palabra_clave_sync_pendientes', function (Blueprint $table) {
            $table->id();
            $table->string('accion', 20); // graba | elimina
            $table->string('frase', 200);
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->timestamps();

            $table->unique('frase');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_palabra_clave_sync_pendientes');
    }
};
