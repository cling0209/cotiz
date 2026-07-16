<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->unsignedSmallInteger('cantidad_productos')->nullable()->after('palabras_coinciden');
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->dropColumn('cantidad_productos');
        });
    }
};
