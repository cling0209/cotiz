<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maeprod_import_staging', function (Blueprint $table) {
            $table->string('source_path', 500)->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('maeprod_import_staging', function (Blueprint $table) {
            $table->dropColumn('source_path');
        });
    }
};
