<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraAgilLineaMercado extends Model
{
    protected $table = 'compra_agil_lineas_mercado';

    protected $fillable = [
        'codigo_proceso', 'codigo_producto_mp', 'nombre_producto', 'cantidad', 'unidad_medida',
        'precio_ganador_unitario', 'prod_item', 'fecha_proceso',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'precio_ganador_unitario' => 'integer',
            'fecha_proceso' => 'datetime',
        ];
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(CompraAgilProceso::class, 'codigo_proceso', 'codigo');
    }
}
