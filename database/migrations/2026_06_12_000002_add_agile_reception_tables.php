<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agilemaeprod', function (Blueprint $table) {
            $table->string('prod_item_agile', 50)->primary();
            $table->string('prod_descripcion_agile', 255)->nullable();
            $table->string('prod_item', 50)->nullable()->default('');
            $table->index('prod_item');
        });

        Schema::table('notasdetalle', function (Blueprint $table) {
            $table->string('prod_descripcion_agile', 500)->nullable()->after('prod_item_agile');
        });
    }

    public function down(): void
    {
        Schema::table('notasdetalle', function (Blueprint $table) {
            $table->dropColumn('prod_descripcion_agile');
        });

        Schema::dropIfExists('agilemaeprod');
    }
};
