<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('maeprod_softland_auditoria')) {
            return;
        }

        Schema::create('maeprod_softland_auditoria', function (Blueprint $table) {
            $table->id();
            $table->string('prod_item', 120);
            $table->string('usuario', 50);
            $table->timestamp('fechahora');
            $table->string('valor_anterior', 120)->nullable();
            $table->string('valor_nuevo', 120)->nullable();
            $table->string('origen', 20);
            $table->integer('nronota')->nullable();

            $table->index(['prod_item', 'fechahora']);
            $table->index(['origen', 'fechahora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maeprod_softland_auditoria');
    }
};
