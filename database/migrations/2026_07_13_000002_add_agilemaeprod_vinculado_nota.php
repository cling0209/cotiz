<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agilemaeprod', function (Blueprint $table) {
            $table->integer('vinculado_nota')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agilemaeprod', function (Blueprint $table) {
            $table->dropColumn('vinculado_nota');
        });
    }
};
