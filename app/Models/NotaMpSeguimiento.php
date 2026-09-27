<?php

namespace App\Models;

use App\Casts\PgBoolean;
use App\Enums\EstadoOrdenCompraMp;
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
        'convocatoria_estado', 'convocatoria_descripcion',
        'fecha_cierre_primer_llamado', 'fecha_cierre_segundo_llamado',
        'rut_ganador', 'razon_social_ganador', 'id_orden_compra',
        'oc_fecha_envio', 'oc_fecha_creacion', 'oc_fecha_aceptacion', 'oc_estado',
        'monto_total_ganador',
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
            'fecha_cierre_primer_llamado' => 'datetime',
            'fecha_cierre_segundo_llamado' => 'datetime',
            'oc_fecha_envio' => 'datetime',
            'oc_fecha_creacion' => 'datetime',
            'oc_fecha_aceptacion' => 'datetime',
            'convocatoria_estado' => 'integer',
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

    /** Fecha de última consulta MP con usuario entre paréntesis (sistema si fue automático). */
    public function textoConsultado(string $vacio = '—'): string
    {
        if ($this->ultimo_consultado_en === null) {
            return $vacio;
        }

        $usuario = trim((string) ($this->ultimo_usuario ?? ''));
        if ($usuario === '') {
            $usuario = 'sistema';
        }

        return $this->ultimo_consultado_en->format('d/m/Y H:i').' ('.$usuario.')';
    }

    /** Ganador Reicol o Romulo (grupo), según RUTs configurados en cotiz.php. */
    public function etiquetaGanadorGrupo(): ?string
    {
        $rut = trim((string) ($this->rut_ganador ?? ''));
        if ($rut === '') {
            return null;
        }

        return app(\App\Services\NotaMpResultadosService::class)->etiquetaGanadorPorRut($rut);
    }

    public function esGanadorGrupo(): bool
    {
        return $this->etiquetaGanadorGrupo() !== null;
    }

    /** Ganó la empresa de esta instancia (cotiz.empresa_rut). */
    public function esGanadorPropio(): bool
    {
        return app(\App\Services\NotaMpResultadosService::class)->esRutPropio(
            $this->rut_ganador !== null ? (string) $this->rut_ganador : null,
        );
    }

    public function estadoOrdenCompraMp(): ?EstadoOrdenCompraMp
    {
        return app(\App\Services\NotaMpResultadosService::class)->estadoOrdenCompra(
            (string) ($this->nota?->ocompra ?? ''),
            $this->id_orden_compra,
            $this->rut_ganador !== null ? (string) $this->rut_ganador : null,
            $this->estado_mp_codigo,
            $this->fecha_ultimo_cambio,
        );
    }

    /**
     * Código AG (ej. 1411-2423-AG26) si ganamos y ya está resuelto; si no, la etiqueta
     * del estado (OC por emitir, Buscando código OC, OC entregada a otra empresa…) o «—».
     */
    public function textoOrdenCompraMp(): string
    {
        $estado = $this->estadoOrdenCompraMp();

        return match ($estado) {
            null => '—',
            EstadoOrdenCompraMp::CODIGO => trim((string) ($this->nota?->ocompra ?? '')),
            default => $estado->etiqueta(),
        };
    }

    /** Valor para exportación CSV (vacío si no aplica); incluye la empresa si la OC es de otra. */
    public function valorOrdenCompraExport(): string
    {
        $texto = $this->textoOrdenCompraMp();
        if ($texto === '—') {
            return '';
        }

        $empresa = trim((string) ($this->razon_social_ganador ?? ''));
        if ($this->estadoOrdenCompraMp() === EstadoOrdenCompraMp::OTRA_EMPRESA && $empresa !== '') {
            return $texto.' ('.$empresa.')';
        }

        return $texto;
    }

    /**
     * Campos OC para respuestas JSON del detalle (modal).
     *
     * @return array{orden_compra: ?string, orden_compra_estado: ?string, orden_compra_texto: ?string}
     */
    public function ordenCompraParaJson(): array
    {
        $estado = $this->estadoOrdenCompraMp();

        return [
            'orden_compra' => $estado === EstadoOrdenCompraMp::CODIGO
                ? trim((string) ($this->nota?->ocompra ?? ''))
                : null,
            'orden_compra_estado' => $estado?->value,
            'orden_compra_texto' => $estado?->etiqueta(),
        ];
    }

    /** Aún debe poder consultarse MP (seguimiento abierto o falta código AG). */
    public function puedeReconsultarMp(): bool
    {
        if ($this->resultado_propio === 'pendiente') {
            return true;
        }

        if (! $this->finalizado) {
            return true;
        }

        return $this->estadoOrdenCompraMp() === EstadoOrdenCompraMp::BUSCANDO;
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
