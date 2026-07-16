<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_tomadas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('sistema', 100)->nullable();
            $table->string('usuario', 100)->nullable();
            $table->timestamp('tomada_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_tomadas');
    }
};
