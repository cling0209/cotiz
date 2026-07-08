<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->json('recientes_json')->nullable()->after('en_curso_json');
        });
    }

    public function down(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->dropColumn('recientes_json');
        });
    }
};
