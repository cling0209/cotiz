<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('famprod', function (Blueprint $table) {
            $table->string('codigo', 120)->primary();
            $table->string('nombre', 120)->nullable();
        });

        Schema::create('maeprod', function (Blueprint $table) {
            $table->string('prod_item', 50)->primary();
            $table->string('prod_nombre', 255)->nullable();
            $table->string('prod_imagen', 255)->nullable();
            $table->integer('prod_valor')->nullable();
            $table->integer('prod_stock_real')->nullable();
            $table->string('prod_gramaje', 120)->nullable();
            $table->string('prod_familia', 120)->nullable();
            $table->string('prod_item_softland', 50)->nullable();
            $table->timestamp('prod_valor_fecha')->nullable();
            $table->timestamp('prod_item_softland_fecha')->nullable();
            $table->integer('prod_valor_costo')->nullable();
            $table->string('prod_user_upd', 50)->nullable();
        });

        Schema::create('notas', function (Blueprint $table) {
            $table->integer('nronota')->primary();
            $table->string('descripcion', 500);
            $table->date('fecha');
            $table->string('usuario', 20);
            $table->string('empresa', 100)->default('');
            $table->string('encargado', 100)->default('');
            $table->string('celular', 15)->default('');
            $table->string('contacto', 100)->default('');
            $table->string('contactocorreo', 60)->default('');
            $table->string('rutempresa', 10)->nullable();
            $table->integer('nota_softland')->nullable();
            $table->integer('diashabiles')->nullable();
            $table->integer('notaorigen')->nullable();
            $table->string('sistema', 100)->nullable();
            $table->integer('enviadoapi')->default(0);
            $table->string('estado', 100)->nullable();
            $table->timestamp('estadofecha')->nullable();
            $table->string('estadousuario', 20)->nullable();
            $table->string('ocompra', 20)->nullable();
            $table->date('fechaentrega')->nullable();
            $table->decimal('factor_precio_venta', 8, 4)->nullable();

            $table->index('usuario');
            $table->index('fecha');
            $table->index('encargado');
        });

        Schema::create('notasdetalle', function (Blueprint $table) {
            $table->integer('nronota');
            $table->string('prod_item', 50);
            $table->integer('prod_valor');
            $table->integer('cantidad');
            $table->timestamp('fechahora');
            $table->integer('orden');
            $table->integer('prod_valor_costo')->default(0);
            $table->string('prod_item_agile', 50)->nullable();

            $table->primary(['nronota', 'prod_item', 'orden']);
            $table->foreign('nronota')->references('nronota')->on('notas')->cascadeOnDelete();
        });

        Schema::create('nronota_seq', function (Blueprint $table) {
            $table->integer('ultimo')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nronota_seq');
        Schema::dropIfExists('notasdetalle');
        Schema::dropIfExists('notas');
        Schema::dropIfExists('maeprod');
        Schema::dropIfExists('famprod');
    }
};
