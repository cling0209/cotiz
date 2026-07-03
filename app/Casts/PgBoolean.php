<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Boolean cast compatible with PostgreSQL + PDO emulated prepares.
 *
 * With ATTR_EMULATE_PREPARES (required by Neon pooler), PHP true/false
 * are interpolated as 1/0 which PostgreSQL rejects for boolean columns.
 * This cast writes DB::raw('true'/'false') literals and reads back
 * as native PHP booleans.
 *
 * @implements CastsAttributes<bool, bool>
 */
class PgBoolean implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return DB::raw((bool) $value ? 'true' : 'false');
    }
}
