<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maeprod_frases_busqueda', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('frase_norm')->constrained('users')->nullOnDelete();
        });

        Schema::create('maeprod_frase_busqueda_regiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('frase_busqueda_id')
                ->constrained('maeprod_frases_busqueda')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('region_codigo');
            $table->timestamps();

            $table->unique(['frase_busqueda_id', 'region_codigo']);
            $table->index('region_codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maeprod_frase_busqueda_regiones');

        Schema::table('maeprod_frases_busqueda', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
