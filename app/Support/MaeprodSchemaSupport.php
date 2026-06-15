<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MaeprodSchemaSupport
{
    /** @var array<string, int> */
    public const STRING_COLUMN_WIDTHS = [
        'prod_item' => 120,
        'prod_nombre' => 255,
        'prod_imagen' => 255,
        'prod_gramaje' => 120,
        'prod_familia' => 120,
        'prod_item_softland' => 120,
        'prod_user_upd' => 50,
    ];

    public static function ensurePostgresStringColumnWidths(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('maeprod')) {
            return;
        }

        foreach (self::STRING_COLUMN_WIDTHS as $column => $length) {
            if (! Schema::hasColumn('maeprod', $column)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE maeprod ALTER COLUMN %s TYPE varchar(%d)',
                $column,
                $length,
            ));
        }
    }
}
