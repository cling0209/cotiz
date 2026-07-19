<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correos_chile_dex_tarifas', function (Blueprint $table) {
            $table->id();
            $table->string('origen', 80);
            $table->string('destino', 120);
            $table->string('destino_key', 120);
            $table->unsignedTinyInteger('recargo_pct')->nullable();
            $table->json('tarifas');
            $table->string('archivo_origen', 255)->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['origen', 'destino_key']);
            $table->index('destino');
            $table->index('imported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correos_chile_dex_tarifas');
    }
};
