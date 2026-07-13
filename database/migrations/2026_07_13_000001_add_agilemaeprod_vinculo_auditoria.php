<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agilemaeprod', function (Blueprint $table) {
            $table->string('vinculado_por', 100)->nullable();
            $table->timestamp('vinculado_en')->nullable();
            $table->string('vinculado_origen', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agilemaeprod', function (Blueprint $table) {
            $table->dropColumn(['vinculado_por', 'vinculado_en', 'vinculado_origen']);
        });
    }
};
