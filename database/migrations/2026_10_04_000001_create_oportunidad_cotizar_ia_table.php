<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_cotizar_ia', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->unsignedInteger('veces')->default(0);
            $table->timestamp('ultimo_uso_at')->nullable();
            $table->timestamps();

            $table->index('codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_cotizar_ia');
    }
};
