<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->timestamp('nota_inicio_at')->nullable()->after('codigo_actual');
        });
    }

    public function down(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->dropColumn('nota_inicio_at');
        });
    }
};
