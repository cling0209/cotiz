<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_agil_sync_logs', function (Blueprint $table) {
            $table->string('usuario', 50)->nullable()->after('inicio');
        });
    }

    public function down(): void
    {
        Schema::table('compra_agil_sync_logs', function (Blueprint $table) {
            $table->dropColumn('usuario');
        });
    }
};
