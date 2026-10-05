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

        if (! Schema::hasColumn('maeprod', 'prod_embedding_model')) {
            Schema::table('maeprod', function ($table) {
                $table->string('prod_embedding_model', 128)->nullable();
            });
        }

        $targetDim = max(64, min(3072, (int) env('COTIZ_EMBEDDING_DIMENSION', 384)));

        DB::statement('DROP INDEX IF EXISTS maeprod_prod_embedding_hnsw_idx');

        DB::statement(
            'UPDATE maeprod SET prod_embedding = NULL, prod_embedding_fuente = NULL, '
            .'prod_embedding_at = NULL, prod_embedding_model = NULL WHERE prod_embedding IS NOT NULL'
        );

        if (Schema::hasColumn('maeprod', 'prod_embedding')) {
            DB::statement('ALTER TABLE maeprod DROP COLUMN prod_embedding');
        }

        DB::statement('ALTER TABLE maeprod ADD COLUMN prod_embedding vector('.$targetDim.') NULL');

        DB::statement(
            'CREATE INDEX IF NOT EXISTS maeprod_prod_embedding_hnsw_idx ON maeprod '
            .'USING hnsw (prod_embedding vector_cosine_ops)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS maeprod_prod_embedding_hnsw_idx');

        if (Schema::hasColumn('maeprod', 'prod_embedding')) {
            DB::statement('ALTER TABLE maeprod DROP COLUMN prod_embedding');
        }

        $dim = max(64, min(3072, (int) env('COTIZ_EMBEDDING_DIMENSION', 768)));
        DB::statement('ALTER TABLE maeprod ADD COLUMN prod_embedding vector('.$dim.') NULL');

        DB::statement(
            'CREATE INDEX IF NOT EXISTS maeprod_prod_embedding_hnsw_idx ON maeprod '
            .'USING hnsw (prod_embedding vector_cosine_ops)'
        );

        if (Schema::hasColumn('maeprod', 'prod_embedding_model')) {
            Schema::table('maeprod', function ($table) {
                $table->dropColumn('prod_embedding_model');
            });
        }
    }
};
