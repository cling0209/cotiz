<?php

namespace App\Services;

use App\Jobs\ProcessOportunidadAdjuntoJob;
use App\Models\OportunidadAdjuntoCorrida;
use App\Models\OportunidadEncontrada;
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
            if ($this->adjuntos->yaConsultado($codigo)) {
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
            return [
                'accion' => 'omitido',
                'mensaje' => 'Sin corrida de adjuntos en curso.',
                'corrida_id' => null,
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
            'mensaje' => $corrida->mensaje,
        ];
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
}
