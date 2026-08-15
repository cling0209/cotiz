<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maeprod_frases_busqueda', function (Blueprint $table) {
            $table->dropForeign(['prod_item']);
        });

        Schema::table('maeprod_frases_busqueda', function (Blueprint $table) {
            $table->dropUnique(['prod_item', 'frase_norm']);
        });

        Schema::table('maeprod_frases_busqueda', function (Blueprint $table) {
            $table->string('prod_item', 50)->nullable()->change();
            $table->unique('frase_norm');
            $table->foreign('prod_item')
                ->references('prod_item')
                ->on('maeprod')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maeprod_frases_busqueda', function (Blueprint $table) {
            $table->dropForeign(['prod_item']);
            $table->dropUnique(['frase_norm']);
        });

        Schema::table('maeprod_frases_busqueda', function (Blueprint $table) {
            $table->string('prod_item', 50)->nullable(false)->change();
            $table->unique(['prod_item', 'frase_norm']);
            $table->foreign('prod_item')
                ->references('prod_item')
                ->on('maeprod')
                ->cascadeOnDelete();
        });
    }
};
