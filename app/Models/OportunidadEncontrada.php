<?php

namespace App\Models;

use App\Casts\PgBoolean;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OportunidadEncontrada extends Model
{
    protected $table = 'oportunidad_encontradas';

    protected $fillable = [
        'codigo',
        'nombre',
        'organismo',
        'rut_organismo',
        'region',
        'nombre_region',
        'comuna',
        'direccion',
        'monto_presupuesto_clp',
        'moneda',
        'fecha_publicacion',
        'fecha_cierre',
        'estado_codigo',
        'estado_glosa',
        'palabras_coinciden',
        'cantidad_productos',
        'vinculo_completo',
        'productos_vinculados',
        'porcentaje_vinculo',
        'vinculo_at',
        'vinculo_preview_json',
        'vinculo_error',
        'sync_par_at',
        'sync_par_ok',
        'sync_par_error',
        'fecha_busqueda',
        'indice_region_config',
        'found_by',
    ];

    protected function casts(): array
    {
        return [
            'region' => 'integer',
            'monto_presupuesto_clp' => 'integer',
            'fecha_publicacion' => 'datetime',
            'fecha_cierre' => 'datetime',
            'palabras_coinciden' => 'array',
            'cantidad_productos' => 'integer',
            // Neon pooler + emulate prepares: true/false llegan como 1/0 y PG rechaza boolean.
            'vinculo_completo' => PgBoolean::class,
            'productos_vinculados' => 'integer',
            'porcentaje_vinculo' => 'integer',
            'vinculo_at' => 'datetime',
            'vinculo_preview_json' => 'array',
            'sync_par_at' => 'datetime',
            'sync_par_ok' => PgBoolean::class,
            'fecha_busqueda' => 'date',
            'indice_region_config' => 'integer',
        ];
    }

    public function descubridor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'found_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toResumen(): array
    {
        return [
            'codigo' => strtoupper((string) $this->codigo),
            'nombre' => (string) ($this->nombre ?? ''),
            'organismo' => (string) ($this->organismo ?? ''),
            'rut_organismo' => (string) ($this->rut_organismo ?? ''),
            'region' => $this->region,
            'nombre_region' => (string) ($this->nombre_region ?? ''),
            'comuna' => (string) ($this->comuna ?? ''),
            'direccion' => (string) ($this->direccion ?? ''),
            'monto_presupuesto_clp' => $this->monto_presupuesto_clp,
            'moneda' => (string) ($this->moneda ?? 'CLP'),
            'fecha_publicacion' => $this->fecha_publicacion?->toIso8601String(),
            'fecha_cierre' => $this->fecha_cierre?->toIso8601String(),
            'estado_codigo' => (string) ($this->estado_codigo ?? ''),
            'estado_glosa' => (string) ($this->estado_glosa ?? ''),
            'palabras_coinciden' => array_values($this->palabras_coinciden ?? []),
            'cantidad_productos' => $this->cantidad_productos,
            'vinculo_completo' => (bool) $this->vinculo_completo,
            'tiene_vinculo_preview' => $this->tieneVinculoPreview(),
            'vinculo_error' => ($err = trim((string) ($this->vinculo_error ?? ''))) !== '' ? $err : null,
            'vinculo_estado' => $this->estadoVinculoUi(),
            'sync_par_ok' => $this->sync_par_ok === null ? null : (bool) $this->sync_par_ok,
            'sync_par_error' => ($syncErr = trim((string) ($this->sync_par_error ?? ''))) !== '' ? $syncErr : null,
            'sync_par_at' => $this->sync_par_at?->toIso8601String(),
            'sync_par_estado' => $this->estadoSyncParUi(),
            'productos_vinculados' => $this->productos_vinculados,
            'porcentaje_vinculo' => $this->porcentaje_vinculo,
            'indice_region_config' => (int) $this->indice_region_config,
            'guardada' => true,
        ];
    }

    public function tieneVinculoPreview(): bool
    {
        return is_array($this->vinculo_preview_json)
            && isset($this->vinculo_preview_json['lineas'])
            && is_array($this->vinculo_preview_json['lineas']);
    }

    /**
     * Estado visible en listado: procesada | fallida | pendiente.
     */
    public function estadoVinculoUi(): string
    {
        if ((bool) $this->vinculo_completo && $this->tieneVinculoPreview()) {
            return 'procesada';
        }

        if (trim((string) ($this->vinculo_error ?? '')) !== '') {
            return 'fallida';
        }

        return 'pendiente';
    }

    /**
     * Estado de réplica al sitio par: ok | error | omitido | pendiente | null.
     * null = aún no aplica (p. ej. vínculo no procesado).
     */
    public function estadoSyncParUi(): ?string
    {
        if ($this->estadoVinculoUi() !== 'procesada' && ! (bool) $this->vinculo_completo) {
            return null;
        }

        if ($this->sync_par_ok === true) {
            return 'ok';
        }

        $err = trim((string) ($this->sync_par_error ?? ''));
        if ($this->sync_par_ok === false) {
            if ($err !== '' && (
                str_contains(mb_strtolower($err), 'sin preview')
                || str_contains(mb_strtolower($err), 'omitid')
            )) {
                return 'omitido';
            }

            return 'error';
        }

        // Completo sin preview: no se puede replicar como procesada.
        if ((bool) $this->vinculo_completo && ! $this->tieneVinculoPreview()) {
            return 'omitido';
        }

        return 'pendiente';
    }

    /**
     * Resumen + detalle de productos para replicar vinculación al sitio par.
     *
     * @return array<string, mixed>
     */
    public function toResumenConPreviewVinculo(): array
    {
        $preview = $this->vinculo_preview_json;

        return $this->toResumen() + [
            'vinculo_preview_json' => is_array($preview) ? $preview : null,
            'vinculo_at' => $this->vinculo_at?->toIso8601String(),
            'vinculo_error' => ($err = trim((string) ($this->vinculo_error ?? ''))) !== '' ? $err : null,
        ];
    }
}
