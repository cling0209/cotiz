<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->string('direccion_entrega', 255)->nullable()->after('fechaentrega');
            $table->unsignedTinyInteger('region')->nullable()->after('direccion_entrega');
            $table->string('nombre_region', 100)->nullable()->after('region');
            $table->string('comuna', 120)->nullable()->after('nombre_region');
        });

        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->string('direccion', 255)->nullable()->after('comuna');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn(['direccion_entrega', 'region', 'nombre_region', 'comuna']);
        });

        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->dropColumn('direccion');
        });
    }
};
