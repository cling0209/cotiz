<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->json('en_curso_json')->nullable()->after('nota_inicio_at');
        });
    }

    public function down(): void
    {
        Schema::table('nota_mp_corridas', function (Blueprint $table) {
            $table->dropColumn('en_curso_json');
        });
    }
};
