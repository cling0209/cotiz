<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotaMpOferta extends Model
{
    protected $table = 'nota_mp_ofertas';

    protected $fillable = [
        'nronota', 'id_cotizacion_mp', 'rut_proveedor', 'razon_social',
        'proveedor_seleccionado', 'monto_total', 'es_propio', 'inadmisible', 'id_oc',
    ];

    protected function casts(): array
    {
        return [
            'nronota' => 'integer',
            'id_cotizacion_mp' => 'integer',
            'monto_total' => 'integer',
            'proveedor_seleccionado' => 'boolean',
            'es_propio' => 'boolean',
            'inadmisible' => 'boolean',
            'id_oc' => 'integer',
        ];
    }

    public function seguimiento(): BelongsTo
    {
        return $this->belongsTo(NotaMpSeguimiento::class, 'nronota', 'nronota');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(NotaMpOfertaLinea::class, 'oferta_id');
    }

    public function scopeWhereProveedorSeleccionado(Builder $query): Builder
    {
        return $query->whereRaw('proveedor_seleccionado IS TRUE');
    }
}
