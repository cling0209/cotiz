<?php

namespace App\Services;

use App\Jobs\ProcessNotaMpCorridaJob;
use App\Models\Nota;
use App\Models\NotaMpCorrida;
use App\Models\NotaMpCorridaCambio;
use App\Models\NotaMpCorridaDetalle;
use App\Models\NotaMpOferta;
use App\Models\NotaMpOfertaLinea;
use App\Models\NotaMpSeguimiento;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class NotaMpResultadosService
{
    private const LIMITE_CORRIDA_MAX = 10000;

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilGanadorResolver $ganador,
        protected CompraAgilTextoParserService $parser,
    ) {}

    public function ultimaCorrida(): ?NotaMpCorrida
    {
        return NotaMpCorrida::query()
            ->masivas()
            ->whereIn('estado', ['ok', 'error', 'cancelled'])
            ->orderByDesc('id')
            ->first();
    }

    /** Corrida masiva en curso (worker). Ignora consultas individuales por fila. */
    public function corridaEnCurso(): ?NotaMpCorrida
    {
        $corrida = NotaMpCorrida::query()
            ->masivas()
            ->where('estado', 'running')
            ->orderByDesc('id')
            ->first();

        if ($corrida === null) {
            return null;
        }

        if ($this->liberarNotaColgadaIfNeeded($corrida)) {
            $corrida = $corrida->fresh() ?? $corrida;
        }

        if ($this->liberarCorridaColgadaIfNeeded($corrida)) {
            return null;
        }

        return $corrida->fresh() ?? $corrida;
    }

    /**
     * Si una nota supera el tiempo máximo sin avanzar, registra fallo y encola la siguiente.
     */
    public function liberarNotaColgadaIfNeeded(NotaMpCorrida $corrida): bool
    {
        if ($corrida->estado !== 'running' || ! filled($corrida->codigo_actual)) {
            return false;
        }

        // Con worker activo en esta corrida, el job registra el fallo; evita duplicar error por polling.
        if ($this->contarJobsResultadosMpReservados($corrida->id) > 0) {
            return false;
        }

        $segundosEnNota = $this->segundosEnNotaActual($corrida);
        if ($segundosEnNota === null) {
            return false;
        }
        $umbral = $this->notaMaxSegundos() + 30;

        if ($segundosEnNota < $umbral) {
            return false;
        }

        $lote = $this->enCursoActual($corrida);
        if ($lote === []) {
            $lote = array_slice($this->lotePendienteActual($corrida, 1), 0, 1);
        }
        if ($lote === []) {
            return false;
        }

        // Completar datos de empresa desde pendientes_json si falta.
        $pendientes = is_array($corrida->pendientes_json) ? $corrida->pendientes_json : [];
        $empresaPorNota = [];
        foreach ($pendientes as $item) {
            if (! is_array($item)) {
                continue;
            }
            $nr = (int) ($item['nronota'] ?? 0);
            if ($nr > 0) {
                $empresaPorNota[$nr] = trim((string) ($item['empresa'] ?? ''));
            }
        }

        Log::warning('NotaMpResultados: lote colgado recuperado automáticamente', [
            'corrida_id' => $corrida->id,
            'lote' => $lote,
            'segundos' => $segundosEnNota,
        ]);

        foreach ($lote as $item) {
            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = trim((string) ($item['codigo'] ?? $corrida->codigo_actual));
            if ($nronota <= 0) {
                continue;
            }
            $empresa = $empresaPorNota[$nronota] ?? '';
            $this->registrarDetalleFallo(
                $corrida,
                $nronota,
                $codigo,
                self::mensajeTiempoMaximoNota().' ('.$this->notaMaxSegundos().' s, recuperación automática).',
                $empresa !== '' ? $empresa : null,
            );
            $corrida->increment('notas_procesadas');
        }

        $corrida->refresh();

        $this->eliminarJobsResultadosMpPendientes($corrida->id);

        $pendientesRestantes = count($pendientes);
        if ((int) $corrida->notas_procesadas >= $pendientesRestantes) {
            $fallidas = (int) NotaMpCorridaDetalle::query()
                ->where('corrida_id', $corrida->id)
                ->whereRaw('exito IS FALSE')
                ->count();
            $this->finalizarCorridaDesdeJob($corrida, $fallidas, self::mensajeTiempoMaximoNota());

            return true;
        }

        $this->marcarSiguienteNotaPendiente($corrida);

        if (! $this->jobResultadosMpEncolado($corrida->id)) {
            ProcessNotaMpCorridaJob::dispatch($corrida->id);
        }

        return true;
    }

    public function liberarCorridaColgadaIfNeeded(NotaMpCorrida $corrida): bool
    {
        if ($corrida->estado !== 'running') {
            return false;
        }

        $segundos = (int) $corrida->inicio->diffInSeconds(now());
        $umbralBase = max(300, (int) config('cotiz.mercadopublico.resultados_corrida_colgada_segundos', 600));
        $jobsPendientes = $this->contarJobsResultadosMpPendientes($corrida->id);
        $jobsReservados = $this->contarJobsResultadosMpReservados($corrida->id);
        $procesadas = (int) $corrida->notas_procesadas;
        $total = max(0, (int) $corrida->total_notas);
        $sinJobActivo = $jobsPendientes === 0 && $jobsReservados === 0;

        $umbralPorNotas = max($umbralBase, $total * 60);
        $umbral = $sinJobActivo ? $umbralBase : $umbralPorNotas;
        $umbralJobColgado = max($umbralPorNotas, 3600);

        if ($sinJobActivo && $segundos >= $umbral) {
            // sin job activo y pasó el umbral → liberar
        } elseif ($segundos >= $umbralJobColgado) {
            // job aún reservado pero sin avance hace >15 min → worker probablemente murió
        } else {
            return false;
        }

        if ($procesadas >= $total && $total > 0) {
            return false;
        }

        $minutos = (int) floor($segundos / 60);
        $codigo = trim((string) ($corrida->codigo_actual ?? ''));

        $this->eliminarJobsResultadosMpPendientes();

        if ($procesadas === 0) {
            $mensaje = 'Consulta colgada liberada automáticamente tras '.$minutos.' min sin worker activo.';
            if ($codigo !== '') {
                $mensaje .= ' Se detuvo en '.$codigo.'.';
            }
            $mensaje .= ' Reintente con «Consultar ahora».';

            $this->finalizarCorrida($corrida, 'error', $mensaje);

            return true;
        }

        $this->finalizarCorrida(
            $corrida,
            'error',
            'Consulta interrumpida tras '.$minutos.' min ('.$procesadas.'/'.$total.' procesadas). Reintente.',
        );

        return true;
    }

    public function apiConfigurada(): bool
    {
        return $this->api->isConfigured();
    }

    public function esCodigoCompraAgil(string $codigo): bool
    {
        $codigo = strtoupper(trim($codigo));

        return $codigo !== '' && (bool) preg_match('/^\d+-\d+-COT\d+$/', $codigo);
    }

    public function limiteCorridaMax(): int
    {
        return self::LIMITE_CORRIDA_MAX;
    }

    public function normalizarLimiteConsulta(int $limite): int
    {
        return max(1, min(self::LIMITE_CORRIDA_MAX, $limite));
    }

    public function notaMaxSegundos(): int
    {
        return max(60, (int) config('cotiz.mercadopublico.resultados_nota_max_segundos', 180));
    }

    public function notaAlertaSegundos(): int
    {
        return max(60, (int) config('cotiz.mercadopublico.resultados_nota_alerta_segundos', 180));
    }

    public static function mensajeTiempoMaximoNota(): string
    {
        return 'Tiempo máximo por nota excedido. Se reintentará en la próxima consulta.';
    }

    public static function formatearDuracionSegundos(int $segundos): string
    {
        $segundos = max(0, $segundos);
        $h = intdiv($segundos, 3600);
        $m = intdiv($segundos % 3600, 60);
        $s = $segundos % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    public function marcarNotaEnConsulta(NotaMpCorrida $corrida, int $nronota, string $codigo): void
    {
        $codigo = trim($codigo);

        $corrida->update([
            'nronota_actual' => $nronota,
            'codigo_actual' => $codigo !== '' ? $codigo : null,
            'nota_inicio_at' => now(),
            'en_curso_json' => $codigo !== ''
                ? [['nronota' => $nronota, 'codigo' => $codigo]]
                : [],
        ]);
    }

    /**
     * @param  list<array{nronota: int, codigo: string, empresa?: string|null}>  $lote
     */
    public function marcarLoteEnConsulta(NotaMpCorrida $corrida, array $lote): void
    {
        $enCurso = [];
        foreach ($lote as $item) {
            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($nronota <= 0 || $codigo === '') {
                continue;
            }
            $enCurso[] = ['nronota' => $nronota, 'codigo' => $codigo];
        }

        $primero = $enCurso[0] ?? null;
        $corrida->update([
            'nronota_actual' => $primero['nronota'] ?? null,
            'codigo_actual' => $primero['codigo'] ?? null,
            'nota_inicio_at' => now(),
            'en_curso_json' => $enCurso !== [] ? $enCurso : null,
        ]);
    }

    public function concurrenciaResultados(): int
    {
        return max(1, min(10, (int) config('cotiz.mercadopublico.resultados_concurrencia', 5)));
    }

    /**
     * @return list<array{nronota: int, codigo: string, empresa?: string|null}>
     */
    public function lotePendienteActual(NotaMpCorrida $corrida, ?int $tamano = null): array
    {
        $tamano ??= $this->concurrenciaResultados();
        $pendientes = is_array($corrida->pendientes_json) ? $corrida->pendientes_json : [];
        $indice = (int) $corrida->notas_procesadas;
        $slice = array_slice($pendientes, $indice, max(1, $tamano));
        $lote = [];
        foreach ($slice as $item) {
            if (! is_array($item)) {
                continue;
            }
            $nronota = (int) ($item['nronota'] ?? 0);
            if ($nronota <= 0) {
                continue;
            }
            $lote[] = [
                'nronota' => $nronota,
                'codigo' => strtoupper(trim((string) ($item['codigo'] ?? ''))),
                'empresa' => trim((string) ($item['empresa'] ?? '')),
            ];
        }

        return $lote;
    }

    public function marcarSiguienteNotaPendiente(NotaMpCorrida $corrida): void
    {
        $lote = $this->lotePendienteActual($corrida);
        if ($lote === []) {
            $corrida->update([
                'nronota_actual' => null,
                'codigo_actual' => null,
                'nota_inicio_at' => null,
                'en_curso_json' => null,
            ]);

            return;
        }

        $this->marcarLoteEnConsulta($corrida, $lote);
    }

    private function segundosEnNotaActual(NotaMpCorrida $corrida): ?int
    {
        if (! filled($corrida->codigo_actual)) {
            return null;
        }

        if ($corrida->nota_inicio_at !== null) {
            return (int) $corrida->nota_inicio_at->diffInSeconds(now());
        }

        if ($corrida->updated_at !== null) {
            return (int) $corrida->updated_at->diffInSeconds(now());
        }

        return null;
    }

    public function contarNotasPendientesConsulta(): int
    {
        return Nota::query()
            ->select(['notas.nronota', 'notas.encargado'])
            ->leftJoin('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota')
            ->whereRaw("trim(coalesce(notas.encargado, '')) <> ''")
            ->where(function ($q) {
                $q->whereNull('seg.nronota')
                    ->orWhereRaw('seg.finalizado IS FALSE');
            })
            ->get()
            ->filter(fn (Nota $nota) => $this->esCodigoCompraAgil((string) $nota->encargado))
            ->count();
    }

    /**
     * @return Collection<int, array{nronota: int, codigo: string, fecha: ?string, empresa: string}>
     */
    public function notasPendientesConsulta(?int $limite = null): Collection
    {
        $query = Nota::query()
            ->select(['notas.nronota', 'notas.encargado', 'notas.fecha', 'notas.empresa'])
            ->leftJoin('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota')
            ->whereRaw("trim(coalesce(notas.encargado, '')) <> ''")
            ->where(function ($q) {
                $q->whereNull('seg.nronota')
                    ->orWhereRaw('seg.finalizado IS FALSE');
            })
            ->orderBy('notas.fecha')
            ->orderBy('notas.nronota');

        $filtered = $query->get()
            ->filter(fn (Nota $nota) => $this->esCodigoCompraAgil((string) $nota->encargado))
            ->values();

        if ($limite !== null && $limite > 0) {
            $filtered = $filtered->take($limite);
        }

        return $filtered->map(fn (Nota $nota) => [
            'nronota' => (int) $nota->nronota,
            'codigo' => strtoupper(trim((string) $nota->encargado)),
            'fecha' => $nota->fecha?->format('Y-m-d'),
            'empresa' => trim((string) ($nota->empresa ?? '')),
        ]);
    }

    public function encolarCorrida(string $usuario): NotaMpCorrida
    {
        if ($this->corridaEnCurso() !== null) {
            throw new RuntimeException('Ya hay una consulta en curso.');
        }

        $pendientes = $this->notasPendientesConsulta();
        if ($pendientes->isEmpty()) {
            throw new RuntimeException('No hay cotizaciones pendientes de consultar (sin código CA o ya finalizadas).');
        }

        $lista = $pendientes->values()->all();

        $this->assertColaBackgroundDisponible();

        $corrida = NotaMpCorrida::query()->create([
            'usuario' => trim($usuario),
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => count($lista),
            'pendientes_json' => $lista,
            'notas_procesadas' => 0,
            'notas_con_cambio' => 0,
        ]);

        $this->marcarSiguienteNotaPendiente($corrida);
        $corrida->refresh();

        try {
            $this->eliminarJobsResultadosMpPendientes();
            ProcessNotaMpCorridaJob::dispatch($corrida->id);

            if (config('queue.default') !== 'sync' && ! $this->jobResultadosMpEncolado($corrida->id)) {
                $corrida->refresh();
                if ($corrida->estado === 'running' && (int) $corrida->notas_procesadas === 0) {
                    throw new RuntimeException(
                        'El job no quedó en la tabla jobs. Verifique migraciones (tabla jobs) y QUEUE_CONNECTION=database.',
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->finalizarCorrida(
                $corrida,
                'error',
                'No se pudo encolar la consulta: '.$e->getMessage(),
            );

            throw new RuntimeException(
                'No se pudo encolar la consulta: '.$e->getMessage(),
                0,
                $e,
            );
        }

        return $corrida->fresh() ?? $corrida;
    }

    public function assertColaBackgroundDisponible(): void
    {
        if (config('queue.default') === 'sync' && app()->isProduction()) {
            throw new RuntimeException(
                'La consulta en segundo plano requiere QUEUE_CONNECTION=database y RUN_QUEUE_WORKER=true en Render.',
            );
        }
    }

    public function eliminarJobsResultadosMpPendientes(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')->where('payload', 'like', '%ProcessNotaMpCorridaJob%');

        if ($corridaId !== null) {
            $query->where('payload', 'like', '%i:'.$corridaId.';%');
        }

        return $query->delete();
    }

    private function filtrarJobsResultadosMpPorCorrida(\Illuminate\Database\Query\Builder $query, ?int $corridaId): \Illuminate\Database\Query\Builder
    {
        if ($corridaId === null) {
            return $query;
        }

        // Payload Laravel: PHP serialize dentro de JSON → corridaId";i:8; o corridaId\";i:8;
        return $query->where('payload', 'like', '%i:'.$corridaId.';%');
    }

    public function jobResultadosMpEncolado(int $corridaId): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return $this->contarJobsResultadosMpPendientes($corridaId) > 0
            || $this->contarJobsResultadosMpReservados($corridaId) > 0;
    }

    public function contarJobsResultadosMpPendientes(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessNotaMpCorridaJob%')
            ->whereNull('reserved_at');

        $query = $this->filtrarJobsResultadosMpPorCorrida($query, $corridaId);

        return (int) $query->count();
    }

    public function contarJobsResultadosMpReservados(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessNotaMpCorridaJob%')
            ->whereNotNull('reserved_at');

        $query = $this->filtrarJobsResultadosMpPorCorrida($query, $corridaId);

        return (int) $query->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function estadoCorrida(?NotaMpCorrida $corrida = null): array
    {
        $corrida = $this->corridaEnCurso();
        if ($corrida !== null) {
            $corrida = $corrida->fresh() ?? $corrida;
        }
        if ($corrida === null) {
            return ['en_curso' => false];
        }

        $total = max(1, (int) $corrida->total_notas);
        $procesadas = min((int) $corrida->notas_procesadas, $total);
        $segundosEnCurso = (int) $corrida->inicio->diffInSeconds(now());
        $jobsEnCola = $this->contarJobsResultadosMpPendientes($corrida->id);
        $jobsReservados = $this->contarJobsResultadosMpReservados($corrida->id);
        $colaDriver = (string) config('queue.default');
        $alerta = null;

        $tieneCodigoActual = filled($corrida->codigo_actual);

        $segundosEnNotaActual = $this->segundosEnNotaActual($corrida);

        if ($corrida->estado === 'running' && $tieneCodigoActual && $segundosEnNotaActual !== null) {
            $umbralAlertaNota = $this->notaAlertaSegundos();
            $umbralMaxNota = $this->notaMaxSegundos();
            if ($segundosEnNotaActual >= $umbralAlertaNota) {
                $alerta = 'Consultando '.$corrida->codigo_actual.' lleva '
                    .self::formatearDuracionSegundos($segundosEnNotaActual).'. '
                    .'Al superar '.$umbralMaxNota.' s se registrará como fallo y continuará con la siguiente '
                    .'(recuperación automática a los '.($umbralMaxNota + 30).' s).';
            }
        }

        if ($corrida->estado === 'running' && $procesadas === 0 && $alerta === null) {
            if ($colaDriver === 'sync' && app()->isProduction() && $segundosEnCurso >= 30) {
                $alerta = 'QUEUE_CONNECTION=sync en producción: el worker no procesará la cola. Use database y RUN_QUEUE_WORKER=true.';
            } elseif ($jobsEnCola > 0 && ! $tieneCodigoActual && $segundosEnCurso >= 90) {
                $alerta = 'Hay '.$jobsEnCola.' job(s) en cola esperando worker. Confirme RUN_QUEUE_WORKER=true y redeploy en Render.';
            } elseif ($jobsReservados > 0 && ! $tieneCodigoActual && $segundosEnCurso >= 150) {
                $alerta = 'Hay un job reservado sin avance. Espere o use «Cancelar consulta» y reintente.';
            } elseif ($tieneCodigoActual && $segundosEnCurso >= 180) {
                $alerta = 'Sin avance consultando '.$corrida->codigo_actual.' tras '
                    .(int) floor($segundosEnCurso / 60).' min. Use «Cancelar consulta» y reintente.';
            } elseif (! $tieneCodigoActual && $segundosEnCurso >= 150) {
                $alerta = 'Sin avance tras '.(int) floor($segundosEnCurso / 60).' min. Use «Cancelar consulta» y reintente.';
            }
        }

        $detalleStats = NotaMpCorridaDetalle::query()
            ->where('corrida_id', $corrida->id)
            ->selectRaw("count(*) as total, count(*) filter (where exito IS TRUE) as ok, count(*) filter (where exito IS FALSE) as fallos")
            ->first();

        $ultimoDetalle = NotaMpCorridaDetalle::query()
            ->where('corrida_id', $corrida->id)
            ->orderByDesc('id')
            ->first();

        $ultimoDetalleInfo = null;
        if ($ultimoDetalle) {
            $ultimoDetalleInfo = [
                'nronota' => $ultimoDetalle->nronota,
                'codigo' => $ultimoDetalle->codigo_proceso,
                'exito' => (bool) $ultimoDetalle->exito,
                'mensaje' => $ultimoDetalle->mensaje,
                'estado_mp' => $ultimoDetalle->estado_mp_glosa,
                'resultado' => $ultimoDetalle->resultado_propio,
            ];
        }

        return [
            'en_curso' => $corrida->estado === 'running',
            'corrida_id' => $corrida->id,
            'usuario' => $corrida->usuario,
            'inicio' => $corrida->inicio->format('d/m/Y H:i:s'),
            'procesadas' => $procesadas,
            'total' => (int) $corrida->total_notas,
            'porcentaje' => (int) round(($procesadas / $total) * 100),
            'nronota_actual' => $corrida->nronota_actual,
            'codigo_actual' => $corrida->codigo_actual,
            'notas_con_cambio' => (int) $corrida->notas_con_cambio,
            'estado' => $corrida->estado,
            'segundos_en_curso' => $segundosEnCurso,
            'segundos_en_nota_actual' => $segundosEnNotaActual,
            'jobs_en_cola' => $jobsEnCola,
            'jobs_reservados' => $jobsReservados,
            'cola_driver' => $colaDriver,
            'alerta' => $alerta,
            'detalle_ok' => (int) ($detalleStats->ok ?? 0),
            'detalle_fallos' => (int) ($detalleStats->fallos ?? 0),
            'ultimo_detalle' => $ultimoDetalleInfo,
            'notas_en_curso' => $this->enCursoActual($corrida),
            'recientes' => $this->recientesActual($corrida),
            'concurrencia' => $this->concurrenciaResultados(),
            'config_mp' => self::configMpEfectiva(),
        ];
    }

    /**
     * @return list<array{nronota: int, codigo: string, started_at?: string|null, segundos?: int}>
     */
    public function enCursoActual(NotaMpCorrida $corrida): array
    {
        $lista = is_array($corrida->en_curso_json) ? $corrida->en_curso_json : [];
        $out = [];
        $now = now();
        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }
            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($nronota <= 0 || $codigo === '') {
                continue;
            }
            $startedAt = isset($item['started_at']) ? (string) $item['started_at'] : null;
            $segundos = null;
            if ($startedAt) {
                try {
                    $segundos = (int) \Carbon\Carbon::parse($startedAt)->diffInSeconds($now);
                } catch (\Throwable) {
                    $segundos = null;
                }
            } elseif ($corrida->nota_inicio_at !== null && count($lista) === 1) {
                $segundos = (int) $corrida->nota_inicio_at->diffInSeconds($now);
            }
            $out[] = [
                'nronota' => $nronota,
                'codigo' => $codigo,
                'started_at' => $startedAt,
                'segundos' => $segundos,
            ];
        }

        if ($out === [] && filled($corrida->codigo_actual)) {
            $segundos = $this->segundosEnNotaActual($corrida);
            $out[] = [
                'nronota' => (int) ($corrida->nronota_actual ?? 0),
                'codigo' => (string) $corrida->codigo_actual,
                'started_at' => $corrida->nota_inicio_at?->toIso8601String(),
                'segundos' => $segundos,
            ];
        }

        return $out;
    }

    /**
     * Últimas notas terminadas (máx. 5) con duración.
     *
     * @return list<array{nronota: int, codigo: string, exito: bool, ms: int, mensaje?: string|null}>
     */
    public function recientesActual(NotaMpCorrida $corrida): array
    {
        $lista = is_array($corrida->recientes_json) ? $corrida->recientes_json : [];
        $out = [];
        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }
            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($nronota <= 0 || $codigo === '') {
                continue;
            }
            $out[] = [
                'nronota' => $nronota,
                'codigo' => $codigo,
                'exito' => (bool) ($item['exito'] ?? false),
                'ms' => (int) ($item['ms'] ?? 0),
                'mensaje' => isset($item['mensaje']) ? (string) $item['mensaje'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array{nronota: int, codigo: string, exito: bool, ms: int, mensaje?: string|null}  $entry
     */
    public function pushReciente(NotaMpCorrida $corrida, array $entry): void
    {
        $lista = is_array($corrida->recientes_json) ? $corrida->recientes_json : [];
        array_unshift($lista, $entry);
        $lista = array_slice($lista, 0, 5);
        $corrida->update(['recientes_json' => $lista]);
    }

    /**
     * @return array{timeout_seg: int, connect_timeout_seg: int, low_speed_seg: int, reintentos: int, delay_ms: int, concurrencia: int, stagger_ms: int}
     */
    public static function configMpEfectiva(): array
    {
        return [
            'timeout_seg' => (int) config('cotiz.mercadopublico.api_timeout_segundos', 45),
            'connect_timeout_seg' => (int) config('cotiz.mercadopublico.api_connect_timeout_segundos', 15),
            'low_speed_seg' => (int) config('cotiz.mercadopublico.api_low_speed_time_segundos', 20),
            'reintentos' => (int) config('cotiz.mercadopublico.api_reintentos_http', 3),
            'delay_ms' => (int) config('cotiz.mercadopublico.resultados_delay_ms', 500),
            'concurrencia' => max(1, min(10, (int) config('cotiz.mercadopublico.resultados_concurrencia', 5))),
            'stagger_ms' => max(0, (int) config('cotiz.mercadopublico.resultados_stagger_ms', 2000)),
        ];
    }

    /** @deprecated Use encolarCorrida() */
    public function iniciarCorrida(string $usuario, int $limite = 5): NotaMpCorrida
    {
        return $this->encolarCorrida($usuario, $limite);
    }

    public function finalizarCorrida(NotaMpCorrida $corrida, string $estado = 'ok', ?string $mensaje = null): NotaMpCorrida
    {
        $corrida->update([
            'fin' => now(),
            'estado' => $estado,
            'mensaje' => $mensaje,
            'nronota_actual' => null,
            'codigo_actual' => null,
            'nota_inicio_at' => null,
            'en_curso_json' => null,
            'recientes_json' => null,
        ]);

        return $corrida->fresh() ?? $corrida;
    }

    public function finalizarCorridaDesdeJob(
        NotaMpCorrida $corrida,
        int $fallidas,
        ?string $ultimoError = null,
    ): NotaMpCorrida {
        $guardadas = (int) NotaMpSeguimiento::query()
            ->where('ultima_corrida_id', $corrida->id)
            ->count();
        $procesadas = (int) $corrida->notas_procesadas;

        if ($guardadas === 0 && $procesadas > 0) {
            $mensaje = 'Ninguna de las '.$procesadas.' consultas guardó seguimiento.';
            if ($ultimoError !== null && trim($ultimoError) !== '') {
                $mensaje .= ' Último error: '.trim($ultimoError);
            } else {
                $mensaje .= ' Revise MERCADOPUBLICO_TICKET, cuota diaria de MP y logs del servidor.';
            }

            return $this->finalizarCorrida($corrida, 'error', $mensaje);
        }

        if ($fallidas > 0) {
            $mensaje = $guardadas.' consultadas ok, '.$fallidas.' con error.';
            if ($ultimoError !== null && trim($ultimoError) !== '') {
                $mensaje .= ' Último error: '.trim($ultimoError);
            }

            return $this->finalizarCorrida($corrida, 'ok', $mensaje);
        }

        return $this->finalizarCorrida($corrida, 'ok');
    }

    public function cancelarCorridaEnCurso(string $usuario): NotaMpCorrida
    {
        $corrida = $this->corridaEnCurso();
        if ($corrida === null) {
            throw new RuntimeException('No hay una consulta en curso para cancelar.');
        }

        $this->eliminarJobsResultadosMpPendientes($corrida->id);

        return $this->finalizarCorrida(
            $corrida,
            'cancelled',
            'Cancelada por '.trim($usuario).'.',
        );
    }

    /**
     * Procesa un lote con disparo escalonado: lanza la siguiente ~2 s después
     * sin esperar respuesta; máximo N en vuelo. Persiste cada resultado al llegar.
     *
     * @param  list<array{nronota: int, codigo: string, empresa?: string|null}>  $lote
     * @return array{ultimo_error: ?string, fallidas: int, ok: int}
     */
    public function consultarLoteMasivo(NotaMpCorrida $corrida, array $lote, string $usuario): array
    {
        if ($lote === []) {
            return ['ultimo_error' => null, 'fallidas' => 0, 'ok' => 0];
        }

        // UI empieza vacía; se llena con onLaunch (máx. N en vuelo).
        $corrida->update([
            'en_curso_json' => [],
            'nronota_actual' => null,
            'codigo_actual' => null,
            'nota_inicio_at' => now(),
            'recientes_json' => is_array($corrida->recientes_json) ? $corrida->recientes_json : [],
        ]);

        $porCodigo = [];
        $codigos = [];
        $startedAtByCodigo = [];
        foreach ($lote as $item) {
            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($nronota <= 0 || $codigo === '') {
                continue;
            }
            $codigos[] = $codigo;
            $porCodigo[$codigo] = $item;
        }

        $fallidas = 0;
        $ok = 0;
        $ultimoError = null;
        $yaContadas = [];

        $maxInFlight = $this->concurrenciaResultados();
        $staggerMs = max(0, (int) config('cotiz.mercadopublico.resultados_stagger_ms', 2000));

        $onLaunch = function (string $codigo) use (&$corrida, $porCodigo, &$startedAtByCodigo): void {
            $item = $porCodigo[$codigo] ?? null;
            if ($item === null) {
                return;
            }
            $startedAt = now()->toIso8601String();
            $startedAtByCodigo[$codigo] = microtime(true);
            $corrida->refresh();
            $enCurso = is_array($corrida->en_curso_json) ? $corrida->en_curso_json : [];
            $enCurso[] = [
                'nronota' => (int) $item['nronota'],
                'codigo' => $codigo,
                'started_at' => $startedAt,
            ];
            $primero = $enCurso[0] ?? null;
            $corrida->update([
                'en_curso_json' => $enCurso,
                'nronota_actual' => $primero['nronota'] ?? null,
                'codigo_actual' => $primero['codigo'] ?? null,
                'nota_inicio_at' => $corrida->nota_inicio_at ?? now(),
            ]);
        };

        $onDone = function (string $codigo, array|RuntimeException $apiResult) use (
            &$corrida,
            $usuario,
            $porCodigo,
            &$fallidas,
            &$ok,
            &$ultimoError,
            &$yaContadas,
            &$startedAtByCodigo,
        ): void {
            if (isset($yaContadas[$codigo])) {
                return;
            }
            $yaContadas[$codigo] = true;

            $corrida->refresh();
            if ($corrida->estado !== 'running') {
                return;
            }

            $item = $porCodigo[$codigo] ?? null;
            if ($item === null) {
                return;
            }

            $nronota = (int) ($item['nronota'] ?? 0);
            $empresa = trim((string) ($item['empresa'] ?? ''));
            $procesadasAntes = (int) $corrida->notas_procesadas;
            $ms = isset($startedAtByCodigo[$codigo])
                ? (int) round((microtime(true) - $startedAtByCodigo[$codigo]) * 1000)
                : 0;

            $enCurso = array_values(array_filter(
                is_array($corrida->en_curso_json) ? $corrida->en_curso_json : [],
                static fn ($row) => ! (is_array($row) && strtoupper((string) ($row['codigo'] ?? '')) === $codigo),
            ));
            $corrida->update([
                'en_curso_json' => $enCurso !== [] ? $enCurso : null,
                'nronota_actual' => $enCurso[0]['nronota'] ?? null,
                'codigo_actual' => $enCurso[0]['codigo'] ?? null,
            ]);

            $exito = false;
            $mensajeReciente = null;
            try {
                if ($apiResult instanceof RuntimeException) {
                    throw $apiResult;
                }
                $this->consultarNotaConPayload($nronota, $corrida, $usuario, $apiResult, null);
                $ok++;
                $exito = true;
            } catch (\Throwable $e) {
                $fallidas++;
                $ultimoError = $e->getMessage();
                $mensajeReciente = mb_substr((string) $ultimoError, 0, 120);
                $sufijo = CompraAgilApiService::esErrorDefinitivoMp((string) $ultimoError)
                    ? 'sin reintento'
                    : (max(1, (int) config('cotiz.mercadopublico.api_reintentos_http', 3)).' intentos HTTP');
                $this->registrarDetalleFallo(
                    $corrida,
                    $nronota,
                    $codigo,
                    mb_substr(($ultimoError ?: 'Error desconocido').' ('.$sufijo.')', 0, 500),
                    $empresa !== '' ? $empresa : null,
                );
                Log::warning('NotaMpResultados: nota fallida en pipeline', [
                    'corrida_id' => $corrida->id,
                    'nronota' => $nronota,
                    'codigo' => $codigo,
                    'message' => $ultimoError,
                ]);
            }

            $corrida->refresh();
            $this->pushReciente($corrida, [
                'nronota' => $nronota,
                'codigo' => $codigo,
                'exito' => $exito,
                'ms' => $ms,
                'mensaje' => $mensajeReciente,
            ]);

            if ((int) $corrida->notas_procesadas <= $procesadasAntes) {
                $corrida->increment('notas_procesadas');
            }
        };

        if ($codigos !== []) {
            $this->api->detalleVariosEscalonado(
                $codigos,
                $maxInFlight,
                $staggerMs,
                $onDone,
                function () use ($corrida): bool {
                    $corrida->refresh();

                    return $corrida->estado === 'running';
                },
                $onLaunch,
            );
        }

        // Notas del lote sin código válido: marcar fallidas.
        foreach ($lote as $item) {
            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($nronota <= 0) {
                continue;
            }
            if ($codigo !== '' && isset($yaContadas[$codigo])) {
                continue;
            }
            if ($codigo !== '') {
                continue;
            }

            $corrida->refresh();
            $procesadasAntes = (int) $corrida->notas_procesadas;
            $ultimoError = 'La nota no tiene un código Compra Ágil válido.';
            $this->registrarDetalleFallo(
                $corrida,
                $nronota,
                '',
                $ultimoError.' (sin reintento)',
                trim((string) ($item['empresa'] ?? '')) ?: null,
            );
            $fallidas++;
            if ((int) $corrida->notas_procesadas <= $procesadasAntes) {
                $corrida->increment('notas_procesadas');
            }
        }

        return [
            'ultimo_error' => $ultimoError,
            'fallidas' => $fallidas,
            'ok' => $ok,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarNota(int $nronota, NotaMpCorrida $corrida, string $usuario, ?float $deadline = null): array
    {
        return $this->consultarNotaConPayload($nronota, $corrida, $usuario, null, $deadline);
    }

    /**
     * @param  array<string, mixed>|null  $payloadPrecargado
     * @return array<string, mixed>
     */
    public function consultarNotaConPayload(
        int $nronota,
        NotaMpCorrida $corrida,
        string $usuario,
        ?array $payloadPrecargado,
        ?float $deadline = null,
    ): array {
        $inicio = microtime(true);

        if (! $this->api->isConfigured()) {
            throw new RuntimeException('MERCADOPUBLICO_TICKET no configurado.');
        }

        $nota = Nota::query()->find($nronota);
        if ($nota === null) {
            throw new RuntimeException('Cotización no encontrada.');
        }

        $codigo = strtoupper(trim((string) $nota->encargado));
        if (! $this->esCodigoCompraAgil($codigo)) {
            throw new RuntimeException('La nota no tiene un código Compra Ágil válido en encargado.');
        }

        $anterior = NotaMpSeguimiento::query()->find($nronota);
        $estadoAnterior = $anterior?->estado_mp_codigo;
        $procesadasAlInicio = (int) $corrida->notas_procesadas;

        try {
            $payload = $payloadPrecargado ?? $this->api->detalle($codigo, false, $deadline);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'No existe Compra Ágil')) {
                $resultado = $this->marcarNoExisteEnMp($nronota, $codigo, $corrida, $usuario, $nota, $anterior);
                $msTotal = (int) round((microtime(true) - $inicio) * 1000);

                return array_merge($resultado, [
                    'ms_total' => $msTotal,
                    'ms_api' => $msTotal,
                    'ms_guardado' => 0,
                ]);
            }
            throw $e;
        }

        $msApi = (int) round((microtime(true) - $inicio) * 1000);
        $this->assertAntesDeDeadline($deadline, $nronota, $codigo, 'tras API');

        $ganadorProv = $this->ganador->ganadorPrincipal($payload);
        $institucion = is_array($payload['institucion'] ?? null) ? $payload['institucion'] : [];
        $estadoCodigo = $this->ganador->codigoEstadoMp($payload);
        $estadoGlosa = $this->ganador->glosaEstadoMp($payload);
        $resultadoPropio = $this->ganador->resultadoPropio($payload);
        $finalizado = in_array($resultadoPropio, ['cerrada', 'desierta', 'cancelada'], true);

        $rutGanador = $this->ganador->rutGanador($payload);
        $montoGanador = $ganadorProv !== null ? (int) round((float) ($ganadorProv['monto_total'] ?? 0)) : null;

        DB::transaction(function () use (
            $nronota,
            $codigo,
            $estadoCodigo,
            $estadoGlosa,
            $institucion,
            $rutGanador,
            $ganadorProv,
            $payload,
            $montoGanador,
            $resultadoPropio,
            $finalizado,
            $usuario,
            $corrida,
            $estadoAnterior,
            $anterior,
            $nota,
            $deadline,
            $procesadasAlInicio,
        ) {
            $datosSeguimiento = array_merge(
                [
                    'codigo_proceso' => $codigo,
                    'estado_mp_codigo' => $estadoCodigo,
                    'estado_mp_glosa' => $estadoGlosa,
                    'organismo' => mb_substr(trim((string) ($institucion['organismo_comprador'] ?? '')), 0, 200),
                    'rut_ganador' => $rutGanador,
                    'razon_social_ganador' => $ganadorProv !== null
                        ? mb_substr(trim((string) ($ganadorProv['razon_social'] ?? '')), 0, 200)
                        : null,
                    'id_orden_compra' => isset($payload['id_orden_compra']) ? (int) $payload['id_orden_compra'] : null,
                    'monto_total_ganador' => $montoGanador,
                    'resultado_propio' => $resultadoPropio,
                    'finalizado' => $finalizado,
                    'ultimo_usuario' => trim($usuario),
                    'ultimo_consultado_en' => now(),
                    'ultima_corrida_id' => $corrida->id,
                ],
                $this->fechasSeguimientoParaGuardar($payload, $finalizado, $anterior),
                $this->convocatoriaSeguimientoParaGuardar($payload),
            );

            NotaMpSeguimiento::query()->updateOrCreate(
                ['nronota' => $nronota],
                $datosSeguimiento,
            );

            $this->persistirOfertas($nronota, $payload, $deadline);

            $cambio = $estadoAnterior !== $estadoCodigo
                || ($anterior?->resultado_propio !== $resultadoPropio)
                || ($anterior?->rut_ganador !== $rutGanador);

            if ($cambio) {
                NotaMpCorridaCambio::query()->create([
                    'corrida_id' => $corrida->id,
                    'nronota' => $nronota,
                    'codigo_proceso' => $codigo,
                    'estado_anterior' => $estadoAnterior,
                    'estado_nuevo' => $estadoCodigo,
                    'resultado_propio' => $resultadoPropio,
                    'rut_ganador' => $rutGanador,
                    'razon_social_ganador' => $ganadorProv !== null
                        ? mb_substr(trim((string) ($ganadorProv['razon_social'] ?? '')), 0, 200)
                        : null,
                ]);

                $corrida->increment('notas_con_cambio');
            }

            $corrida->refresh();
            if ((int) $corrida->notas_procesadas === $procesadasAlInicio) {
                $corrida->increment('notas_procesadas');
            }

            NotaMpCorridaDetalle::query()->updateOrCreate(
                ['corrida_id' => $corrida->id, 'nronota' => $nronota],
                [
                    'codigo_proceso' => $codigo,
                    'empresa' => mb_substr(trim((string) ($nota->empresa ?? '')), 0, 200) ?: null,
                    'exito' => true,
                    'mensaje' => null,
                    'estado_mp_glosa' => $estadoGlosa,
                    'resultado_propio' => $resultadoPropio,
                    'rut_ganador' => $rutGanador,
                    'razon_social_ganador' => $ganadorProv !== null
                        ? mb_substr(trim((string) ($ganadorProv['razon_social'] ?? '')), 0, 200)
                        : null,
                    'cambio' => $cambio,
                ],
            );
        });

        $corrida->refresh();

        $msTotal = (int) round((microtime(true) - $inicio) * 1000);
        if ($msTotal >= 60000) {
            Log::info('NotaMpResultados: consulta lenta', [
                'nronota' => $nronota,
                'codigo' => $codigo,
                'ms_total' => $msTotal,
                'ms_api' => $msApi,
                'ms_guardado' => max(0, $msTotal - $msApi),
            ]);
        }

        return [
            'nronota' => $nronota,
            'codigo' => $codigo,
            'empresa' => trim((string) ($nota->empresa ?? '')),
            'estado_anterior' => $estadoAnterior,
            'estado_anterior_glosa' => $anterior?->estado_mp_glosa ?: $anterior?->estado_mp_codigo,
            'estado_nuevo' => $estadoCodigo,
            'estado_glosa' => $estadoGlosa,
            'resultado_anterior' => $anterior?->resultado_propio,
            'cambio' => $estadoAnterior !== $estadoCodigo
                || ($anterior?->resultado_propio !== $resultadoPropio)
                || ($anterior?->rut_ganador !== $rutGanador),
            'finalizado' => $finalizado,
            'grupo' => $finalizado ? 'cerradas' : 'pendientes',
            'resultado_propio' => $resultadoPropio,
            'rut_ganador' => $rutGanador,
            'razon_social_ganador' => $ganadorProv !== null ? trim((string) ($ganadorProv['razon_social'] ?? '')) : null,
            'monto_total_ganador' => $montoGanador,
            'id_orden_compra' => isset($payload['id_orden_compra']) ? (int) $payload['id_orden_compra'] : null,
            'organismo' => trim((string) ($institucion['organismo_comprador'] ?? '')),
            'ms_total' => $msTotal,
            'ms_api' => $msApi,
            'ms_guardado' => max(0, $msTotal - $msApi),
        ];
    }

    private function marcarNoExisteEnMp(
        int $nronota,
        string $codigo,
        NotaMpCorrida $corrida,
        string $usuario,
        Nota $nota,
        ?NotaMpSeguimiento $anterior,
    ): array {
        $estadoAnterior = $anterior?->estado_mp_codigo;

        DB::transaction(function () use ($nronota, $codigo, $corrida, $usuario, $nota, $estadoAnterior) {
            NotaMpSeguimiento::query()->updateOrCreate(
                ['nronota' => $nronota],
                [
                    'codigo_proceso' => $codigo,
                    'estado_mp_glosa' => 'No existe en MP',
                    'resultado_propio' => 'no_encontrada',
                    'finalizado' => true,
                    'ultimo_usuario' => trim($usuario),
                    'ultimo_consultado_en' => now(),
                    'ultima_corrida_id' => $corrida->id,
                ],
            );

            $corrida->increment('notas_procesadas');

            NotaMpCorridaDetalle::query()->updateOrCreate(
                ['corrida_id' => $corrida->id, 'nronota' => $nronota],
                [
                    'codigo_proceso' => $codigo,
                    'empresa' => mb_substr(trim((string) ($nota->empresa ?? '')), 0, 200) ?: null,
                    'exito' => true,
                    'mensaje' => 'No existe en Mercado Público — omitida',
                    'estado_mp_glosa' => 'No existe en MP',
                    'resultado_propio' => 'no_encontrada',
                    'cambio' => $estadoAnterior !== null,
                ],
            );
        });

        $corrida->refresh();

        return [
            'nronota' => $nronota,
            'codigo' => $codigo,
            'empresa' => trim((string) ($nota->empresa ?? '')),
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => null,
            'estado_glosa' => 'No existe en MP',
            'cambio' => false,
            'finalizado' => true,
            'grupo' => 'cerradas',
            'resultado_propio' => 'no_encontrada',
            'rut_ganador' => null,
            'razon_social_ganador' => null,
            'monto_total_ganador' => null,
            'id_orden_compra' => null,
            'organismo' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistirOfertas(int $nronota, array $payload, ?float $deadline = null): void
    {
        NotaMpOferta::query()->where('nronota', $nronota)->delete();

        $rutPropio = $this->ganador->rutEmpresaPropia();
        $idOrdenCompra = isset($payload['id_orden_compra']) ? (int) $payload['id_orden_compra'] : null;
        $proveedores = is_array($payload['proveedores_cotizando'] ?? null) ? $payload['proveedores_cotizando'] : [];
        $lineasBatch = [];
        $now = now();
        $tamanoLote = 500;

        foreach ($proveedores as $prov) {
            if (! is_array($prov)) {
                continue;
            }

            $this->assertAntesDeDeadline($deadline, $nronota, (string) ($payload['codigo'] ?? ''), 'guardando ofertas');

            $rut = trim((string) ($prov['rut_proveedor'] ?? ''));
            $esGanador = $this->ganador->esProveedorGanador($prov, $idOrdenCompra);

            $oferta = NotaMpOferta::query()->create([
                'nronota' => $nronota,
                'id_cotizacion_mp' => isset($prov['id_cotizacion']) ? (int) $prov['id_cotizacion'] : null,
                'rut_proveedor' => $rut !== '' ? $this->parser->normalizarRut($rut) : null,
                'razon_social' => mb_substr(trim((string) ($prov['razon_social'] ?? '')), 0, 200),
                'proveedor_seleccionado' => $esGanador,
                'monto_total' => isset($prov['monto_total']) ? (int) round((float) $prov['monto_total']) : null,
                'es_propio' => $rutPropio !== '' && $this->ganador->rutsCoinciden(
                    $rut !== '' ? $this->parser->normalizarRut($rut) : null,
                    $rutPropio,
                ),
                'inadmisible' => (int) ($prov['estado'] ?? 0) === 3,
                'id_oc' => isset($prov['id_oc']) ? (int) $prov['id_oc'] : null,
            ]);

            $productos = is_array($prov['productos_cotizados'] ?? null) ? $prov['productos_cotizados'] : [];
            foreach ($productos as $idxLinea => $linea) {
                if (! is_array($linea)) {
                    continue;
                }
                if ($idxLinea % 25 === 0) {
                    $this->assertAntesDeDeadline($deadline, $nronota, (string) ($payload['codigo'] ?? ''), 'guardando ofertas');
                }
                $lineasBatch[] = [
                    'oferta_id' => $oferta->id,
                    'codigo_producto' => trim((string) ($linea['codigo_producto'] ?? '')),
                    'nombre_producto' => mb_substr(trim((string) ($linea['nombre_producto'] ?? $linea['nombre'] ?? '')), 0, 500),
                    'descripcion' => mb_substr(trim((string) ($linea['descripcion'] ?? '')), 0, 500),
                    'cantidad' => (float) ($linea['cantidad'] ?? 0),
                    'precio_unitario' => isset($linea['precio_unitario']) ? (int) round((float) $linea['precio_unitario']) : null,
                    'monto_total' => isset($linea['monto_total_producto']) ? (int) round((float) $linea['monto_total_producto']) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($lineasBatch) >= $tamanoLote) {
                    $this->assertAntesDeDeadline($deadline, $nronota, (string) ($payload['codigo'] ?? ''), 'insertando líneas');
                    NotaMpOfertaLinea::query()->insert($lineasBatch);
                    $lineasBatch = [];
                }
            }
        }

        if ($lineasBatch !== []) {
            $this->assertAntesDeDeadline($deadline, $nronota, (string) ($payload['codigo'] ?? ''), 'insertando líneas');
            NotaMpOfertaLinea::query()->insert($lineasBatch);
        }
    }

    private function assertAntesDeDeadline(?float $deadline, int $nronota, string $codigo, string $fase): void
    {
        if ($deadline === null || microtime(true) < $deadline) {
            return;
        }

        Log::warning('NotaMpResultados: tiempo máximo por nota excedido', [
            'nronota' => $nronota,
            'codigo' => $codigo,
            'fase' => $fase,
        ]);

        throw new RuntimeException(self::mensajeTiempoMaximoNota());
    }

    /**
     * @return Collection<int, NotaMpSeguimiento>
     */
    public function listadoCerradas(int $limite = 50): Collection
    {
        $items = $this->aplicarOrdenCerradas($this->buildCerradasQuery([]))
            ->with(['nota.usuarioRel', 'ofertas' => fn ($q) => $q->whereRaw('proveedor_seleccionado IS TRUE')->with('lineas')])
            ->limit($limite)
            ->get();

        return $this->marcarCerradasConFlags($items);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<NotaMpSeguimiento>
     */
    private function buildCerradasQuery(array $filtros): \Illuminate\Database\Eloquent\Builder
    {
        return $this->aplicarFiltrosListadoSeguimiento(
            NotaMpSeguimiento::query()->whereRaw('finalizado IS TRUE'),
            $filtros,
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<NotaMpSeguimiento>  $query
     * @return \Illuminate\Database\Eloquent\Builder<NotaMpSeguimiento>
     */
    private function aplicarOrdenCerradas(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->orderByRaw('fecha_ultimo_cambio IS NULL')
            ->orderByDesc('fecha_ultimo_cambio');
    }

    /**
     * @param  Collection<int, NotaMpSeguimiento>  $items
     * @return Collection<int, NotaMpSeguimiento>
     */
    private function marcarCerradasConFlags(Collection $items): Collection
    {
        $rutPropio = $this->ganador->rutEmpresaPropia();

        return $items->each(function (NotaMpSeguimiento $seg) use ($rutPropio): void {
            $seg->es_ganador_propio = $rutPropio !== ''
                && $this->ganador->rutsCoinciden($seg->rut_ganador, $rutPropio);
            $seg->tiene_proveedor_seleccionado = $this->segTieneProveedorSeleccionado($seg);
        });
    }

    private function segTieneProveedorSeleccionado(NotaMpSeguimiento $seg): bool
    {
        if ($seg->estado_mp_codigo === 'proveedor_seleccionado') {
            return true;
        }

        if (filled($seg->razon_social_ganador) || filled($seg->rut_ganador)) {
            return true;
        }

        if ($seg->relationLoaded('ofertas') && $seg->ofertas->isNotEmpty()) {
            return true;
        }

        return false;
    }

    public function nombreEjecutivoNota(?NotaMpSeguimiento $seg): string
    {
        if ($seg === null || $seg->nota === null) {
            return '';
        }

        $nombre = trim((string) ($seg->nota->usuarioRel?->fullName() ?: $seg->nota->usuario));

        return $nombre;
    }

    private function buildAnalisisPreciosQuery(array $filtros = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = NotaMpOfertaLinea::query()
            ->join('nota_mp_ofertas as o', 'o.id', '=', 'nota_mp_oferta_lineas.oferta_id')
            ->join('nota_mp_seguimientos as s', 's.nronota', '=', 'o.nronota')
            ->leftJoin('nota_mp_ofertas as op', function ($join) {
                $join->on('op.nronota', '=', 'o.nronota')
                     ->whereRaw('op.es_propio IS TRUE');
            })
            ->leftJoin('nota_mp_oferta_lineas as lp', function ($join) {
                $join->on('lp.oferta_id', '=', 'op.id')
                     ->on('lp.codigo_producto', '=', 'nota_mp_oferta_lineas.codigo_producto');
            })
            ->select([
                'nota_mp_oferta_lineas.codigo_producto',
                'nota_mp_oferta_lineas.nombre_producto',
                'nota_mp_oferta_lineas.descripcion',
                'nota_mp_oferta_lineas.cantidad',
                'nota_mp_oferta_lineas.precio_unitario',
                'nota_mp_oferta_lineas.monto_total',
                'o.nronota',
                'o.rut_proveedor',
                'o.razon_social',
                'o.proveedor_seleccionado',
                'o.es_propio',
                's.codigo_proceso',
                's.fecha_publicacion',
                's.organismo',
                DB::raw('lp.precio_unitario as precio_propio'),
                DB::raw('lp.cantidad as cantidad_propia'),
                DB::raw('lp.monto_total as total_propio'),
            ]);

        if (! empty($filtros['producto'])) {
            $palabras = preg_split('/\s+/', trim($filtros['producto']), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($palabras as $palabra) {
                $term = '%' . $palabra . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('nota_mp_oferta_lineas.nombre_producto', 'ilike', $term)
                      ->orWhere('nota_mp_oferta_lineas.descripcion', 'ilike', $term)
                      ->orWhere('nota_mp_oferta_lineas.codigo_producto', 'ilike', $term);
                });
            }
        }

        if (! empty($filtros['nronota'])) {
            $query->where('o.nronota', (int) $filtros['nronota']);
        }

        if (! empty($filtros['codigo_proceso'])) {
            $query->where('s.codigo_proceso', 'ilike', '%' . $filtros['codigo_proceso'] . '%');
        }

        if (! empty($filtros['proveedor'])) {
            $query->where('o.razon_social', 'ilike', '%' . $filtros['proveedor'] . '%');
        }

        if (! empty($filtros['fecha_desde'])) {
            $query->where('s.fecha_publicacion', '>=', $filtros['fecha_desde'] . ' 00:00:00');
        }

        if (! empty($filtros['fecha_hasta'])) {
            $query->where('s.fecha_publicacion', '<=', $filtros['fecha_hasta'] . ' 23:59:59');
        }

        if (! empty($filtros['precio_desde'])) {
            $query->where('nota_mp_oferta_lineas.precio_unitario', '>=', (int) $filtros['precio_desde']);
        }

        if (! empty($filtros['precio_hasta'])) {
            $query->where('nota_mp_oferta_lineas.precio_unitario', '<=', (int) $filtros['precio_hasta']);
        }

        if (! empty($filtros['solo_ganador'])) {
            $query->whereRaw('o.proveedor_seleccionado IS TRUE');
        }

        return $query
            ->orderByRaw('s.fecha_publicacion DESC NULLS LAST')
            ->orderBy('nota_mp_oferta_lineas.precio_unitario');
    }

    public function analisisPrecios(array $filtros = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->buildAnalisisPreciosQuery($filtros)
            ->paginate(30)
            ->withQueryString();
    }

    public function analisisPreciosExportar(array $filtros = []): Collection
    {
        return $this->buildAnalisisPreciosQuery($filtros)
            ->limit(5000)
            ->get();
    }

    public function contarCerradas(): int
    {
        return NotaMpSeguimiento::query()->whereRaw('finalizado IS TRUE')->count();
    }

    public function contarPendientesSeguimiento(): int
    {
        return NotaMpSeguimiento::query()->where('resultado_propio', 'pendiente')->count();
    }

    public function contarTodas(): int
    {
        return $this->buildTodasNotasQuery([])->count('notas.nronota');
    }

    public function listadoTodasPaginado(int $porPagina = 20, array $filtros = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $paginator = $this->buildTodasNotasQuery($filtros)
            ->with([
                'usuarioRel',
                'mpSeguimiento.ofertas' => fn ($q) => $q->whereRaw('proveedor_seleccionado IS TRUE')->with('lineas'),
            ])
            ->paginate($porPagina)
            ->withQueryString();

        $items = $paginator->getCollection()
            ->map(fn (Nota $nota) => $this->itemListadoTodasDesdeNota($nota));

        $this->marcarCerradasConFlags($items);
        $paginator->setCollection($items);

        return $paginator;
    }

    /**
     * @return Collection<int, NotaMpSeguimiento>
     */
    public function listadoTodasExportar(array $filtros = [], int $limite = 10000): Collection
    {
        $notas = $this->buildTodasNotasQuery($filtros)
            ->with(['usuarioRel', 'mpSeguimiento'])
            ->limit($limite)
            ->get();

        $items = $notas->map(fn (Nota $nota) => $this->itemListadoTodasDesdeNota($nota));

        return $this->marcarCerradasConFlags($items);
    }

    public function listadoPendientesPaginado(int $porPagina = 20, array $filtros = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $paginator = $this->aplicarOrdenCerradas($this->buildPendientesSeguimientoQuery($filtros))
            ->with(['nota.usuarioRel', 'ofertas' => fn ($q) => $q->whereRaw('proveedor_seleccionado IS TRUE')->with('lineas')])
            ->paginate($porPagina)
            ->withQueryString();

        $this->marcarCerradasConFlags($paginator->getCollection());

        return $paginator;
    }

    /**
     * @return Collection<int, NotaMpSeguimiento>
     */
    public function listadoPendientesExportar(array $filtros = [], int $limite = 10000): Collection
    {
        $items = $this->aplicarOrdenCerradas($this->buildPendientesSeguimientoQuery($filtros))
            ->with(['nota.usuarioRel'])
            ->limit($limite)
            ->get();

        return $this->marcarCerradasConFlags($items);
    }

    /**
     * Consulta una sola cotización en Mercado Público (síncrona, independiente de la corrida masiva).
     * No usa worker ni compite con el progreso del proceso masivo en pantalla.
     *
     * @return array<string, mixed>
     */
    public function consultarNotaIndividual(int $nronota, string $usuario): array
    {
        if (! $this->api->isConfigured()) {
            throw new RuntimeException('MERCADOPUBLICO_TICKET no configurado.');
        }

        $nota = Nota::query()->find($nronota);
        if ($nota === null) {
            throw new RuntimeException('Cotización no encontrada.');
        }

        $codigo = strtoupper(trim((string) $nota->encargado));
        if (! $this->esCodigoCompraAgil($codigo)) {
            throw new RuntimeException('La nota no tiene un código Compra Ágil válido en encargado.');
        }

        $corrida = NotaMpCorrida::query()->create([
            'usuario' => trim($usuario),
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 1,
            'notas_procesadas' => 0,
            'notas_con_cambio' => 0,
        ]);

        try {
            $resultado = $this->consultarNota($nronota, $corrida, $usuario, null);
            $this->finalizarCorrida($corrida, 'ok', 'Consulta individual nota '.$nronota.'.');

            return $resultado;
        } catch (RuntimeException $e) {
            $this->registrarDetalleFallo(
                $corrida,
                $nronota,
                $codigo,
                $e->getMessage(),
                trim((string) ($nota->empresa ?? '')),
            );
            $corrida->increment('notas_procesadas');
            $this->finalizarCorrida($corrida, 'error', $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<NotaMpSeguimiento>
     */
    private function buildPendientesSeguimientoQuery(array $filtros): \Illuminate\Database\Eloquent\Builder
    {
        return $this->aplicarFiltrosListadoSeguimiento(
            NotaMpSeguimiento::query()->where('resultado_propio', 'pendiente'),
            $filtros,
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Nota>
     */
    private function buildTodasNotasQuery(array $filtros): \Illuminate\Database\Eloquent\Builder
    {
        $query = Nota::query()
            ->select('notas.*')
            ->leftJoin('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota');

        $this->aplicarFiltroCodigoCaEnNotas($query);

        if (! empty($filtros['nronota'])) {
            $query->where('notas.nronota', (int) $filtros['nronota']);
        }

        if (! empty($filtros['codigo_proceso'])) {
            $term = '%'.$filtros['codigo_proceso'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('seg.codigo_proceso', 'ilike', $term)
                    ->orWhere(function ($q2) use ($term): void {
                        $q2->whereNull('seg.nronota')
                            ->where('notas.encargado', 'ilike', $term);
                    });
            });
        }

        if (! empty($filtros['organismo'])) {
            $term = '%'.$filtros['organismo'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('seg.organismo', 'ilike', $term)
                    ->orWhere(function ($q2) use ($term): void {
                        $q2->whereNull('seg.nronota')
                            ->where('notas.empresa', 'ilike', $term);
                    });
            });
        }

        if (! empty($filtros['proveedor'])) {
            $query->where('seg.razon_social_ganador', 'ilike', '%'.$filtros['proveedor'].'%');
        }

        if (! empty($filtros['fecha_desde'])) {
            $query->where(function ($q) use ($filtros): void {
                $q->where('seg.fecha_publicacion', '>=', $filtros['fecha_desde'].' 00:00:00')
                    ->orWhere(function ($q2) use ($filtros): void {
                        $q2->whereNull('seg.nronota')
                            ->where('notas.fecha', '>=', $filtros['fecha_desde']);
                    });
            });
        }

        if (! empty($filtros['fecha_hasta'])) {
            $query->where(function ($q) use ($filtros): void {
                $q->where('seg.fecha_publicacion', '<=', $filtros['fecha_hasta'].' 23:59:59')
                    ->orWhere(function ($q2) use ($filtros): void {
                        $q2->whereNull('seg.nronota')
                            ->where('notas.fecha', '<=', $filtros['fecha_hasta']);
                    });
            });
        }

        if (! empty($filtros['cambio_desde'])) {
            $query->where('seg.fecha_ultimo_cambio', '>=', $filtros['cambio_desde'].' 00:00:00');
        }

        if (! empty($filtros['cambio_hasta'])) {
            $query->where('seg.fecha_ultimo_cambio', '<=', $filtros['cambio_hasta'].' 23:59:59');
        }

        if (! empty($filtros['seguimiento'])) {
            if ($filtros['seguimiento'] === 'sin_consultar') {
                $query->whereNull('seg.nronota');
            } else {
                $query->where('seg.resultado_propio', $filtros['seguimiento']);
            }
        }

        if (! empty($filtros['estado_mp'])) {
            $term = '%'.$filtros['estado_mp'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('seg.estado_mp_glosa', 'ilike', $term)
                    ->orWhere('seg.estado_mp_codigo', 'ilike', $term);
            });
        }

        return $query
            ->orderByRaw('COALESCE(seg.fecha_ultimo_cambio, notas.fecha) DESC')
            ->orderByDesc('notas.nronota');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Nota>  $query
     */
    private function aplicarFiltroCodigoCaEnNotas(\Illuminate\Database\Eloquent\Builder $query, string $column = 'notas.encargado'): void
    {
        $query->whereRaw("trim(coalesce({$column}, '')) <> ''");

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $query->whereRaw("upper(trim({$column})) ~ ?", ['^\d+-\d+-COT\d+$']);

            return;
        }

        $query->whereRaw("upper({$column}) LIKE '%-%-COT%'");
    }

    private function itemListadoTodasDesdeNota(Nota $nota): NotaMpSeguimiento
    {
        $seg = $nota->relationLoaded('mpSeguimiento') ? $nota->mpSeguimiento : null;

        if ($seg !== null) {
            $seg->setRelation('nota', $nota);

            return $seg;
        }

        $placeholder = new NotaMpSeguimiento([
            'nronota' => $nota->nronota,
            'codigo_proceso' => strtoupper(trim((string) $nota->encargado)),
            'organismo' => trim((string) ($nota->empresa ?? '')),
            'resultado_propio' => 'sin_consultar',
        ]);
        $placeholder->exists = false;
        $placeholder->setRelation('nota', $nota);

        return $placeholder;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<NotaMpSeguimiento>  $query
     * @return \Illuminate\Database\Eloquent\Builder<NotaMpSeguimiento>
     */
    private function aplicarFiltrosListadoSeguimiento(
        \Illuminate\Database\Eloquent\Builder $query,
        array $filtros,
    ): \Illuminate\Database\Eloquent\Builder {
        if (! empty($filtros['nronota'])) {
            $query->where('nronota', (int) $filtros['nronota']);
        }

        if (! empty($filtros['codigo_proceso'])) {
            $query->where('codigo_proceso', 'ilike', '%'.$filtros['codigo_proceso'].'%');
        }

        if (! empty($filtros['organismo'])) {
            $query->where('organismo', 'ilike', '%'.$filtros['organismo'].'%');
        }

        if (! empty($filtros['proveedor'])) {
            $query->where('razon_social_ganador', 'ilike', '%'.$filtros['proveedor'].'%');
        }

        if (! empty($filtros['fecha_desde'])) {
            $query->where('fecha_publicacion', '>=', $filtros['fecha_desde'].' 00:00:00');
        }

        if (! empty($filtros['fecha_hasta'])) {
            $query->where('fecha_publicacion', '<=', $filtros['fecha_hasta'].' 23:59:59');
        }

        if (! empty($filtros['cambio_desde'])) {
            $query->where('fecha_ultimo_cambio', '>=', $filtros['cambio_desde'].' 00:00:00');
        }

        if (! empty($filtros['cambio_hasta'])) {
            $query->where('fecha_ultimo_cambio', '<=', $filtros['cambio_hasta'].' 23:59:59');
        }

        if (! empty($filtros['seguimiento'])) {
            $query->where('resultado_propio', $filtros['seguimiento']);
        }

        if (! empty($filtros['estado_mp'])) {
            $term = '%'.$filtros['estado_mp'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('estado_mp_glosa', 'ilike', $term)
                    ->orWhere('estado_mp_codigo', 'ilike', $term);
            });
        }

        return $query;
    }

    public function listadoCerradasPaginado(int $porPagina = 20, array $filtros = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $paginator = $this->aplicarOrdenCerradas($this->buildCerradasQuery($filtros))
            ->with(['nota.usuarioRel', 'ofertas' => fn ($q) => $q->whereRaw('proveedor_seleccionado IS TRUE')->with('lineas')])
            ->paginate($porPagina)
            ->withQueryString();

        $this->marcarCerradasConFlags($paginator->getCollection());

        return $paginator;
    }

    /**
     * @return Collection<int, NotaMpSeguimiento>
     */
    public function listadoCerradasExportar(array $filtros = [], int $limite = 10000): Collection
    {
        $items = $this->aplicarOrdenCerradas($this->buildCerradasQuery($filtros))
            ->with(['nota.usuarioRel'])
            ->limit($limite)
            ->get();

        return $this->marcarCerradasConFlags($items);
    }

    public function registrarDetalleFallo(
        NotaMpCorrida $corrida,
        int $nronota,
        string $codigo,
        string $mensaje,
        ?string $empresa = null,
    ): void {
        NotaMpCorridaDetalle::query()->updateOrCreate(
            ['corrida_id' => $corrida->id, 'nronota' => $nronota],
            [
                'codigo_proceso' => mb_substr(strtoupper(trim($codigo)), 0, 40),
                'empresa' => $empresa !== null ? mb_substr(trim($empresa), 0, 200) : null,
                'exito' => false,
                'mensaje' => mb_substr(trim($mensaje), 0, 500),
                'estado_mp_glosa' => null,
                'resultado_propio' => null,
                'rut_ganador' => null,
                'razon_social_ganador' => null,
                'cambio' => false,
            ],
        );
    }

    /**
     * @return Collection<int, NotaMpCorridaDetalle>
     */
    public function detalleUltimaCorrida(?NotaMpCorrida $corrida = null): Collection
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return collect();
        }

        return NotaMpCorridaDetalle::query()
            ->where('corrida_id', $corrida->id)
            ->orderBy('nronota')
            ->get();
    }

    public function detalleUltimaCorridaPaginado(int $perPage = 50, ?NotaMpCorrida $corrida = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return NotaMpCorridaDetalle::query()->whereRaw('1=0')->paginate($perPage);
        }

        return NotaMpCorridaDetalle::query()
            ->where('corrida_id', $corrida->id)
            ->orderBy('nronota')
            ->paginate($perPage);
    }

    /**
     * Último cambio de estado MP por cotización, ordenado por fecha de último cambio del proceso.
     *
     * @return Collection<int, NotaMpCorridaCambio>
     */
    public function novedadesRecientes(int $limite = 40): Collection
    {
        $limite = max(1, min($limite, 100));
        $rutPropio = $this->ganador->rutEmpresaPropia();
        $ultimaCorridaId = $this->ultimaCorrida()?->id;

        $ultimoCambioPorNota = NotaMpCorridaCambio::query()
            ->join('nota_mp_seguimientos as seg_filter', 'nota_mp_corrida_cambios.nronota', '=', 'seg_filter.nronota')
            ->whereNotNull('seg_filter.fecha_ultimo_cambio')
            ->groupBy('nota_mp_corrida_cambios.nronota')
            ->selectRaw('MAX(nota_mp_corrida_cambios.id) as id');

        $items = NotaMpCorridaCambio::query()
            ->with(['nota', 'seguimiento'])
            ->join('nota_mp_seguimientos as seg', 'nota_mp_corrida_cambios.nronota', '=', 'seg.nronota')
            ->whereIn('nota_mp_corrida_cambios.id', $ultimoCambioPorNota)
            ->orderByDesc('seg.fecha_ultimo_cambio')
            ->select('nota_mp_corrida_cambios.*')
            ->limit($limite)
            ->get();

        return $items->each(function (NotaMpCorridaCambio $nov) use ($rutPropio, $ultimaCorridaId): void {
            $nov->es_ganador_propio = $rutPropio !== ''
                && $this->ganador->rutsCoinciden($nov->rut_ganador, $rutPropio);
            $nov->cambio_ultima_consulta = $ultimaCorridaId !== null
                && (int) $nov->corrida_id === (int) $ultimaCorridaId;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function detalleNota(int $nronota): array
    {
        $seg = NotaMpSeguimiento::query()
            ->with(['nota', 'ofertas.lineas'])
            ->find($nronota);

        if ($seg === null) {
            throw new RuntimeException('Sin seguimiento MP para esta nota.');
        }

        return [
            'seguimiento' => $seg,
            'ofertas' => $seg->ofertas,
            'lineas_ganador' => $seg->ofertas
                ->first(fn (NotaMpOferta $o) => $o->proveedor_seleccionado)
                ?->lineas ?? collect(),
        ];
    }

    /**
     * Fechas MP: en procesos abiertos se actualizan en cada consulta; en cerrados solo se rellenan vacíos.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, Carbon>
     */
    private function fechasSeguimientoParaGuardar(array $payload, bool $finalizado, ?NotaMpSeguimiento $anterior): array
    {
        $campos = [
            'fecha_publicacion' => $this->parseFechaMp(data_get($payload, 'fechas.fecha_publicacion')),
            'fecha_cierre' => $this->parseFechaMp(data_get($payload, 'fechas.fecha_cierre')),
            'fecha_ultimo_cambio' => $this->parseFechaMp(data_get($payload, 'fechas.fecha_ultimo_cambio')),
            'fecha_cancelacion' => $this->parseFechaMp(data_get($payload, 'fechas.fecha_cancelacion')),
        ];

        $out = [];
        foreach ($campos as $campo => $valor) {
            if ($valor === null) {
                continue;
            }
            if ($finalizado && $anterior !== null && $anterior->{$campo} !== null) {
                continue;
            }
            $out[$campo] = $valor;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function convocatoriaSeguimientoParaGuardar(array $payload): array
    {
        $conv = is_array($payload['convocatoria'] ?? null) ? $payload['convocatoria'] : [];
        if ($conv === []) {
            return [];
        }

        $out = [];
        if (isset($conv['estado_convocatoria']) && $conv['estado_convocatoria'] !== '') {
            $out['convocatoria_estado'] = (int) $conv['estado_convocatoria'];
        }
        $descripcion = mb_substr(trim((string) ($conv['descripcion'] ?? '')), 0, 120);
        if ($descripcion !== '') {
            $out['convocatoria_descripcion'] = $descripcion;
        }
        $cierrePrimero = $this->parseFechaMp($conv['fecha_cierre_primer_llamado'] ?? null);
        if ($cierrePrimero !== null) {
            $out['fecha_cierre_primer_llamado'] = $cierrePrimero;
        }
        $cierreSegundo = $this->parseFechaMp($conv['fecha_cierre_segundo_llamado'] ?? null);
        if ($cierreSegundo !== null) {
            $out['fecha_cierre_segundo_llamado'] = $cierreSegundo;
        }

        return $out;
    }

    private function parseFechaMp(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
