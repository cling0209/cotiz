<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Si quedó la migración anterior de columnas en notas (no desplegada), limpiar.
        if (Schema::hasColumn('notas', 'modificado_usuario')) {
            Schema::table('notas', function (Blueprint $table) {
                $drop = array_values(array_filter([
                    Schema::hasColumn('notas', 'modificado_usuario') ? 'modificado_usuario' : null,
                    Schema::hasColumn('notas', 'modificado_fechahora') ? 'modificado_fechahora' : null,
                    Schema::hasColumn('notas', 'pdf_usuario') ? 'pdf_usuario' : null,
                    Schema::hasColumn('notas', 'pdf_fechahora') ? 'pdf_fechahora' : null,
                ]));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (! Schema::hasTable('notasauditoria')) {
            Schema::create('notasauditoria', function (Blueprint $table) {
                $table->id();
                $table->integer('nronota');
                $table->string('usuario', 20);
                $table->timestamp('fechahora');
                $table->string('accion', 20);
                $table->string('observacion', 500)->nullable();

                $table->index(['nronota', 'fechahora']);
                $table->index(['nronota', 'accion']);
                $table->foreign('nronota')->references('nronota')->on('notas')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notasauditoria');
    }
};
