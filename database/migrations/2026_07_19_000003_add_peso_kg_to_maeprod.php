<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maeprod') || Schema::hasColumn('maeprod', 'peso_kg')) {
            return;
        }

        Schema::table('maeprod', function (Blueprint $table) {
            $table->decimal('peso_kg', 8, 3)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('maeprod') || ! Schema::hasColumn('maeprod', 'peso_kg')) {
            return;
        }

        Schema::table('maeprod', function (Blueprint $table) {
            $table->dropColumn('peso_kg');
        });
    }
};
