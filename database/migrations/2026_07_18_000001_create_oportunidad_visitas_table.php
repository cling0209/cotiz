<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_visitas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->unsignedInteger('veces')->default(0);
            $table->timestamp('ultima_visita_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'codigo']);
            $table->index('codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_visitas');
    }
};
