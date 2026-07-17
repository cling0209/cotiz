<?php

namespace App\Services;

use App\Jobs\ProcessOportunidadBusquedaJob;
use App\Models\OportunidadBusquedaCorrida;
use Illuminate\Support\Carbon;
use RuntimeException;

class OportunidadBusquedaService
{
    public const ESTADO_RUNNING = 'running';

    public const ESTADO_COMPLETED = 'completed';

    public const ESTADO_CANCELLED = 'cancelled';

    public const ESTADO_ERROR = 'error';

    private const PASO_PENDING = 'pending';

    private const PASO_OK = 'ok';

    private const PASO_FAILED = 'failed';

    private const PASO_RETRY_FAILED = 'retry_failed';

    public function __construct(
        protected OportunidadParaCotizarService $oportunidades,
    ) {}

    public function habilitada(): bool
    {
        return (bool) config('cotiz.mercadopublico.analisis_admin_habilitado', false);
    }

    public function corridaEnCurso(): ?OportunidadBusquedaCorrida
    {
        return OportunidadBusquedaCorrida::query()
            ->where('estado', self::ESTADO_RUNNING)
            ->latest('id')
            ->first();
    }

    public function ultimaCorrida(): ?OportunidadBusquedaCorrida
    {
        return OportunidadBusquedaCorrida::query()->latest('id')->first();
    }

    public function iniciar(string $usuario = 'sistema', mixed $fechaBusqueda = null): OportunidadBusquedaCorrida
    {
        if (! $this->habilitada()) {
            throw new RuntimeException('La búsqueda automática de oportunidades no está habilitada en este sitio.');
        }

        if (! $this->oportunidades->apiConfigurada()) {
            throw new RuntimeException('API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.');
        }

        if (config('queue.default') === 'sync' && app()->isProduction()) {
            throw new RuntimeException(
                'La búsqueda en segundo plano requiere QUEUE_CONNECTION=database y RUN_QUEUE_WORKER=true en Render.'
            );
        }

        $existente = $this->corridaEnCurso();
        if ($existente !== null) {
            return $existente;
        }

        $dia = $fechaBusqueda === null
            ? $this->primeraFechaPendiente()
            : $this->normalizarFechaCorrida($fechaBusqueda);

        if ($dia === null) {
            $ultima = $this->ultimaCorrida();
            if ($ultima !== null) {
                return $ultima;
            }

            throw new RuntimeException('No hay fechas pendientes para buscar oportunidades.');
        }

        $plan = $this->oportunidades->planBusqueda();
        if ($plan['error'] !== null) {
            throw new RuntimeException((string) $plan['error']);
        }

        $pasos = is_array($plan['pasos'] ?? null) ? $plan['pasos'] : [];
        if ($pasos === []) {
            throw new RuntimeException('No hay palabras clave o regiones configuradas para buscar.');
        }

        $pasos = $this->enriquecerPlan($pasos);

        $corrida = OportunidadBusquedaCorrida::query()->create([
            'usuario' => trim($usuario) ?: 'sistema',
            'fecha_busqueda' => $dia,
            'inicio' => now(),
            'estado' => self::ESTADO_RUNNING,
            'total_pasos' => count($pasos),
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasEn($dia)),
            'plan_json' => $pasos,
            'errores_json' => [],
            'mensaje' => 'Búsqueda encolada para '.$this->formatearFechaMensaje($dia).'.',
        ]);

        ProcessOportunidadBusquedaJob::dispatch($corrida->id);

        return $corrida;
    }

    public function procesar(OportunidadBusquedaCorrida $corrida): void
    {
        while ($this->procesarPaso($corrida)) {
            $corrida->refresh();
        }
    }

    /**
     * Procesa un único paso (o reintento de región).
     * Orden: región completa → reintentar fallidos de esa región → siguiente región.
     */
    public function procesarPaso(OportunidadBusquedaCorrida $corrida): bool
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
        $cursor = (int) $corrida->pasos_procesados;
        $pasos = $this->asegurarEstadosPlan($pasos, $cursor, $errores);

        $seleccion = $this->seleccionarSiguiente($pasos);
        if ($seleccion === null) {
            $this->persistirPlan($corrida, $pasos, $errores, (int) $corrida->pasos_fallidos, $corrida->mensaje);
            $this->finalizar($corrida);

            return false;
        }

        $indice = (int) $seleccion['indice'];
        $fase = (string) $seleccion['fase'];
        $paso = is_array($pasos[$indice] ?? null) ? $pasos[$indice] : [];
        $frase = trim((string) ($paso['frase'] ?? ''));
        $region = (int) ($paso['region'] ?? 0);
        $fallidos = $this->contarFallidosDefinitivos($pasos);

        $fechaBusqueda = $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda);

        try {
            $resultado = $this->oportunidades->ejecutarPaso($frase, $region, [], null, $fechaBusqueda);
            $encontradas = count(is_array($resultado['items'] ?? null) ? $resultado['items'] : []);
            $pasos[$indice]['estado'] = self::PASO_OK;
            $pasos[$indice]['intentos'] = (int) ($pasos[$indice]['intentos'] ?? 0) + 1;
            $pasos[$indice]['encontradas'] = $encontradas;
            $fallidos = $this->contarFallidosDefinitivos($pasos);
            $mensaje = $fase === 'reintento'
                ? sprintf(
                    'Reintento OK región %d · «%s»: %d cotización(es) (%d/%d pasos).',
                    $region,
                    $frase,
                    $encontradas,
                    $this->contarTerminados($pasos),
                    count($pasos),
                )
                : sprintf(
                    'Paso región %d · «%s»: %d cotización(es) (%d/%d).',
                    $region,
                    $frase,
                    $encontradas,
                    $this->contarTerminados($pasos),
                    count($pasos),
                );
        } catch (\Throwable $e) {
            $intentos = (int) ($pasos[$indice]['intentos'] ?? 0) + 1;
            $pasos[$indice]['intentos'] = $intentos;
            $pasos[$indice]['estado'] = $intentos >= 2
                ? self::PASO_RETRY_FAILED
                : self::PASO_FAILED;
            $pasos[$indice]['encontradas'] = 0;

            $errores[] = [
                'indice' => $indice,
                'frase' => $frase,
                'region' => $region,
                'fase' => $fase,
                'intento' => $intentos,
                'mensaje' => mb_substr($e->getMessage(), 0, 500),
                'fecha' => now()->toIso8601String(),
            ];
            $fallidos = $this->contarFallidosDefinitivos($pasos);
            $mensaje = $fase === 'reintento'
                ? sprintf(
                    'Reintento fallido región %d · «%s»; se sigue con la siguiente región. %s',
                    $region,
                    $frase,
                    mb_substr($e->getMessage(), 0, 200),
                )
                : sprintf(
                    'Paso fallido región %d · «%s»; al cerrar la región se reintentará. %s',
                    $region,
                    $frase,
                    mb_substr($e->getMessage(), 0, 200),
                );
        }

        $actualizada = OportunidadBusquedaCorrida::query()
            ->whereKey($corrida->id)
            ->where('estado', self::ESTADO_RUNNING)
            ->where('pasos_procesados', $cursor)
            ->update([
                'pasos_procesados' => $cursor + 1,
                'pasos_fallidos' => $fallidos,
                'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasEn($fechaBusqueda)),
                'plan_json' => json_encode(array_values($pasos), JSON_UNESCAPED_UNICODE),
                'errores_json' => json_encode(array_slice($errores, -100), JSON_UNESCAPED_UNICODE),
                'mensaje' => $mensaje,
                'updated_at' => now(),
            ]);

        $corrida->refresh();
        if ($actualizada !== 1) {
            // Colisión entre workers: reencolar si aún quedan pasos por procesar.
            if ($corrida->estado === self::ESTADO_RUNNING
                && $this->seleccionarSiguiente(is_array($corrida->plan_json) ? $corrida->plan_json : []) !== null) {
                return true;
            }

            return false;
        }

        if ($this->seleccionarSiguiente(is_array($corrida->plan_json) ? $corrida->plan_json : []) === null) {
            $this->finalizar($corrida);

            return false;
        }

        return $corrida->estado === self::ESTADO_RUNNING;
    }

    public function cancelar(?OportunidadBusquedaCorrida $corrida = null): ?OportunidadBusquedaCorrida
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null) {
            return null;
        }

        $corrida->fill([
            'estado' => self::ESTADO_CANCELLED,
            'fin' => now(),
            'mensaje' => 'Búsqueda cancelada por el usuario.',
        ])->save();

        return $corrida;
    }

    /**
     * @return array{accion: string, mensaje: string, corrida_id: int|null}
     */
    public function catchUp(string $usuario = 'sistema', bool $reanudarActiva = true): array
    {
        if (! $this->habilitada()) {
            return ['accion' => 'omitido', 'mensaje' => 'Búsqueda automática deshabilitada en este sitio.', 'corrida_id' => null];
        }

        $activa = $this->corridaEnCurso();
        if ($activa !== null) {
            if ($reanudarActiva) {
                ProcessOportunidadBusquedaJob::dispatch($activa->id);
            }

            return [
                'accion' => $reanudarActiva ? 'reanudada' : 'en_curso',
                'mensaje' => $reanudarActiva
                    ? 'Corrida de oportunidades reanudada.'
                    : 'Ya hay una corrida de oportunidades en curso.',
                'corrida_id' => $activa->id,
            ];
        }

        $slot = $this->ultimoHorarioProgramado();
        if ($slot === null) {
            return ['accion' => 'omitido', 'mensaje' => 'No hay horarios programados válidos.', 'corrida_id' => null];
        }

        $fechaPendiente = $this->primeraFechaPendiente();
        if ($fechaPendiente === null) {
            return ['accion' => 'omitido', 'mensaje' => 'Todas las fechas hasta hoy ya tienen corrida.', 'corrida_id' => null];
        }

        $yaEjecutada = OportunidadBusquedaCorrida::query()
            ->where('inicio', '>=', $slot)
            ->whereDate('fecha_busqueda', $fechaPendiente)
            ->exists();
        if ($yaEjecutada) {
            return [
                'accion' => 'omitido',
                'mensaje' => 'El último horario programado ya tiene corrida para '.$this->formatearFechaMensaje($fechaPendiente).'.',
                'corrida_id' => null,
            ];
        }

        $corrida = $this->iniciar($usuario, $fechaPendiente);

        return [
            'accion' => 'encolada',
            'mensaje' => 'Catch-up de oportunidades encolado para '.$this->formatearFechaMensaje($fechaPendiente).'.',
            'corrida_id' => $corrida->id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function estado(?OportunidadBusquedaCorrida $corrida = null): ?array
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return null;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $total = max(0, (int) $corrida->total_pasos);
        $terminados = $this->contarTerminados($pasos);

        $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
        $ultimoError = $errores !== [] ? $errores[array_key_last($errores)] : null;
        $fechaBusqueda = $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda);

        return [
            'id' => $corrida->id,
            'estado' => $corrida->estado,
            'fecha_busqueda' => $fechaBusqueda,
            'inicio' => $corrida->inicio?->toIso8601String(),
            'fin' => $corrida->fin?->toIso8601String(),
            'total_pasos' => $total,
            'pasos_procesados' => $terminados,
            'pasos_fallidos' => (int) $corrida->pasos_fallidos,
            'oportunidades_encontradas' => (int) $corrida->oportunidades_encontradas,
            'progreso' => $total > 0 ? min(100, (int) round(($terminados / $total) * 100)) : 0,
            'mensaje' => $corrida->mensaje,
            'errores' => $errores,
            'ultimo_error' => is_array($ultimoError) ? $ultimoError : null,
            'pasos_resumen' => $this->resumirPasosCorrida($pasos, $errores, $fechaBusqueda),
            'items' => $this->oportunidades->listarGuardadasEn($fechaBusqueda),
        ];
    }

    /**
     * Resumen por paso para la UI: región, frase y resultado
     * (OK 1.er intento, OK en reintento, fallido pendiente de reintento, fallido definitivo).
     *
     * @param  list<array<string, mixed>>  $pasos
     * @param  list<array<string, mixed>>  $errores
     * @return list<array<string, mixed>>
     */
    private function resumirPasosCorrida(array $pasos, array $errores, string $fechaBusqueda): array
    {
        $ultimoErrorPorIndice = [];
        foreach ($errores as $error) {
            if (is_array($error) && isset($error['indice'])) {
                $ultimoErrorPorIndice[(int) $error['indice']] = trim((string) ($error['mensaje'] ?? ''));
            }
        }

        $out = [];
        foreach (array_values($pasos) as $i => $paso) {
            if (! is_array($paso)) {
                continue;
            }

            $estado = (string) ($paso['estado'] ?? self::PASO_PENDING);
            $intentos = (int) ($paso['intentos'] ?? 0);

            [$resultado, $etiqueta] = match (true) {
                $estado === self::PASO_OK && $intentos > 1 => ['ok_reintento', 'OK (reintento)'],
                $estado === self::PASO_OK => ['ok', 'OK (1.er intento)'],
                $estado === self::PASO_RETRY_FAILED => ['fallo_definitivo', 'Falló (definitivo)'],
                $estado === self::PASO_FAILED => ['fallo_reintentara', 'Falló (se reintentará)'],
                default => ['pendiente', 'Pendiente'],
            };

            $encontradas = array_key_exists('encontradas', $paso)
                ? (int) $paso['encontradas']
                : null;

            $out[] = [
                'indice' => $i,
                'fecha_busqueda' => $fechaBusqueda,
                'region' => (int) ($paso['region'] ?? 0),
                'region_nombre' => (string) ($paso['region_nombre'] ?? ''),
                'frase' => (string) ($paso['frase'] ?? ''),
                'intentos' => $intentos,
                'encontradas' => $encontradas,
                'resultado' => $resultado,
                'etiqueta' => $etiqueta,
                'error' => $estado === self::PASO_FAILED || $estado === self::PASO_RETRY_FAILED
                    ? ($ultimoErrorPorIndice[$i] ?? null)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     * @return list<array<string, mixed>>
     */
    private function enriquecerPlan(array $pasos): array
    {
        $out = [];
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            $out[] = [
                'frase' => trim((string) ($paso['frase'] ?? '')),
                'region' => (int) ($paso['region'] ?? 0),
                'region_nombre' => (string) ($paso['region_nombre'] ?? ''),
                'estado' => self::PASO_PENDING,
                'intentos' => 0,
                'encontradas' => null,
            ];
        }

        return $out;
    }

    /**
     * Compatibilidad con corridas creadas antes del estado por paso.
     *
     * @param  list<array<string, mixed>>  $pasos
     * @param  list<array<string, mixed>>  $errores
     * @return list<array<string, mixed>>
     */
    private function asegurarEstadosPlan(array $pasos, int $cursorLineal, array $errores): array
    {
        if ($pasos === [] || isset($pasos[0]['estado'])) {
            return $pasos;
        }

        $fallidosIdx = [];
        foreach ($errores as $error) {
            if (is_array($error) && isset($error['indice'])) {
                $fallidosIdx[(int) $error['indice']] = true;
            }
        }

        foreach ($pasos as $i => $paso) {
            if (! is_array($paso)) {
                continue;
            }
            if ($i < $cursorLineal) {
                $pasos[$i]['estado'] = isset($fallidosIdx[$i]) ? self::PASO_FAILED : self::PASO_OK;
                $pasos[$i]['intentos'] = 1;
            } else {
                $pasos[$i]['estado'] = self::PASO_PENDING;
                $pasos[$i]['intentos'] = 0;
            }
        }

        return $pasos;
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     * @return array{indice: int, fase: string}|null
     */
    private function seleccionarSiguiente(array $pasos): ?array
    {
        $regiones = [];
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            $region = (int) ($paso['region'] ?? 0);
            if (! in_array($region, $regiones, true)) {
                $regiones[] = $region;
            }
        }

        foreach ($regiones as $region) {
            $indices = [];
            foreach ($pasos as $i => $paso) {
                if (is_array($paso) && (int) ($paso['region'] ?? 0) === $region) {
                    $indices[] = $i;
                }
            }

            foreach ($indices as $i) {
                if (($pasos[$i]['estado'] ?? '') === self::PASO_PENDING) {
                    return ['indice' => $i, 'fase' => 'primario'];
                }
            }

            // Región sin pendientes: reintentar fallidos una vez antes de pasar a la siguiente.
            foreach ($indices as $i) {
                $estado = (string) ($pasos[$i]['estado'] ?? '');
                $intentos = (int) ($pasos[$i]['intentos'] ?? 0);
                if ($estado === self::PASO_FAILED && $intentos === 1) {
                    return ['indice' => $i, 'fase' => 'reintento'];
                }
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
            if (! is_array($paso)) {
                continue;
            }
            $estado = (string) ($paso['estado'] ?? '');
            if (in_array($estado, [self::PASO_OK, self::PASO_RETRY_FAILED], true)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     */
    private function contarFallidosDefinitivos(array $pasos): int
    {
        $n = 0;
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            if (($paso['estado'] ?? '') === self::PASO_RETRY_FAILED) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     * @param  list<array<string, mixed>>  $errores
     */
    private function persistirPlan(
        OportunidadBusquedaCorrida $corrida,
        array $pasos,
        array $errores,
        int $fallidos,
        ?string $mensaje,
    ): void {
        $corrida->fill([
            'plan_json' => array_values($pasos),
            'errores_json' => array_slice($errores, -100),
            'pasos_fallidos' => $fallidos,
            'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasEn($corrida->fecha_busqueda)),
            'mensaje' => $mensaje,
        ])->save();
    }

    private function fechaInicioBusqueda(): string
    {
        return $this->normalizarFechaCorrida(
            config('cotiz.mercadopublico.fecha_inicio_busqueda', '2026-07-14'),
        ) ?? '2026-07-14';
    }

    private function normalizarFechaCorrida(mixed $fecha): ?string
    {
        $texto = trim((string) ($fecha ?? ''));
        if ($texto === '') {
            return null;
        }

        try {
            $dia = Carbon::parse($texto)
                ->timezone(config('app.timezone'))
                ->toDateString();
        } catch (\Throwable) {
            return null;
        }

        $hoy = $this->oportunidades->fechaBusquedaHoy();

        return $dia > $hoy ? $hoy : $dia;
    }

    private function primeraFechaPendiente(?string $desde = null): ?string
    {
        $inicio = Carbon::parse($desde ?? $this->fechaInicioBusqueda(), config('app.timezone'))->startOfDay();
        $hoy = Carbon::parse($this->oportunidades->fechaBusquedaHoy(), config('app.timezone'))->startOfDay();

        if ($inicio->greaterThan($hoy)) {
            return null;
        }

        for ($dia = $inicio->copy(); $dia->lessThanOrEqualTo($hoy); $dia->addDay()) {
            $fecha = $dia->toDateString();
            if (! $this->fechaTieneCorridaCompleta($fecha)) {
                return $fecha;
            }
        }

        return null;
    }

    private function proximaFechaPendienteDespues(mixed $fecha): ?string
    {
        $dia = $this->normalizarFechaCorrida($fecha);
        if ($dia === null) {
            return $this->primeraFechaPendiente();
        }

        $siguiente = Carbon::parse($dia, config('app.timezone'))->addDay()->toDateString();

        return $this->primeraFechaPendiente($siguiente);
    }

    private function fechaTieneCorridaCompleta(string $fecha): bool
    {
        return OportunidadBusquedaCorrida::query()
            ->whereDate('fecha_busqueda', $fecha)
            ->where('estado', self::ESTADO_COMPLETED)
            ->exists();
    }

    private function formatearFechaMensaje(string $fecha): string
    {
        try {
            return Carbon::parse($fecha, config('app.timezone'))->format('d-m-Y');
        } catch (\Throwable) {
            return $fecha;
        }
    }

    private function ultimoHorarioProgramado(): ?Carbon
    {
        $horas = collect(explode(',', (string) config('cotiz.mercadopublico.resultados_schedule_hours', '10,19')))
            ->map(fn ($hora) => (int) trim((string) $hora))
            ->filter(fn (int $hora) => $hora >= 0 && $hora <= 23)
            ->unique()
            ->sort()
            ->values();

        if ($horas->isEmpty()) {
            return null;
        }

        $ahora = now()->timezone((string) config('app.timezone', 'America/Santiago'));
        foreach ($horas->reverse() as $hora) {
            $candidato = $ahora->copy()->startOfDay()->addHours($hora);
            if ($candidato->lessThanOrEqualTo($ahora)) {
                return $candidato;
            }
        }

        return $ahora->copy()->subDay()->startOfDay()->addHours((int) $horas->last());
    }

    private function finalizar(OportunidadBusquedaCorrida $corrida): void
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $fallidos = $this->contarFallidosDefinitivos($pasos);
        $corrida->fill([
            'estado' => self::ESTADO_COMPLETED,
            'fin' => now(),
            'pasos_fallidos' => $fallidos,
            'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasEn($corrida->fecha_busqueda)),
            'mensaje' => $fallidos > 0
                ? 'Búsqueda terminada con '.$fallidos.' paso(s) fallido(s) tras reintento por región.'
                : 'Búsqueda terminada correctamente.',
        ])->save();

        $siguienteFecha = $this->proximaFechaPendienteDespues($corrida->fecha_busqueda);
        if ($siguienteFecha !== null && $this->corridaEnCurso() === null) {
            $this->iniciar((string) ($corrida->usuario ?? 'sistema'), $siguienteFecha);
        }
    }
}
