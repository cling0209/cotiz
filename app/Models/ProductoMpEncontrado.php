<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoMpEncontrado extends Model
{
    protected $table = 'producto_mp_encontrados';

    protected $fillable = [
        'codigo',
        'nombre_ca',
        'organismo',
        'region',
        'nombre_region',
        'codigo_producto_mp',
        'descripcion_mp',
        'prod_item',
        'prod_nombre',
        'frase',
        'frase_norm',
        'origen_detalle',
        'fecha_publicacion',
        'fecha_cierre',
        'fecha_busqueda',
    ];

    protected function casts(): array
    {
        return [
            'region' => 'integer',
            'fecha_publicacion' => 'datetime',
            'fecha_cierre' => 'datetime',
            'fecha_busqueda' => 'date',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Maeprod::class, 'prod_item', 'prod_item');
    }
}
