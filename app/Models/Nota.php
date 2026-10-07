<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'nronota',
        'descripcion',
        'fecha',
        'usuario',
        'asignado_por',
        'asignado_at',
        'empresa',
        'encargado',
        'correlativo',
        'celular',
        'contacto',
        'contactocorreo',
        'rutempresa',
        'nota_softland',
        'diashabiles',
        'notaorigen',
        'sistema',
        'enviadoapi',
        'estado',
        'estadofecha',
        'estadousuario',
        'ocompra',
        'ocompra_usuario',
        'ocompra_registrada_en',
        'fecha_envio_oc',
        'fecha_envio_oc_usuario',
        'fecha_envio_oc_registrada_en',
        'fechaentrega',
        'factor_precio_venta',
        'direccion_entrega',
        'region',
        'nombre_region',
        'comuna',
        'observacion_ejecutivo',
        'es_compra_agil',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fechaentrega' => 'date',
            'estadofecha' => 'datetime',
            'ocompra_registrada_en' => 'datetime',
            'fecha_envio_oc' => 'date',
            'fecha_envio_oc_registrada_en' => 'datetime',
            'asignado_at' => 'datetime',
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

    public function asignadoPorRel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por', 'username');
    }

    public function mpSeguimiento(): HasOne
    {
        return $this->hasOne(NotaMpSeguimiento::class, 'nronota', 'nronota');
    }

    /** Código OC manual; si está vacío, el resuelto desde Mercado Público. */
    public function ocompraEfectiva(): string
    {
        $manual = trim((string) ($this->ocompra ?? ''));
        if ($manual !== '') {
            return $manual;
        }

        return strtoupper(trim((string) ($this->mpSeguimiento?->ocompra_mp ?? '')));
    }

    /**
     * Campos de registro del código OC manual: usuario y fecha cuando el código cambia;
     * vacío si se borra; sin cambios si el código es el mismo.
     *
     * @return array{ocompra_usuario?: ?string, ocompra_registrada_en?: ?\Illuminate\Support\Carbon}
     */
    public static function registroOcompra(?string $anterior, ?string $nuevo, ?string $usuario): array
    {
        $anterior = strtoupper(trim((string) $anterior));
        $nuevo = strtoupper(trim((string) $nuevo));
        if ($anterior === $nuevo) {
            return [];
        }

        if ($nuevo === '') {
            return ['ocompra_usuario' => null, 'ocompra_registrada_en' => null];
        }

        $usuario = trim((string) $usuario);

        return [
            'ocompra_usuario' => $usuario !== '' ? mb_substr($usuario, 0, 50) : null,
            'ocompra_registrada_en' => now(),
        ];
    }

    /** «usuario · dd/mm/aaaa hh:mm» de quien ingresó el código OC manual, o vacío si no hay registro. */
    public function textoRegistroOcompra(): string
    {
        $partes = array_filter([
            trim((string) ($this->ocompra_usuario ?? '')),
            $this->ocompra_registrada_en?->format('d/m/Y H:i') ?? '',
        ], fn(string $parte) => $parte !== '');

        return implode(' · ', $partes);
    }

    /** Normaliza fecha manual de envío OC (solo día, zona app). */
    public static function normalizarFechaEnvioOcManual(\Carbon\Carbon|string|null $valor): ?\Illuminate\Support\Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $tz = (string) config('app.timezone', 'America/Santiago');

        return \Illuminate\Support\Carbon::parse($valor, $tz)->startOfDay();
    }

    /** Copia solo la fecha de envío desde MP a valor manual (sin hora). */
    public static function fechaEnvioOcManualDesdeMp(\Illuminate\Support\Carbon $fechaMp): \Illuminate\Support\Carbon
    {
        $tz = (string) config('app.timezone', 'America/Santiago');

        return $fechaMp->copy()->timezone($tz)->startOfDay();
    }

    /** Fecha de envío OC manual (al aceptar o vacío). */
    public function fechaEnvioOcManual(): ?\Illuminate\Support\Carbon
    {
        return $this->fecha_envio_oc;
    }

    /** «usuario · dd/mm/aaaa hh:mm» del registro de fecha envío OC manual, o vacío. */
    public function textoRegistroFechaEnvioOc(): string
    {
        $partes = array_filter([
            trim((string) ($this->fecha_envio_oc_usuario ?? '')),
            $this->fecha_envio_oc_registrada_en?->format('d/m/Y H:i') ?? '',
        ], fn (string $parte) => $parte !== '');

        return implode(' · ', $partes);
    }

    /** Formato de lista: manual sin hora; solo MP con hora si aplica. */
    public function formatoFechaEnvioOcEfectiva(): string
    {
        $fecha = $this->fechaEnvioOcEfectiva();
        if ($fecha === null) {
            return '';
        }

        if ($this->fecha_envio_oc !== null) {
            return $fecha->format('d/m/Y');
        }

        return $fecha->format('d/m/Y H:i');
    }

    /**
     * Fecha de envío OC para mostrar: manual en la nota; si no, la de MP en el seguimiento.
     */
    public function fechaEnvioOcEfectiva(): ?\Illuminate\Support\Carbon
    {
        if ($this->fecha_envio_oc !== null) {
            return $this->fecha_envio_oc;
        }

        return $this->mpSeguimiento?->oc_fecha_envio;
    }

    /** Indica si la fecha efectiva proviene solo de MP (sin manual en nota). */
    public function fechaEnvioOcDesdeApi(): bool
    {
        return $this->fecha_envio_oc === null
            && $this->mpSeguimiento?->oc_fecha_envio !== null;
    }

    /** Indica si el código OC efectivo proviene solo de MP (sin manual en nota). */
    public function ocompraDesdeApi(): bool
    {
        return trim((string) ($this->ocompra ?? '')) === ''
            && trim((string) ($this->mpSeguimiento?->ocompra_mp ?? '')) !== '';
    }

    public function tieneOcompraMpObtenida(): bool
    {
        return trim((string) ($this->mpSeguimiento?->ocompra_mp ?? '')) !== '';
    }

    public function tieneFechaEnvioOcApiObtenida(): bool
    {
        return $this->mpSeguimiento?->oc_fecha_envio !== null;
    }

    public function total(): int
    {
        return (int) $this->detalle->sum(fn(NotaDetalle $linea) => $linea->prod_valor * $linea->cantidad);
    }

    public function requiereNumeroCotizacion(): bool
    {
        return trim((string) $this->encargado) === '';
    }

    /** Adjudicada a mano con el botón «Aceptar» del listado. */
    public function estaAceptada(): bool
    {
        return strtolower(trim((string) $this->estado)) === 'aceptada';
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

    /**
     * En PostgreSQL con prepares emulados, where(columna, true) se envía como "= 1"
     * y la columna boolean lo rechaza. El literal true/false evita ese error.
     */
    public function scopeWhereEsCompraAgil(Builder $query, bool $valor = true): Builder
    {
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->whereRaw('es_compra_agil is '.($valor ? 'true' : 'false'));
        }

        return $query->where('es_compra_agil', $valor);
    }

    public function esCotizacionInterna(): bool
    {
        return $this->es_compra_agil === false;
    }

    public static function siguienteNotaSoftland(): int
    {
        $max = (int) static::query()->where('nota_softland', '>', 0)->max('nota_softland');

        return max($max + 1, (int) config('cotiz.nota_softland_inicio', 10000));
    }
}
