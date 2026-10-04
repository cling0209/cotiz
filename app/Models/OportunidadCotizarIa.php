<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OportunidadCotizarIa extends Model
{
    protected $table = 'oportunidad_cotizar_ia';

    protected $fillable = [
        'codigo',
        'veces',
        'ultimo_uso_at',
    ];

    protected function casts(): array
    {
        return [
            'veces' => 'integer',
            'ultimo_uso_at' => 'datetime',
        ];
    }
}
