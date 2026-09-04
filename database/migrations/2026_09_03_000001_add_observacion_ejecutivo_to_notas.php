<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notas', 'observacion_ejecutivo')) {
            Schema::table('notas', function (Blueprint $table) {
                $table->text('observacion_ejecutivo')->nullable()->after('comuna');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notas') && Schema::hasColumn('notas', 'observacion_ejecutivo')) {
            Schema::table('notas', function (Blueprint $table) {
                $table->dropColumn('observacion_ejecutivo');
            });
        }
    }
};
