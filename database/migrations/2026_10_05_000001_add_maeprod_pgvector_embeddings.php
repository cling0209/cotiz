<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        if (! Schema::hasColumn('maeprod', 'prod_embedding')) {
            $dimension = max(64, min(3072, (int) env('COTIZ_EMBEDDING_DIMENSION', 768)));
            DB::statement('ALTER TABLE maeprod ADD COLUMN prod_embedding vector('.$dimension.') NULL');
            DB::statement('ALTER TABLE maeprod ADD COLUMN prod_embedding_fuente TEXT NULL');
            DB::statement('ALTER TABLE maeprod ADD COLUMN prod_embedding_at TIMESTAMP NULL');
        }

        DB::statement('CREATE INDEX IF NOT EXISTS maeprod_prod_embedding_hnsw_idx ON maeprod USING hnsw (prod_embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS maeprod_prod_embedding_hnsw_idx');

        if (Schema::hasColumn('maeprod', 'prod_embedding')) {
            DB::statement('ALTER TABLE maeprod DROP COLUMN IF EXISTS prod_embedding');
            DB::statement('ALTER TABLE maeprod DROP COLUMN IF EXISTS prod_embedding_fuente');
            DB::statement('ALTER TABLE maeprod DROP COLUMN IF EXISTS prod_embedding_at');
        }
    }
};
