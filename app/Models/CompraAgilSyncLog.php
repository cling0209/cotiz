<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompraAgilSyncLog extends Model
{
    protected $table = 'compra_agil_sync_logs';

    protected $fillable = [
        'inicio', 'fin', 'usuario', 'listados', 'detalles', 'procesos_nuevos', 'estado', 'mensaje',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'listados' => 'integer',
            'detalles' => 'integer',
            'procesos_nuevos' => 'integer',
        ];
    }
}
