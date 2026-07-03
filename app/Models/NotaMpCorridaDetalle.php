<?php

namespace App\Models;

use App\Casts\PgBoolean;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaMpCorridaDetalle extends Model
{
    protected $table = 'nota_mp_corrida_detalle';

    protected $fillable = [
        'corrida_id', 'nronota', 'codigo_proceso', 'empresa', 'exito', 'mensaje',
        'estado_mp_glosa', 'resultado_propio', 'rut_ganador', 'razon_social_ganador', 'cambio',
    ];

    protected function casts(): array
    {
        return [
            'corrida_id' => 'integer',
            'nronota' => 'integer',
            'exito' => PgBoolean::class,
            'cambio' => PgBoolean::class,
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
