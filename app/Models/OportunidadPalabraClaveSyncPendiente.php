<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OportunidadPalabraClaveSyncPendiente extends Model
{
    protected $table = 'oportunidad_palabra_clave_sync_pendientes';

    protected $fillable = [
        'accion',
        'frase',
        'intentos',
        'ultimo_error',
    ];

    protected function casts(): array
    {
        return [
            'intentos' => 'integer',
        ];
    }
}
