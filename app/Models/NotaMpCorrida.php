<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotaMpCorrida extends Model
{
    protected $table = 'nota_mp_corridas';

    protected $fillable = [
        'usuario', 'inicio', 'fin', 'notas_procesadas', 'notas_con_cambio',
        'total_notas', 'nronota_actual', 'codigo_actual', 'nota_inicio_at', 'en_curso_json',
        'pendientes_json', 'estado', 'mensaje',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'nota_inicio_at' => 'datetime',
            'notas_procesadas' => 'integer',
            'notas_con_cambio' => 'integer',
            'total_notas' => 'integer',
            'nronota_actual' => 'integer',
            'en_curso_json' => 'array',
            'pendientes_json' => 'array',
        ];
    }

    /** Corrida masiva (worker): tiene cola de notas pendientes. */
    public function esMasiva(): bool
    {
        $pendientes = $this->pendientes_json;

        return is_array($pendientes) && count($pendientes) > 0;
    }

    /** Consulta síncrona de una sola nota (sin worker). */
    public function esIndividual(): bool
    {
        return ! $this->esMasiva();
    }

    /**
     * @param  Builder<NotaMpCorrida>  $query
     * @return Builder<NotaMpCorrida>
     */
    public function scopeMasivas(Builder $query): Builder
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query
                ->whereNotNull('pendientes_json')
                ->whereRaw('jsonb_array_length(pendientes_json::jsonb) > 0');
        }

        return $query
            ->whereNotNull('pendientes_json')
            ->where('pendientes_json', '!=', '[]')
            ->where('pendientes_json', '!=', 'null');
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
