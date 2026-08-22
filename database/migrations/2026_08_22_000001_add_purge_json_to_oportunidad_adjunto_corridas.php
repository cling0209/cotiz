<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_adjunto_corridas', function (Blueprint $table) {
            $table->json('purge_json')->nullable()->after('mensaje');
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad_adjunto_corridas', function (Blueprint $table) {
            $table->dropColumn('purge_json');
        });
    }
};
