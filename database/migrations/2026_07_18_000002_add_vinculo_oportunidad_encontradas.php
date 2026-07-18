<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->boolean('vinculo_completo')->default(false)->after('cantidad_productos');
            $table->unsignedInteger('productos_vinculados')->nullable()->after('vinculo_completo');
            $table->unsignedTinyInteger('porcentaje_vinculo')->nullable()->after('productos_vinculados');
            $table->timestampTz('vinculo_at')->nullable()->after('porcentaje_vinculo');
        });

        Schema::create('oportunidad_vinculo_corridas', function (Blueprint $table) {
            $table->id();
            $table->string('usuario', 50);
            $table->date('fecha_busqueda');
            $table->timestampTz('inicio');
            $table->timestampTz('fin')->nullable();
            $table->string('estado', 20)->default('running');
            $table->unsignedInteger('total_pasos')->default(0);
            $table->unsignedInteger('pasos_procesados')->default(0);
            $table->unsignedInteger('pasos_fallidos')->default(0);
            $table->json('plan_json');
            $table->json('errores_json')->nullable();
            $table->text('mensaje')->nullable();
            $table->timestamps();

            $table->index(['estado', 'fecha_busqueda']);
            $table->index('inicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_vinculo_corridas');

        Schema::table('oportunidad_encontradas', function (Blueprint $table) {
            $table->dropColumn([
                'vinculo_completo',
                'productos_vinculados',
                'porcentaje_vinculo',
                'vinculo_at',
            ]);
        });
    }
};
