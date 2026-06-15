<?php

use App\Support\MaeprodSchemaSupport;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Fuerza ampliación aunque la migración anterior se haya registrado sin tabla.
     */
    public function up(): void
    {
        MaeprodSchemaSupport::ensurePostgresStringColumnWidths();
    }

    public function down(): void
    {
        // No revertir: podría truncar datos ya importados.
    }
};
