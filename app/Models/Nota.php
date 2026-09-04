<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Nota extends Model
{
    protected $table = 'notas';

    public $incrementing = false;

    protected $primaryKey = 'nronota';

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'nronota', 'descripcion', 'fecha', 'usuario', 'empresa', 'encargado', 'correlativo',
        'celular', 'contacto', 'contactocorreo', 'rutempresa', 'nota_softland',
        'diashabiles', 'notaorigen', 'sistema', 'enviadoapi', 'estado',
        'estadofecha', 'estadousuario', 'ocompra', 'fechaentrega', 'factor_precio_venta',
        'direccion_entrega', 'region', 'nombre_region', 'comuna',
        'observacion_ejecutivo',
        'es_compra_agil',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fechaentrega' => 'date',
            'estadofecha' => 'datetime',
            'factor_precio_venta' => 'decimal:4',
            'enviadoapi' => 'integer',
            'diashabiles' => 'integer',
            'region' => 'integer',
            'correlativo' => 'integer',
            'es_compra_agil' => 'boolean',
        ];
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(NotaDetalle::class, 'nronota', 'nronota')->orderBy('orden');
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(NotaAuditoria::class, 'nronota', 'nronota')->orderByDesc('fechahora');
    }

    public function usuarioRel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario', 'username');
    }

    public function mpSeguimiento(): HasOne
    {
        return $this->hasOne(NotaMpSeguimiento::class, 'nronota', 'nronota');
    }

    public function total(): int
    {
        return (int) $this->detalle->sum(fn (NotaDetalle $linea) => $linea->prod_valor * $linea->cantidad);
    }

    public function requiereNumeroCotizacion(): bool
    {
        return trim((string) $this->encargado) === '';
    }

    public function fueRecibidaPorApi(): bool
    {
        return (int) $this->notaorigen > 0;
    }

    /**
     * Copia de otra cotización del mismo proceso de Mercado Público.
     */
    public function esCopiaDeCotizacion(): bool
    {
        return (int) ($this->correlativo ?? 1) > 1;
    }

    public function esCotizacionInterna(): bool
    {
        return $this->es_compra_agil === false;
    }
}
