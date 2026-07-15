<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_palabras_clave', function (Blueprint $table) {
            $table->id();
            $table->string('frase', 200);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('frase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_palabras_clave');
    }
};
