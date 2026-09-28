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

        if (! Schema::hasColumn('notasdetalle', 'imagen_ref')) {
            Schema::table('notasdetalle', function (Blueprint $table) {
                $table->string('imagen_ref', 300)->nullable()->after('observacion_cliente');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notasdetalle') && Schema::hasColumn('notasdetalle', 'imagen_ref')) {
            Schema::table('notasdetalle', function (Blueprint $table) {
                $table->dropColumn('imagen_ref');
            });
        }
    }
};
