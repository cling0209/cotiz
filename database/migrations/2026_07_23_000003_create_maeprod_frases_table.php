<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maeprod_frases', function (Blueprint $table) {
            $table->id();
            $table->string('prod_item', 50);
            $table->string('frase', 200);
            $table->string('frase_norm', 200);
            $table->timestamps();

            $table->unique('frase_norm');
            $table->index('prod_item');
            $table->foreign('prod_item')
                ->references('prod_item')
                ->on('maeprod')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maeprod_frases');
    }
};
