<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            if (! Schema::hasColumn('notas', 'asignado_por')) {
                $table->string('asignado_por', 20)->nullable()->after('usuario');
            }
            if (! Schema::hasColumn('notas', 'asignado_at')) {
                $table->timestampTz('asignado_at')->nullable()->after('asignado_por');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            if (Schema::hasColumn('notas', 'asignado_at')) {
                $table->dropColumn('asignado_at');
            }
            if (Schema::hasColumn('notas', 'asignado_por')) {
                $table->dropColumn('asignado_por');
            }
        });
    }
};
