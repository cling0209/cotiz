<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_cotizar_ia', function (Blueprint $table) {
            $table->unsignedInteger('veces_aplicada')->default(0)->after('veces');
            $table->timestamp('ultima_aplicacion_at')->nullable()->after('ultimo_uso_at');
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad_cotizar_ia', function (Blueprint $table) {
            $table->dropColumn(['veces_aplicada', 'ultima_aplicacion_at']);
        });
    }
};
