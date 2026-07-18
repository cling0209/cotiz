<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->json('vinculo_preview_json')->nullable()->after('vinculo_at');
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->dropColumn('vinculo_preview_json');
        });
    }
};
