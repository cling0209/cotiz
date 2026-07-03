<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaMpCorridaCambio extends Model
{
    protected $table = 'nota_mp_corrida_cambios';

    protected $fillable = [
        'corrida_id', 'nronota', 'codigo_proceso', 'estado_anterior', 'estado_nuevo',
        'resultado_propio', 'rut_ganador', 'razon_social_ganador',
    ];

    protected function casts(): array
    {
        return [
            'nronota' => 'integer',
        ];
    }

    public function corrida(): BelongsTo
    {
        return $this->belongsTo(NotaMpCorrida::class, 'corrida_id');
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class, 'nronota', 'nronota');
    }
}
