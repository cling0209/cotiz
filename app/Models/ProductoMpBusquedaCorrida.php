<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoMpBusquedaCorrida extends Model
{
    protected $table = 'producto_mp_busqueda_corridas';

    protected $fillable = [
        'usuario',
        'fecha_busqueda',
        'inicio',
        'fin',
        'estado',
        'total_pasos',
        'pasos_procesados',
        'pasos_fallidos',
        'matches_encontrados',
        'cas_revisadas',
        'plan_json',
        'errores_json',
        'mensaje',
    ];

    protected function casts(): array
    {
        return [
            'fecha_busqueda' => 'date',
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'total_pasos' => 'integer',
            'pasos_procesados' => 'integer',
            'pasos_fallidos' => 'integer',
            'matches_encontrados' => 'integer',
            'cas_revisadas' => 'integer',
            'plan_json' => 'array',
            'errores_json' => 'array',
        ];
    }
}
