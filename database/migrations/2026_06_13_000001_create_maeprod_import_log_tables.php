<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maeprod_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('usuario', 50);
            $table->string('archivo', 255);
            $table->unsignedInteger('creados')->default(0);
            $table->unsignedInteger('actualizados')->default(0);
            $table->unsignedInteger('omitidos')->default(0);
            $table->unsignedInteger('total_errores')->default(0);
            $table->string('estado', 20);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('maeprod_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('maeprod_import_runs')->cascadeOnDelete();
            $table->unsignedInteger('fila')->nullable();
            $table->string('codigo', 50)->nullable();
            $table->string('nombre', 255)->nullable();
            $table->string('familia', 120)->nullable();
            $table->string('mensaje', 255);
            $table->string('detalle', 255)->nullable();
            $table->index(['run_id', 'fila']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maeprod_import_errors');
        Schema::dropIfExists('maeprod_import_runs');
    }
};
