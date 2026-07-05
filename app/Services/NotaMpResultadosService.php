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
            ->whereIn('estado', ['ok', 'error', 'cancelled'])
            ->orderByDesc('id')
            ->first();
    }

    public function corridaEnCurso(): ?NotaMpCorrida
    {
        $corrida = NotaMpCorrida::query()
            ->where('estado', 'running')
            ->orderByDesc('id')
            ->first();

        if ($corrida === null) {
            return null;
        }

        if ($this->liberarCorridaColgadaIfNeeded($corrida)) {
            return null;
        }

        return $corrida->fresh() ?? $corrida;
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

        $segundosEnNotaActual = null;
        if ($tieneCodigoActual && $corrida->updated_at !== null) {
            $segundosEnNotaActual = (int) $corrida->updated_at->diffInSeconds(now());
        }

        if ($corrida->estado === 'running' && $tieneCodigoActual && $segundosEnNotaActual !== null) {
            $umbralAlertaNota = $this->notaAlertaSegundos();
            if ($segundosEnNotaActual >= $umbralAlertaNota) {
                $alerta = 'Consultando '.$corrida->codigo_actual.' lleva '
                    .(int) floor($segundosEnNotaActual / 60).' min. '
                    .'Si supera '.$umbralAlertaNota.' s se registrará como fallo y continuará con la siguiente.';
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
     * @return array<string, mixed>
     */
    public function consultarNota(int $nronota, NotaMpCorrida $corrida, string $usuario, ?float $deadline = null): array
    {
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

        try {
            $payload = $this->api->detalle($codigo, usarCache: false);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'No existe Compra Ágil')) {
                return $this->marcarNoExisteEnMp($nronota, $codigo, $corrida, $usuario, $nota, $anterior);
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

            $corrida->increment('notas_procesadas');

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
            'estado_nuevo' => $estadoCodigo,
            'estado_glosa' => $estadoGlosa,
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
            foreach ($productos as $linea) {
                if (! is_array($linea)) {
                    continue;
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
                    NotaMpOfertaLinea::query()->insert($lineasBatch);
                    $lineasBatch = [];
                }
            }
        }

        if ($lineasBatch !== []) {
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
        return NotaMpSeguimiento::query()
            ->with(['nota', 'ofertas' => fn ($q) => $q->whereRaw('proveedor_seleccionado IS TRUE')->with('lineas')])
            ->whereRaw('finalizado IS TRUE')
            ->orderByRaw('fecha_publicacion IS NULL')
            ->orderByDesc('fecha_publicacion')
            ->limit($limite)
            ->get();
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

    public function listadoCerradasPaginado(int $porPagina = 20, array $filtros = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = NotaMpSeguimiento::query()
            ->with(['nota', 'ofertas' => fn ($q) => $q->whereRaw('proveedor_seleccionado IS TRUE')->with('lineas')])
            ->whereRaw('finalizado IS TRUE');

        if (! empty($filtros['nronota'])) {
            $query->where('nronota', (int) $filtros['nronota']);
        }

        if (! empty($filtros['codigo_proceso'])) {
            $query->where('codigo_proceso', 'ilike', '%' . $filtros['codigo_proceso'] . '%');
        }

        if (! empty($filtros['organismo'])) {
            $query->where('organismo', 'ilike', '%' . $filtros['organismo'] . '%');
        }

        if (! empty($filtros['proveedor'])) {
            $query->where('razon_social_ganador', 'ilike', '%' . $filtros['proveedor'] . '%');
        }

        if (! empty($filtros['fecha_desde'])) {
            $query->where('fecha_publicacion', '>=', $filtros['fecha_desde'] . ' 00:00:00');
        }

        if (! empty($filtros['fecha_hasta'])) {
            $query->where('fecha_publicacion', '<=', $filtros['fecha_hasta'] . ' 23:59:59');
        }

        return $query
            ->orderByRaw('fecha_publicacion IS NULL')
            ->orderByDesc('fecha_publicacion')
            ->paginate($porPagina)
            ->withQueryString();
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
     * @return Collection<int, NotaMpCorridaCambio>
     */
    public function novedadesUltimaCorrida(?NotaMpCorrida $corrida = null): Collection
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return collect();
        }

        return NotaMpCorridaCambio::query()
            ->with(['nota', 'seguimiento'])
            ->where('corrida_id', $corrida->id)
            ->get()
            ->sortByDesc(fn (NotaMpCorridaCambio $nov) => $nov->seguimiento?->fecha_publicacion?->timestamp ?? 0)
            ->values();
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
     * Por ahora solo persiste fechas MP en procesos cerrados y sin sobrescribir valores ya guardados.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, Carbon>
     */
    private function fechasSeguimientoParaGuardar(array $payload, bool $finalizado, ?NotaMpSeguimiento $anterior): array
    {
        if (! $finalizado) {
            return [];
        }

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
            if ($anterior !== null && $anterior->{$campo} !== null) {
                continue;
            }
            $out[$campo] = $valor;
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
