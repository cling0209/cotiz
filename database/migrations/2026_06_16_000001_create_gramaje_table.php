<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gramaje', function (Blueprint $table) {
            $table->integer('codigo')->primary();
            $table->string('nombre', 120);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gramaje');
    }
};
