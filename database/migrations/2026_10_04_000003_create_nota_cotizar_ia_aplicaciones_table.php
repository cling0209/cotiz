<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_cotizar_ia_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nronota');
            $table->string('codigo', 40);
            $table->string('usuario', 50);
            $table->unsignedInteger('lineas_agregadas')->default(0);
            $table->timestamp('aplicado_at');
            $table->timestamps();

            $table->index('nronota');
            $table->index('codigo');
            $table->index('aplicado_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_cotizar_ia_aplicaciones');
    }
};
