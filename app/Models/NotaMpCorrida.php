<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotaMpCorrida extends Model
{
    protected $table = 'nota_mp_corridas';

    protected $fillable = [
        'usuario', 'inicio', 'fin', 'notas_procesadas', 'notas_con_cambio',
        'total_notas', 'nronota_actual', 'codigo_actual', 'pendientes_json',
        'estado', 'mensaje',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'notas_procesadas' => 'integer',
            'notas_con_cambio' => 'integer',
            'total_notas' => 'integer',
            'nronota_actual' => 'integer',
            'pendientes_json' => 'array',
        ];
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(NotaMpCorridaCambio::class, 'corrida_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(NotaMpCorridaDetalle::class, 'corrida_id');
    }
}
