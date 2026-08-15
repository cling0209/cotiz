<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->boolean('es_compra_agil')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn('es_compra_agil');
        });
    }
};
