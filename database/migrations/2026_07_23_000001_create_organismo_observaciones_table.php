<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organismo_observaciones', function (Blueprint $table) {
            $table->id();
            $table->string('rut_organismo', 20);
            $table->string('nombre', 200)->nullable();
            $table->text('observacion')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('rut_organismo');
            $table->index('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organismo_observaciones');
    }
};
