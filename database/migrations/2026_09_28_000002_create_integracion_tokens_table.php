<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integracion_tokens')) {
            return;
        }

        Schema::create('integracion_tokens', function (Blueprint $table) {
            $table->string('proveedor', 50)->primary();
            $table->text('refresh_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integracion_tokens');
    }
};
