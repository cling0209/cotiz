<?php

namespace App\Services;

use App\Jobs\ProcessOportunidadAdjuntoJob;
use App\Jobs\ProcessOportunidadAdjuntoPurgeJob;
use App\Models\OportunidadAdjuntoCorrida;
use App\Models\OportunidadEncontrada;
use App\Support\CorridaEstado;
use App\Support\HoraChile;
use App\Support\OportunidadPipelineEtapa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class OportunidadAdjuntoCorridaService
{
    public const ESTADO_RUNNING = 'running';

    public const ESTADO_COMPLETED = 'completed';

    public const ESTADO_CANCELLED = 'cancelled';

    public const ESTADO_ERROR = 'error';

    public const MOTIVO_SIN_PENDIENTES = 'No hay cotizaciones vigentes pendientes de adjuntos.';

    public const PURGE_PENDING = 'pending';

    public const PURGE_RUNNING = 'running';

    public const PURGE_COMPLETED = 'completed';

    public const PURGE_SKIPPED = 'skipped';

    public const PURGE_ERROR = 'error';

    private const PASO_PENDING = 'pending';

    private const PASO_RUNNING = 'running';

    private const PASO_OK = 'ok';

    private const PASO_FAILED = 'failed';

    private const PASO_CANCELLED = 'cancelled';

    private const MENSAJE_CANCELADA = 'Corrida de adjuntos cancelada por el usuario.';

    public function __construct(
        protected OportunidadAdjuntoService $adjuntos,
        protected OportunidadParaCotizarService $oportunidades,
    ) {}

    public function findCorrida(int $id): ?OportunidadAdjuntoCorrida
    {
        return OportunidadAdjuntoCorrida::query()->find($id);
    }

    public function corridaEnCurso(): ?OportunidadAdjuntoCorrida
    {
        return OportunidadAdjuntoCorrida::query()
            ->where('estado', self::ESTADO_RUNNING)
            ->latest('id')
            ->first();
    }

    public function ultimaCorrida(): ?OportunidadAdjuntoCorrida
    {
        return OportunidadAdjuntoCorrida::query()->latest('id')->first();
    }

    /**
     * Arranca la corrida de adjuntos tras sync de vinculaciones al par.
     */
    public function iniciarTrasSyncVinculacion(mixed $fechaBusqueda, string $usuario = 'sistema'): ?OportunidadAdjuntoCorrida
    {
        return $this->iniciarConDetalle($fechaBusqueda, $usuario)['corrida'];
    }

    /**
     * @return array{
     *   ok: bool,
     *   corrida: ?OportunidadAdjuntoCorrida,
     *   motivo: ?string,
     *   pendientes: int
     * }
     */
    public function iniciarConDetalle(mixed $fechaBusqueda, string $usuario = 'sistema'): array
    {
        if (! $this->adjuntos->isConfigured()) {
            return [
                'ok' => false,
                'corrida' => null,
                'motivo' => 'R2 adjuntos no configurado.',
                'pendientes' => 0,
            ];
        }

        $existente = $this->corridaEnCurso();
        if ($existente !== null) {
            $this->liberarCorridaColgadaIfNeeded($existente);
            $existente = $existente->fresh() ?? $existente;
            $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
            $this->agregarPasosPendientes($existente, $dia);

            return [
                'ok' => true,
                'corrida' => $existente->fresh() ?? $existente,
                'motivo' => null,
                'pendientes' => $this->contarPendientes($dia),
            ];
        }

        $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
        $pasos = $this->construirPlan($dia);
        $pendientes = count($pasos);
        if ($pasos === []) {
            return [
                'ok' => false,
                'corrida' => null,
                'motivo' => self::MOTIVO_SIN_PENDIENTES,
                'pendientes' => 0,
            ];
        }

        try {
            $corrida = OportunidadAdjuntoCorrida::query()->create([
                'usuario' => trim($usuario) ?: 'sistema',
                'fecha_busqueda' => $dia,
                'inicio' => now(),
                'estado' => self::ESTADO_RUNNING,
                'total_pasos' => count($pasos),
                'pasos_procesados' => 0,
                'pasos_fallidos' => 0,
                'plan_json' => $pasos,
                'errores_json' => [],
                'mensaje' => 'Adjuntos encolados tras sync al par ('.$this->formatearFecha($dia).').',
            ]);
        } catch (Throwable $e) {
            Log::error('OportunidadAdjuntoCorridaService: no se pudo crear corrida', [
                'fecha_busqueda' => $dia,
                'message' => $e->getMessage(),
            ]);

            $motivo = 'No se pudo iniciar adjuntos: '.$e->getMessage();
            $msg = mb_strtolower($e->getMessage());
            if (str_contains($msg, 'oportunidad_adjunto')
                || str_contains($msg, 'undefined table')
                || str_contains($msg, 'does not exist')
                || str_contains($msg, 'no such table')) {
                $motivo = 'Falta la migración de adjuntos en el servidor. Ejecute php artisan migrate.';
            }

            return [
                'ok' => false,
                'corrida' => null,
                'motivo' => $motivo,
                'pendientes' => $pendientes,
            ];
        }

        ProcessOportunidadAdjuntoJob::dispatch($corrida->id);

        return [
            'ok' => true,
            'corrida' => $corrida,
            'motivo' => null,
            'pendientes' => $pendientes,
        ];
    }

    public function contarPendientes(mixed $fechaBusqueda = null): int
    {
        if (! $this->adjuntos->isConfigured()) {
            return 0;
        }

        $dia = $this->oportunidades->normalizarFechaBusqueda(
            $fechaBusqueda ?? $this->oportunidades->fechaBusquedaHoy(),
        );

        return count($this->construirPlan($dia));
    }

    public function contarPendientesSafe(mixed $fechaBusqueda = null): int
    {
        try {
            return $this->contarPendientes($fechaBusqueda);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function construirPlan(string $dia): array
    {
        $desde = $this->oportunidades->normalizarFechaBusqueda(
            config('cotiz.mercadopublico.fecha_inicio_busqueda', '2026-07-14'),
        );
        $hasta = $this->oportunidades->normalizarFechaBusqueda($dia);
        $codigosTomados = $this->oportunidades->codigosTomadosNormalizados();
        $consultadosIndice = $this->consultadosIndiceSet();

        $rows = OportunidadEncontrada::query()
            ->whereDate('fecha_busqueda', '>=', $desde)
            ->whereDate('fecha_busqueda', '<=', $hasta)
            ->where(function ($query) {
                $query->whereRaw('vinculo_completo IS TRUE')
                    ->orWhereNotNull('vinculo_preview_json');
            })
            ->where(function ($query) {
                $query->whereNull('fecha_cierre')
                    ->orWhere('fecha_cierre', '>', now());
            })
            ->when(
                $codigosTomados !== [],
                fn ($query) => $query->whereNotIn('codigo', $codigosTomados),
            )
            ->orderBy('codigo')
            ->get(['codigo']);

        $pasos = [];
        $vistos = [];
        foreach ($rows as $row) {
            $codigo = strtoupper(trim((string) $row->codigo));
            if ($codigo === '' || isset($vistos[$codigo])) {
                continue;
            }
            if (isset($consultadosIndice[$codigo])) {
                continue;
            }
            if ($consultadosIndice === [] && $this->adjuntos->yaConsultado($codigo)) {
                continue;
            }
            $vistos[$codigo] = true;
            $pasos[] = [
                'codigo' => $codigo,
                'estado' => self::PASO_PENDING,
            ];
        }

        return $pasos;
    }

    /**
     * @return array<string, true>
     */
    private function consultadosIndiceSet(): array
    {
        if (! $this->adjuntos->isConfigured()) {
            return [];
        }

        try {
            $indice = $this->adjuntos->indicePorCodigo();
        } catch (Throwable) {
            return [];
        }

        $set = [];
        foreach ($indice['consultados'] ?? [] as $codigo) {
            $codigo = strtoupper(trim((string) $codigo));
            if ($codigo !== '') {
                $set[$codigo] = true;
            }
        }
        foreach (array_keys($indice['archivos'] ?? []) as $codigo) {
            $codigo = strtoupper(trim((string) $codigo));
            if ($codigo !== '') {
                $set[$codigo] = true;
            }
        }
        foreach ($indice['fallos'] ?? [] as $fallo) {
            if (! is_array($fallo)) {
                continue;
            }
            $codigo = strtoupper(trim((string) ($fallo['codigo'] ?? '')));
            if ($codigo !== '') {
                $set[$codigo] = true;
            }
        }

        return $set;
    }

    private function agregarPasosPendientes(OportunidadAdjuntoCorrida $corrida, string $dia): void
    {
        $nuevos = $this->construirPlan($dia);
        if ($nuevos === []) {
            return;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $ya = [];
        foreach ($pasos as $paso) {
            $codigo = strtoupper(trim((string) ($paso['codigo'] ?? '')));
            if ($codigo !== '') {
                $ya[$codigo] = true;
            }
        }

        $agregados = 0;
        foreach ($nuevos as $paso) {
            $codigo = strtoupper(trim((string) ($paso['codigo'] ?? '')));
            if ($codigo === '' || isset($ya[$codigo])) {
                continue;
            }
            $pasos[] = $paso;
            $ya[$codigo] = true;
            $agregados++;
        }

        if ($agregados === 0) {
            return;
        }

        $corrida->fill([
            'plan_json' => $pasos,
            'total_pasos' => count($pasos),
            'mensaje' => 'Se agregaron '.$agregados.' cotización(es) al plan de adjuntos.',
        ])->save();
    }

    public function procesarPaso(OportunidadAdjuntoCorrida $corrida): bool
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $indice = $this->indiceSiguientePendiente($pasos);
        if ($indice === null) {
            $this->finalizar($corrida);

            return false;
        }

        $paso = $pasos[$indice];
        $codigo = strtoupper(trim((string) ($paso['codigo'] ?? '')));
        $pasos[$indice]['estado'] = self::PASO_RUNNING;
        $pasos[$indice]['inicio'] = now()->toIso8601String();
        $corrida->fill([
            'plan_json' => $pasos,
            'mensaje' => 'Adjuntos '.$codigo.' ('.($indice + 1).'/'.count($pasos).')…',
        ])->save();

        $inicioMs = microtime(true);
        try {
            if ($codigo === '') {
                throw new RuntimeException('Código vacío.');
            }
            if ($this->adjuntos->yaConsultado($codigo)) {
                $resultado = [
                    'guardados' => 0,
                    'sin_adjuntos' => true,
                ];
            } else {
                $resultado = $this->adjuntos->buscarYGuardar($codigo);
            }

            $pasos = is_array($corrida->fresh()?->plan_json) ? $corrida->fresh()->plan_json : $pasos;
            $pasos[$indice]['estado'] = self::PASO_OK;
            $pasos[$indice]['fin'] = now()->toIso8601String();
            $pasos[$indice]['duracion_ms'] = (int) round((microtime(true) - $inicioMs) * 1000);
            $pasos[$indice]['guardados'] = (int) ($resultado['guardados'] ?? 0);
            $pasos[$indice]['sin_adjuntos'] = (bool) ($resultado['sin_adjuntos'] ?? false);
        } catch (Throwable $e) {
            Log::warning('OportunidadAdjuntoCorridaService: fallo al buscar adjuntos', [
                'codigo' => $codigo,
                'message' => $e->getMessage(),
            ]);
            $this->adjuntos->registrarFallo($codigo, $e->getMessage());
            $pasos = is_array($corrida->fresh()?->plan_json) ? $corrida->fresh()->plan_json : $pasos;
            $pasos[$indice]['estado'] = self::PASO_FAILED;
            $pasos[$indice]['fin'] = now()->toIso8601String();
            $pasos[$indice]['error'] = mb_substr($e->getMessage(), 0, 240);
            $pasos[$indice]['duracion_ms'] = (int) round((microtime(true) - $inicioMs) * 1000);

            $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
            $errores[] = [
                'codigo' => $codigo !== '' ? $codigo : null,
                'error' => $e->getMessage(),
                'at' => now()->toIso8601String(),
            ];
            $corrida->errores_json = $errores;
            $corrida->pasos_fallidos = (int) $corrida->pasos_fallidos + 1;
        }

        $terminados = $this->contarTerminados($pasos);
        $corrida->fill([
            'plan_json' => $pasos,
            'pasos_procesados' => $terminados,
            'mensaje' => 'Adjuntos '.$terminados.'/'.count($pasos).'…',
        ])->save();

        if ($this->indiceSiguientePendiente($pasos) === null) {
            $this->finalizar($corrida->fresh() ?? $corrida);

            return false;
        }

        return true;
    }

    public function cancelar(?OportunidadAdjuntoCorrida $corrida = null): ?OportunidadAdjuntoCorrida
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null) {
            return null;
        }

        $this->eliminarJobsAdjunto($corrida->id);

        $fin = now();
        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        foreach ($pasos as $i => $paso) {
            if (! is_array($paso)) {
                continue;
            }
            $estadoPaso = (string) ($paso['estado'] ?? self::PASO_PENDING);
            if ($estadoPaso === self::PASO_RUNNING || $estadoPaso === self::PASO_PENDING) {
                $pasos[$i]['estado'] = self::PASO_CANCELLED;
                $pasos[$i]['fin'] = $fin->toIso8601String();
            }
        }

        $corrida->fill([
            'estado' => self::ESTADO_CANCELLED,
            'fin' => $fin,
            'plan_json' => array_values($pasos),
            'pasos_procesados' => $this->contarTerminados($pasos),
            'mensaje' => self::MENSAJE_CANCELADA.' Tiempo: '.$this->formatearDuracion($corrida->inicio, $fin),
        ])->save();

        return $corrida->fresh() ?? $corrida;
    }

    public function liberarCorridaColgadaIfNeeded(?OportunidadAdjuntoCorrida $corrida = null, bool $forzar = false): bool
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null || $corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $stalledSeg = max(60, (int) config('cotiz.mercadopublico.oportunidad_corrida_stalled_segundos', 180));
        $jobReservadoSeg = max(
            15,
            (int) config('cotiz.mercadopublico.oportunidad_job_reservado_stalled_segundos', 45),
        );
        $corridaStalled = $corrida->updated_at !== null
            && $corrida->updated_at->lt(now()->subSeconds($stalledSeg));
        $jobReservadoColgado = $this->jobAdjuntoReservadoColgado($corrida->id, $forzar ? 0 : $jobReservadoSeg);

        if (! $forzar && ! $corridaStalled && ! $jobReservadoColgado) {
            return false;
        }

        if ($this->jobAdjuntoEncolado($corrida->id) && ! $forzar && ! $jobReservadoColgado) {
            return false;
        }

        $this->eliminarJobsAdjunto($corrida->id);

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        foreach ($pasos as $i => $paso) {
            if (($paso['estado'] ?? '') === self::PASO_RUNNING) {
                $pasos[$i]['estado'] = self::PASO_PENDING;
                unset($pasos[$i]['inicio']);
            }
        }

        $terminados = $this->contarTerminados($pasos);
        $corrida->fill([
            'plan_json' => $pasos,
            'pasos_procesados' => $terminados,
            'mensaje' => $forzar
                ? 'Adjuntos retomados tras deploy (paso '.($terminados + 1).'/'.max(1, (int) $corrida->total_pasos).').'
                : 'Adjuntos retomados automáticamente (paso '.($terminados + 1).'/'.max(1, (int) $corrida->total_pasos).').',
        ])->save();

        if ($this->indiceSiguientePendiente($pasos) === null) {
            $this->finalizar($corrida->fresh() ?? $corrida);

            return true;
        }

        ProcessOportunidadAdjuntoJob::dispatch($corrida->id);

        return true;
    }

    /**
     * @return array{accion: string, mensaje: string, corrida_id: ?int}
     */
    public function retomarCorridaActiva(bool $forzar = false): array
    {
        $corrida = $this->corridaEnCurso();
        if ($corrida === null) {
            $purge = $this->retomarPurgeSiQuedoColgado($forzar);

            return [
                'accion' => $purge ? 'reanudada' : 'omitido',
                'mensaje' => $purge
                    ? 'Limpieza de adjuntos cerrados reanudada'.($forzar ? ' tras deploy' : '').'.'
                    : 'Sin corrida de adjuntos en curso.',
                'corrida_id' => $this->ultimaCorrida()?->id,
            ];
        }

        $reanudada = $this->liberarCorridaColgadaIfNeeded($corrida, $forzar);
        if (! $reanudada && ! $this->jobAdjuntoEncolado($corrida->id)) {
            ProcessOportunidadAdjuntoJob::dispatch($corrida->id);
            $reanudada = true;
        }

        return [
            'accion' => $reanudada ? 'reanudada' : 'en_curso',
            'mensaje' => $reanudada
                ? 'Adjuntos reanudados'.($forzar ? ' tras deploy' : '').'.'
                : 'Adjuntos en curso.',
            'corrida_id' => $corrida->id,
        ];
    }

    public function jobAdjuntoReservadoColgado(int $corridaId, int $segundos): bool
    {
        if (! Schema::hasTable('jobs') || $segundos < 0) {
            return false;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessOportunidadAdjuntoJob%')
            ->whereNotNull('reserved_at');

        if ($segundos > 0) {
            $query->where('reserved_at', '<', now()->subSeconds($segundos)->getTimestamp());
        }

        return (int) $this->filtrarJobsAdjuntoPorCorrida($query, $corridaId)->count() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function estado(?OportunidadAdjuntoCorrida $corrida = null): ?array
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return null;
        }

        if ($corrida->estado === self::ESTADO_RUNNING) {
            try {
                $this->liberarCorridaColgadaIfNeeded($corrida);
                $corrida = $corrida->fresh() ?? $corrida;
            } catch (Throwable $e) {
                Log::warning('OportunidadAdjuntoCorrida: no se pudo liberar corrida colgada', [
                    'corrida_id' => $corrida->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $total = max(0, (int) $corrida->total_pasos);
        $terminados = $this->contarTerminados($pasos);
        $duracionSegundos = $this->duracionSegundos(
            $corrida->inicio,
            $corrida->fin ?? ($corrida->estado === self::ESTADO_RUNNING ? now() : null),
        );
        $recientes = OportunidadPipelineEtapa::ultimosPasosDelPlan($pasos);
        $cierre = null;
        if ($corrida->estado === self::ESTADO_COMPLETED && $this->indiceSiguientePendiente($pasos) === null) {
            $cierre = OportunidadPipelineEtapa::cierreAdjuntos((int) $corrida->pasos_fallidos);
        }
        $pasoActual = $this->pasoActualEnPlan(
            $pasos,
            $corrida->estado === self::ESTADO_RUNNING,
        );
        $ultimaActividad = $this->ultimaActividadIso($pasos, $corrida);

        return [
            'id' => $corrida->id,
            'estado' => $corrida->estado,
            'usuario' => trim((string) ($corrida->usuario ?? 'sistema')) ?: 'sistema',
            'fecha_busqueda' => $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda),
            'inicio' => $corrida->inicio?->toIso8601String(),
            'fin' => $corrida->fin?->toIso8601String(),
            'duracion_segundos' => $duracionSegundos,
            'duracion_texto' => $duracionSegundos !== null ? $this->formatearSegundos($duracionSegundos) : null,
            'total_pasos' => $total,
            'pasos_procesados' => $terminados,
            'pasos_fallidos' => (int) $corrida->pasos_fallidos,
            'progreso' => $total > 0 ? min(100, (int) round(($terminados / $total) * 100)) : 0,
            'paso_actual' => $pasoActual,
            'ultima_actividad' => $ultimaActividad,
            'mensaje' => $corrida->mensaje,
            'ultimo_error' => CorridaEstado::ultimoError($corrida->errores_json),
            'recientes' => $recientes,
            'cierre' => $cierre,
            'purge' => $this->serializarPurge($corrida),
        ];
    }

    public function purgeEnCurso(): bool
    {
        if ($this->jobPurgeEncolado()) {
            return true;
        }

        $corrida = $this->ultimaCorrida();
        if ($corrida === null) {
            return false;
        }
        $estado = (string) (($corrida->purge_json ?? [])['estado'] ?? '');

        return in_array($estado, [self::PURGE_PENDING, self::PURGE_RUNNING], true);
    }

    /**
     * Encola la limpieza de adjuntos cerrados y, al terminar el job, sigue el pipeline.
     */
    public function encolarPurgeYContinuarPipeline(
        mixed $fechaBusqueda,
        string $usuario = 'sistema',
        ?int $corridaId = null,
    ): void {
        $usuario = trim($usuario) ?: 'sistema';
        $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
        $corrida = $corridaId !== null ? $this->findCorrida($corridaId) : $this->ultimaCorrida();

        if (! $this->adjuntos->isConfigured()) {
            $this->persistirPurge($corrida, [
                'estado' => self::PURGE_SKIPPED,
                'inicio' => now()->toIso8601String(),
                'fin' => now()->toIso8601String(),
                'eliminados' => 0,
                'omitidos' => 0,
                'fallos' => 0,
                'mensaje' => 'Limpieza omitida: R2 adjuntos no configurado.',
            ]);
            app(OportunidadBusquedaService::class)->continuarPipelineTrasPurge($dia, $usuario);

            return;
        }

        if ($this->jobPurgeEncolado($corrida?->id)) {
            return;
        }

        $this->persistirPurge($corrida, [
            'estado' => self::PURGE_PENDING,
            'inicio' => null,
            'fin' => null,
            'eliminados' => 0,
            'omitidos' => 0,
            'fallos' => 0,
            'mensaje' => 'Limpieza de adjuntos cerrados encolada.',
        ]);

        ProcessOportunidadAdjuntoPurgeJob::dispatch($dia, $usuario, $corrida?->id);
    }

    /**
     * @return array{
     *   eliminados: int,
     *   omitidos: int,
     *   fallos: int,
     *   revisados: int
     * }
     */
    public function ejecutarPurgeCerrados(?OportunidadAdjuntoCorrida $corrida = null): array
    {
        $inicio = now();

        $protegidos = array_fill_keys(
            array_merge(
                $this->oportunidades->codigosVigentesUnicos(),
                $this->oportunidades->codigosTomadosNormalizados(),
            ),
            true,
        );

        $cerradas = OportunidadEncontrada::query()
            ->whereNotNull('fecha_cierre')
            ->where('fecha_cierre', '<=', now())
            ->pluck('codigo')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter(fn ($c) => $c !== '')
            ->unique()
            ->values();

        $omitidos = 0;
        $candidatos = [];
        foreach ($cerradas as $codigo) {
            if (isset($protegidos[$codigo])) {
                if ($this->adjuntos->carpetaTieneObjetos($codigo)) {
                    $omitidos++;
                }

                continue;
            }
            if (! $this->adjuntos->carpetaTieneObjetos($codigo)) {
                continue;
            }
            $candidatos[] = $codigo;
        }

        $total = count($candidatos);
        $this->persistirPurge($corrida, [
            'estado' => self::PURGE_RUNNING,
            'inicio' => $inicio->toIso8601String(),
            'fin' => null,
            'eliminados' => 0,
            'omitidos' => $omitidos,
            'fallos' => 0,
            'revisados' => 0,
            'total' => $total,
            'indice' => 0,
            'codigo_actual' => null,
            'ultimos_eliminados' => [],
            'mensaje' => $total > 0
                ? 'Limpieza de adjuntos cerrados (0/'.$total.')…'
                : 'Sin carpetas cerradas para eliminar.',
        ]);

        $eliminados = 0;
        $fallos = 0;
        $revisados = 0;
        $ultimosEliminados = [];

        if ($total === 0) {
            $fin = now();
            $mensaje = $this->mensajePurge($eliminados, $omitidos, $fallos, $inicio, $fin);
            $this->persistirPurge($corrida, [
                'estado' => self::PURGE_COMPLETED,
                'inicio' => $inicio->toIso8601String(),
                'fin' => $fin->toIso8601String(),
                'eliminados' => 0,
                'omitidos' => $omitidos,
                'fallos' => 0,
                'revisados' => 0,
                'total' => 0,
                'indice' => 0,
                'codigo_actual' => null,
                'ultimos_eliminados' => [],
                'mensaje' => $mensaje,
            ]);

            return [
                'eliminados' => 0,
                'omitidos' => $omitidos,
                'fallos' => 0,
                'revisados' => 0,
            ];
        }

        foreach ($candidatos as $i => $codigo) {
            $this->persistirPurge($corrida, [
                'estado' => self::PURGE_RUNNING,
                'inicio' => $inicio->toIso8601String(),
                'fin' => null,
                'eliminados' => $eliminados,
                'omitidos' => $omitidos,
                'fallos' => $fallos,
                'revisados' => $revisados,
                'total' => $total,
                'indice' => $i + 1,
                'codigo_actual' => $codigo,
                'ultimos_eliminados' => $ultimosEliminados,
                'mensaje' => 'Eliminando '.$codigo.' ('.($i + 1).'/'.$total.')…',
            ]);

            $revisados++;
            if ($this->adjuntos->eliminarCarpeta($codigo)) {
                $eliminados++;
                array_unshift($ultimosEliminados, [
                    'codigo' => $codigo,
                    'at' => now()->toIso8601String(),
                ]);
                $ultimosEliminados = array_slice($ultimosEliminados, 0, 5);
            } else {
                $fallos++;
            }
        }

        $fin = now();
        $mensaje = $this->mensajePurge($eliminados, $omitidos, $fallos, $inicio, $fin);
        $this->persistirPurge($corrida, [
            'estado' => $fallos > 0 ? self::PURGE_ERROR : self::PURGE_COMPLETED,
            'inicio' => $inicio->toIso8601String(),
            'fin' => $fin->toIso8601String(),
            'eliminados' => $eliminados,
            'omitidos' => $omitidos,
            'fallos' => $fallos,
            'revisados' => $revisados,
            'total' => $total,
            'indice' => $total,
            'codigo_actual' => null,
            'ultimos_eliminados' => $ultimosEliminados,
            'mensaje' => $mensaje,
            'ultimo_error' => $fallos > 0
                ? 'No se pudo eliminar '.$fallos.' carpeta'.($fallos === 1 ? '' : 's').' de adjuntos cerrados.'
                : null,
        ]);

        Log::info('OportunidadAdjunto: purge de cerradas', [
            'corrida_id' => $corrida?->id,
            'eliminados' => $eliminados,
            'omitidos' => $omitidos,
            'fallos' => $fallos,
        ]);

        return [
            'eliminados' => $eliminados,
            'omitidos' => $omitidos,
            'fallos' => $fallos,
            'revisados' => $revisados,
        ];
    }

    public function marcarPurgeError(?OportunidadAdjuntoCorrida $corrida, string $mensaje): void
    {
        $actual = is_array($corrida?->purge_json) ? $corrida->purge_json : [];
        $inicio = $actual['inicio'] ?? now()->toIso8601String();
        $fin = now();
        $this->persistirPurge($corrida, [
            'estado' => self::PURGE_ERROR,
            'inicio' => $inicio,
            'fin' => $fin->toIso8601String(),
            'eliminados' => (int) ($actual['eliminados'] ?? 0),
            'omitidos' => (int) ($actual['omitidos'] ?? 0),
            'fallos' => (int) ($actual['fallos'] ?? 0) + 1,
            'mensaje' => 'Limpieza con error: '.mb_substr(trim($mensaje), 0, 200)
                .'. Inicio '.HoraChile::format($inicio, 'H:i')
                .' — Fin '.HoraChile::format($fin, 'H:i').'.',
            'ultimo_error' => mb_substr(trim($mensaje), 0, 400),
        ]);
    }

    public function retomarPurgeSiQuedoColgado(bool $forzar = false): bool
    {
        $corrida = $this->ultimaCorrida();
        if ($corrida === null) {
            return false;
        }
        $estado = (string) (($corrida->purge_json ?? [])['estado'] ?? '');
        if (! in_array($estado, [self::PURGE_PENDING, self::PURGE_RUNNING], true)) {
            return false;
        }
        if (! $forzar && $this->jobPurgeEncolado($corrida->id)) {
            return false;
        }

        $usuario = trim((string) ($corrida->usuario ?? 'sistema')) ?: 'sistema';
        $dia = $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda);
        ProcessOportunidadAdjuntoPurgeJob::dispatch($dia, $usuario, $corrida->id);

        return true;
    }

    private function finalizar(OportunidadAdjuntoCorrida $corrida): void
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $fallidos = count(array_filter($pasos, static fn ($p) => ($p['estado'] ?? '') === self::PASO_FAILED));
        $fin = now();
        $tiempo = $this->formatearDuracion($corrida->inicio, $fin);

        $corrida->fill([
            'estado' => self::ESTADO_COMPLETED,
            'fin' => $fin,
            'pasos_procesados' => $this->contarTerminados($pasos),
            'pasos_fallidos' => $fallidos,
            'mensaje' => $fallidos > 0
                ? 'Adjuntos terminados con '.$fallidos.' fallo(s). Tiempo: '.$tiempo
                : 'Adjuntos terminados correctamente. Tiempo: '.$tiempo,
        ])->save();

        try {
            app(OportunidadBusquedaService::class)->continuarPipelineTrasAdjuntos(
                $corrida->fecha_busqueda,
                trim((string) ($corrida->usuario ?? 'sistema')) ?: 'sistema',
                $corrida->id,
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo continuar el pipeline tras adjuntos', [
                'corrida_id' => $corrida->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function jobAdjuntoEncolado(int $corridaId): bool
    {
        return $this->contarJobsAdjuntoPendientes($corridaId) > 0
            || $this->contarJobsAdjuntoReservados($corridaId) > 0;
    }

    public function contarJobsAdjuntoPendientes(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessOportunidadAdjuntoJob%')
            ->whereNull('reserved_at');

        return (int) $this->filtrarJobsAdjuntoPorCorrida($query, $corridaId)->count();
    }

    public function contarJobsAdjuntoReservados(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessOportunidadAdjuntoJob%')
            ->whereNotNull('reserved_at');

        return (int) $this->filtrarJobsAdjuntoPorCorrida($query, $corridaId)->count();
    }

    public function eliminarJobsAdjunto(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')->where('payload', 'like', '%ProcessOportunidadAdjuntoJob%');

        return $this->filtrarJobsAdjuntoPorCorrida($query, $corridaId)->delete();
    }

    private function filtrarJobsAdjuntoPorCorrida(\Illuminate\Database\Query\Builder $query, ?int $corridaId): \Illuminate\Database\Query\Builder
    {
        if ($corridaId === null) {
            return $query;
        }

        return $query->where('payload', 'like', '%i:'.$corridaId.';%');
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     */
    private function indiceSiguientePendiente(array $pasos): ?int
    {
        foreach ($pasos as $i => $paso) {
            if (($paso['estado'] ?? self::PASO_PENDING) === self::PASO_PENDING) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     */
    private function contarTerminados(array $pasos): int
    {
        $n = 0;
        foreach ($pasos as $paso) {
            $estado = $paso['estado'] ?? self::PASO_PENDING;
            if (in_array($estado, [self::PASO_OK, self::PASO_FAILED], true)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Paso en curso (running) o siguiente pendiente si la corrida sigue activa.
     *
     * @param  list<array<string, mixed>>  $pasos
     * @return array{codigo: string, indice: int, total: int, inicio: string|null, estado: string}|null
     */
    private function pasoActualEnPlan(array $pasos, bool $corridaRunning): ?array
    {
        if ($pasos === []) {
            return null;
        }

        $total = count($pasos);
        foreach ($pasos as $i => $paso) {
            if (! is_array($paso)) {
                continue;
            }
            if (($paso['estado'] ?? '') === self::PASO_RUNNING) {
                return [
                    'codigo' => strtoupper(trim((string) ($paso['codigo'] ?? ''))),
                    'indice' => (int) $i + 1,
                    'total' => $total,
                    'inicio' => isset($paso['inicio']) ? (string) $paso['inicio'] : null,
                    'estado' => self::PASO_RUNNING,
                ];
            }
        }

        if (! $corridaRunning) {
            return null;
        }

        $indice = $this->indiceSiguientePendiente($pasos);
        if ($indice === null) {
            return null;
        }

        $paso = $pasos[$indice];

        return [
            'codigo' => strtoupper(trim((string) ($paso['codigo'] ?? ''))),
            'indice' => $indice + 1,
            'total' => $total,
            'inicio' => null,
            'estado' => self::PASO_PENDING,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     */
    private function ultimaActividadIso(array $pasos, OportunidadAdjuntoCorrida $corrida): ?string
    {
        $mejor = null;
        $mejorTs = null;

        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            foreach (['fin', 'inicio'] as $campo) {
                $iso = $paso[$campo] ?? null;
                if (! is_string($iso) || trim($iso) === '') {
                    continue;
                }
                try {
                    $ts = Carbon::parse($iso)->getTimestamp();
                } catch (Throwable) {
                    continue;
                }
                if ($mejorTs === null || $ts > $mejorTs) {
                    $mejorTs = $ts;
                    $mejor = $iso;
                }
            }
        }

        if ($mejor !== null) {
            return $mejor;
        }

        return $corrida->updated_at?->toIso8601String();
    }

    private function duracionSegundos(mixed $inicio, mixed $fin): ?int
    {
        if ($inicio === null || $fin === null) {
            return null;
        }

        try {
            $from = $inicio instanceof Carbon ? $inicio : Carbon::parse($inicio);
            $to = $fin instanceof Carbon ? $fin : Carbon::parse($fin);

            return max(0, (int) $from->diffInSeconds($to));
        } catch (Throwable) {
            return null;
        }
    }

    private function formatearDuracion(mixed $inicio, mixed $fin): string
    {
        $segs = $this->duracionSegundos($inicio, $fin);
        if ($segs === null) {
            return '—';
        }

        return $this->formatearSegundos($segs);
    }

    private function formatearSegundos(int $segs): string
    {
        $h = intdiv($segs, 3600);
        $m = intdiv($segs % 3600, 60);
        $s = $segs % 60;
        if ($h > 0) {
            return sprintf('%dh %02dm %02ds', $h, $m, $s);
        }
        if ($m > 0) {
            return sprintf('%dm %02ds', $m, $s);
        }

        return sprintf('%ds', $s);
    }

    private function formatearFecha(string $dia): string
    {
        try {
            return Carbon::parse($dia)->format('d-m-Y');
        } catch (Throwable) {
            return $dia;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistirPurge(?OportunidadAdjuntoCorrida $corrida, array $payload): void
    {
        if ($corrida === null || ! Schema::hasColumn($corrida->getTable(), 'purge_json')) {
            return;
        }

        $corrida->fill(['purge_json' => $payload])->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializarPurge(OportunidadAdjuntoCorrida $corrida): ?array
    {
        if (! Schema::hasColumn($corrida->getTable(), 'purge_json')) {
            return null;
        }
        $purge = $corrida->purge_json;
        if (! is_array($purge) || $purge === []) {
            return null;
        }

        $inicio = $purge['inicio'] ?? null;
        $fin = $purge['fin'] ?? null;
        $estadoPurge = (string) ($purge['estado'] ?? '');
        $finParaDuracion = $fin;
        if ($finParaDuracion === null && in_array($estadoPurge, [self::PURGE_PENDING, self::PURGE_RUNNING], true) && $inicio) {
            $finParaDuracion = now();
        }
        $duracionSegundos = $this->duracionSegundos($inicio, $finParaDuracion);
        $ultimoError = trim((string) ($purge['ultimo_error'] ?? ''));
        if ($ultimoError === '' && (int) ($purge['fallos'] ?? 0) > 0) {
            $ultimoError = trim((string) ($purge['mensaje'] ?? ''));
        }

        $ultimosEliminados = is_array($purge['ultimos_eliminados'] ?? null)
            ? array_values($purge['ultimos_eliminados'])
            : [];
        $cierre = null;
        if (in_array($estadoPurge, [self::PURGE_COMPLETED, self::PURGE_SKIPPED, self::PURGE_ERROR], true)) {
            $cierre = OportunidadPipelineEtapa::cierrePurge();
        }
        $pasoActual = $this->pasoActualPurge($purge, $estadoPurge);
        $ultimaActividad = $this->ultimaActividadPurge($purge, $fin);

        return [
            'estado' => $estadoPurge,
            'inicio' => $inicio,
            'fin' => $fin,
            'inicio_hora' => $inicio ? HoraChile::format($inicio, 'H:i') : null,
            'fin_hora' => $fin ? HoraChile::format($fin, 'H:i') : null,
            'inicio_texto' => $inicio ? HoraChile::format($inicio) : null,
            'fin_texto' => $fin ? HoraChile::format($fin) : null,
            'eliminados' => (int) ($purge['eliminados'] ?? 0),
            'omitidos' => (int) ($purge['omitidos'] ?? 0),
            'fallos' => (int) ($purge['fallos'] ?? 0),
            'revisados' => (int) ($purge['revisados'] ?? 0),
            'total_pasos' => (int) ($purge['total'] ?? 0),
            'paso_actual' => $pasoActual,
            'ultima_actividad' => $ultimaActividad,
            'duracion_segundos' => $duracionSegundos,
            'duracion_texto' => $duracionSegundos !== null ? $this->formatearSegundos($duracionSegundos) : null,
            'mensaje' => (string) ($purge['mensaje'] ?? ''),
            'ultimo_error' => $ultimoError !== '' ? $ultimoError : null,
            'ultimos_eliminados' => $ultimosEliminados,
            'cierre' => $cierre,
        ];
    }

    /**
     * @param  array<string, mixed>  $purge
     * @return array{codigo: string, indice: int, total: int, estado: string}|null
     */
    private function pasoActualPurge(array $purge, string $estadoPurge): ?array
    {
        if (! in_array($estadoPurge, [self::PURGE_PENDING, self::PURGE_RUNNING], true)) {
            return null;
        }

        $codigo = strtoupper(trim((string) ($purge['codigo_actual'] ?? '')));
        $total = (int) ($purge['total'] ?? 0);
        $indice = (int) ($purge['indice'] ?? 0);

        if ($codigo === '' && $estadoPurge === self::PURGE_PENDING) {
            return null;
        }

        if ($codigo === '' && $total === 0) {
            return null;
        }

        return [
            'codigo' => $codigo !== '' ? $codigo : '—',
            'indice' => max(0, $indice),
            'total' => max(0, $total),
            'estado' => $estadoPurge === self::PURGE_RUNNING ? 'running' : 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $purge
     */
    private function ultimaActividadPurge(array $purge, mixed $fin): ?string
    {
        $ultimos = is_array($purge['ultimos_eliminados'] ?? null) ? $purge['ultimos_eliminados'] : [];
        if ($ultimos !== [] && is_array($ultimos[0])) {
            $at = $ultimos[0]['at'] ?? null;
            if (is_string($at) && trim($at) !== '') {
                return $at;
            }
        }

        if ($fin !== null && $fin !== '') {
            return is_string($fin) ? $fin : null;
        }

        $inicio = $purge['inicio'] ?? null;

        return is_string($inicio) && trim($inicio) !== '' ? $inicio : null;
    }

    private function mensajePurge(int $eliminados, int $omitidos, int $fallos, mixed $inicio, mixed $fin): string
    {
        $horaInicio = HoraChile::format($inicio, 'H:i');
        $horaFin = HoraChile::format($fin, 'H:i');
        $tiempo = $this->formatearDuracion($inicio, $fin);
        $msg = 'Limpieza: eliminó '.$eliminados
            .($eliminados === 1 ? ' carpeta' : ' carpetas')
            .'. Inicio '.$horaInicio.' — Fin '.$horaFin.' ('.$tiempo.')';
        if ($omitidos > 0) {
            $msg .= '. '.$omitidos.' omitida'.($omitidos === 1 ? '' : 's').' (tomada/vigente)';
        }
        if ($fallos > 0) {
            $msg .= '. '.$fallos.' fallo'.($fallos === 1 ? '' : 's');
        }

        return $msg;
    }

    public function jobPurgeEncolado(?int $corridaId = null): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        $query = DB::table('jobs')->where('payload', 'like', '%ProcessOportunidadAdjuntoPurgeJob%');

        return (int) $this->filtrarJobsAdjuntoPorCorrida($query, $corridaId)->count() > 0;
    }
}
