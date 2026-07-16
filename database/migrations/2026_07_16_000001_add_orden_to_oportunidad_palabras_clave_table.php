<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad_palabras_clave', function (Blueprint $table) {
            $table->unsignedInteger('orden')->default(0)->after('frase');
        });

        $filas = DB::table('oportunidad_palabras_clave')
            ->orderBy('frase')
            ->orderBy('id')
            ->get(['id']);

        $n = 1;
        foreach ($filas as $fila) {
            DB::table('oportunidad_palabras_clave')
                ->where('id', $fila->id)
                ->update(['orden' => $n]);
            $n++;
        }
    }

    public function down(): void
    {
        Schema::table('oportunidad_palabras_clave', function (Blueprint $table) {
            $table->dropColumn('orden');
        });
    }
};
