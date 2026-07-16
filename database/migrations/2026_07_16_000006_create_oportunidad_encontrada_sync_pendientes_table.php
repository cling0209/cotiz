<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad_encontrada_sync_pendientes', function (Blueprint $table) {
            $table->id();
            $table->json('payload');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_encontrada_sync_pendientes');
    }
};
