<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OportunidadBusquedaCorrida extends Model
{
    protected $table = 'oportunidad_busqueda_corridas';

    protected $fillable = [
        'usuario',
        'fecha_busqueda',
        'inicio',
        'fin',
        'estado',
        'total_pasos',
        'pasos_procesados',
        'pasos_fallidos',
        'oportunidades_encontradas',
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
            'oportunidades_encontradas' => 'integer',
            'plan_json' => 'array',
            'errores_json' => 'array',
        ];
    }
}
