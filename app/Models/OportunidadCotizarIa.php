<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OportunidadCotizarIa extends Model
{
    protected $table = 'oportunidad_cotizar_ia';

    protected $fillable = [
        'codigo',
        'veces',
        'veces_aplicada',
        'ultimo_uso_at',
        'ultima_aplicacion_at',
    ];

    protected function casts(): array
    {
        return [
            'veces' => 'integer',
            'veces_aplicada' => 'integer',
            'ultimo_uso_at' => 'datetime',
            'ultima_aplicacion_at' => 'datetime',
        ];
    }
}
