<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notasdetalle')) {
            return;
        }

        if (! Schema::hasColumn('notasdetalle', 'observacion_cliente')) {
            Schema::table('notasdetalle', function (Blueprint $table) {
                $table->text('observacion_cliente')->nullable()->after('observacion');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notasdetalle') && Schema::hasColumn('notasdetalle', 'observacion_cliente')) {
            Schema::table('notasdetalle', function (Blueprint $table) {
                $table->dropColumn('observacion_cliente');
            });
        }
    }
};
