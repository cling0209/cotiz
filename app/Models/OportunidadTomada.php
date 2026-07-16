<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OportunidadTomada extends Model
{
    protected $table = 'oportunidad_tomadas';

    protected $fillable = [
        'codigo',
        'sistema',
        'usuario',
        'tomada_at',
    ];

    protected function casts(): array
    {
        return [
            'tomada_at' => 'datetime',
        ];
    }
}
