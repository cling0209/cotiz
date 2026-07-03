<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotaMpSeguimiento extends Model
{
    protected $table = 'nota_mp_seguimientos';

    protected $primaryKey = 'nronota';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'nronota', 'codigo_proceso', 'estado_mp_codigo', 'estado_mp_glosa', 'organismo',
        'rut_ganador', 'razon_social_ganador', 'id_orden_compra', 'monto_total_ganador',
        'resultado_propio', 'finalizado', 'ultimo_usuario', 'ultimo_consultado_en', 'ultima_corrida_id',
    ];

    protected function casts(): array
    {
        return [
            'nronota' => 'integer',
            'id_orden_compra' => 'integer',
            'monto_total_ganador' => 'integer',
            'finalizado' => 'boolean',
            'ultimo_consultado_en' => 'datetime',
        ];
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class, 'nronota', 'nronota');
    }

    public function ofertas(): HasMany
    {
        return $this->hasMany(NotaMpOferta::class, 'nronota', 'nronota');
    }

    public function ultimaCorrida(): BelongsTo
    {
        return $this->belongsTo(NotaMpCorrida::class, 'ultima_corrida_id');
    }

    public function scopeFinalizado(Builder $query): Builder
    {
        return $query->whereRaw('finalizado IS TRUE');
    }

    public function scopePendiente(Builder $query): Builder
    {
        return $query->whereRaw('finalizado IS FALSE');
    }
}
