<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organismo_observaciones', function (Blueprint $table) {
            $table->text('observacion_automatica')->nullable()->after('observacion');
            $table->unsignedInteger('observacion_automatica_casos')->nullable()->after('observacion_automatica');
            $table->timestampTz('observacion_automatica_en')->nullable()->after('observacion_automatica_casos');
        });
    }

    public function down(): void
    {
        Schema::table('organismo_observaciones', function (Blueprint $table) {
            $table->dropColumn([
                'observacion_automatica',
                'observacion_automatica_casos',
                'observacion_automatica_en',
            ]);
        });
    }
};
