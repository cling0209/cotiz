<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OportunidadEncontradaSyncPendiente extends Model
{
    protected $table = 'oportunidad_encontrada_sync_pendientes';

    protected $fillable = [
        'payload',
        'intentos',
        'ultimo_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'intentos' => 'integer',
        ];
    }
}
