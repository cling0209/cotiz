<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->timestamp('sync_par_at')->nullable()->after('vinculo_error');
            $table->boolean('sync_par_ok')->nullable()->after('sync_par_at');
            $table->text('sync_par_error')->nullable()->after('sync_par_ok');
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->dropColumn(['sync_par_at', 'sync_par_ok', 'sync_par_error']);
        });
    }
};
