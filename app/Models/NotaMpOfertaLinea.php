<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaMpOfertaLinea extends Model
{
    protected $table = 'nota_mp_oferta_lineas';

    protected $fillable = [
        'oferta_id', 'codigo_producto', 'nombre_producto', 'descripcion',
        'cantidad', 'precio_unitario', 'monto_total',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'float',
            'precio_unitario' => 'integer',
            'monto_total' => 'integer',
        ];
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(NotaMpOferta::class, 'oferta_id');
    }
}
