<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Maeprod extends Model
{
    protected $table = 'maeprod';

    public $incrementing = false;

    protected $primaryKey = 'prod_item';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'prod_item', 'prod_nombre', 'prod_imagen', 'prod_valor', 'prod_stock_real',
        'prod_gramaje', 'prod_familia', 'prod_item_softland', 'prod_valor_fecha',
        'prod_item_softland_fecha', 'prod_valor_costo', 'prod_user_upd',
    ];

    protected function casts(): array
    {
        return [
            'prod_valor_fecha' => 'datetime',
            'prod_item_softland_fecha' => 'datetime',
        ];
    }

    public function imageUrl(): string
    {
        $base = rtrim((string) config('products.image_base_url'), '/');
        $familia = trim((string) $this->prod_familia);
        $item = trim((string) $this->prod_item);

        if ($base === '' || $familia === '' || $item === '') {
            return (string) config('products.image_fallback_url', '');
        }

        $pattern = config('products.image_filename_pattern', '{codigo}_medium.jpg');
        $filename = str_replace('{codigo}', $item, $pattern);

        return $base.'/'.trim($familia, '/').'/'.ltrim($filename, '/');
    }
}
