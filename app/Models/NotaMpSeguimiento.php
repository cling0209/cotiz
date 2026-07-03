<?php

namespace App\Models;

use App\Casts\PgBoolean;
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
        'fecha_publicacion', 'fecha_cierre', 'fecha_ultimo_cambio', 'fecha_cancelacion',
        'rut_ganador', 'razon_social_ganador', 'id_orden_compra', 'monto_total_ganador',
        'resultado_propio', 'finalizado', 'ultimo_usuario', 'ultimo_consultado_en', 'ultima_corrida_id',
    ];

    protected function casts(): array
    {
        return [
            'nronota' => 'integer',
            'id_orden_compra' => 'integer',
            'monto_total_ganador' => 'integer',
            'finalizado' => PgBoolean::class,
            'fecha_publicacion' => 'datetime',
            'fecha_cierre' => 'datetime',
            'fecha_ultimo_cambio' => 'datetime',
            'fecha_cancelacion' => 'datetime',
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

    public function scopeWhereFinalizado(Builder $query): Builder
    {
        return $query->whereRaw('finalizado IS TRUE');
    }

    public function scopeWherePendiente(Builder $query): Builder
    {
        return $query->whereRaw('finalizado IS FALSE');
    }
}
