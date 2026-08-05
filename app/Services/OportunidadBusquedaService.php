<?php

namespace App\Services;

use App\Jobs\ProcessOportunidadBusquedaJob;
use App\Models\OportunidadBusquedaCorrida;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDOException;
use RuntimeException;
use Throwable;

class OportunidadBusquedaService
{
    public const ESTADO_RUNNING = 'running';

    public const ESTADO_COMPLETED = 'completed';

    public const ESTADO_CANCELLED = 'cancelled';

    public const ESTADO_ERROR = 'error';

    private const PASO_PENDING = 'pending';

    private const PASO_RUNNING = 'running';

    private const PASO_OK = 'ok';

    private const PASO_FAILED = 'failed';

    private const PASO_RETRY_FAILED = 'retry_failed';

    private const PASO_CANCELLED = 'cancelled';

    public const MENSAJE_CANCELADA = 'Búsqueda cancelada por el usuario.';

    public function __construct(
        protected OportunidadParaCotizarService $oportunidades,
        protected OportunidadVinculoService $vinculos,
        protected OportunidadEncontradaRelayService $encontradaRelay,
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

    public function iniciar(
        string $usuario = 'sistema',
        mixed $fechaBusqueda = null,
        bool $ventanaCompleta = false,
        mixed $cambioDesdeForzado = null,
    ): OportunidadBusquedaCorrida
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
        $cambioDesdeIso = $this->resolverCambioDesdeParaInicio($dia, $ventanaCompleta, $cambioDesdeForzado);
        $regionesReintento = $this->regionesFallidasDefinitivasUltimaCorrida($dia);
        if ($cambioDesdeIso !== null) {
            $pasos = array_map(static function (array $paso) use ($cambioDesdeIso, $regionesReintento): array {
                $region = (int) ($paso['region'] ?? 0);
                // Fallo definitivo previo: ventana completa del día (no desde última pub.).
                if ($region > 0 && in_array($region, $regionesReintento, true)) {
                    $paso['reintento_fallo_previo'] = true;

                    return $paso;
                }

                $paso['cambio_desde'] = $cambioDesdeIso;
                $paso['incremental'] = true;

                return $paso;
            }, $pasos);
            $pasos = $this->priorizarPasosReintentoFallidos($pasos);
        }

        $mensajeInicio = $this->mensajeInicioCorrida($dia, $cambioDesdeIso, $regionesReintento, $ventanaCompleta);
        $eventos = [];
        $eventoTxt = 'Búsqueda encolada ('.$this->formatearFechaMensaje($dia).', '.count($pasos).' pasos). Esperando worker…';
        if ($ventanaCompleta) {
            $eventoTxt = 'Búsqueda encolada con ventana completa del día ('.$this->formatearFechaMensaje($dia).', '.count($pasos).' pasos). Esperando worker…';
        } elseif ($cambioDesdeIso !== null) {
            $eventoTxt = 'Búsqueda incremental encolada desde '.$this->formatearFechaHoraMensaje($cambioDesdeIso)
                .' ('.$this->formatearFechaMensaje($dia).', '.count($pasos).' pasos). Esperando worker…';
        }
        $this->pushEvento($eventos, 'encolada', $eventoTxt);

        $payload = [
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
            'mensaje' => $mensajeInicio,
        ];
        if ($this->soportaEventosJson()) {
            $payload['eventos_json'] = $eventos;
        }

        $corrida = OportunidadBusquedaCorrida::query()->create($payload);

        ProcessOportunidadBusquedaJob::dispatch($corrida->id);

        return $corrida;
    }

    /**
     * Resuelve cambio_desde al iniciar:
     * - ventana completa → null (00:00 del día)
     * - forzado → timestamp indicado (p. ej. recuperar un salto)
     * - default → última pub. conocida + 1 min
     */
    private function resolverCambioDesdeParaInicio(
        string $dia,
        bool $ventanaCompleta,
        mixed $cambioDesdeForzado = null,
    ): ?string {
        if ($ventanaCompleta) {
            return null;
        }

        $forzado = trim((string) ($cambioDesdeForzado ?? ''));
        if ($forzado !== '') {
            try {
                $desde = Carbon::parse($forzado)->timezone((string) config('app.timezone'));
                $finDia = Carbon::parse($dia, (string) config('app.timezone'))->endOfDay();
                if ($desde->greaterThan($finDia)) {
                    throw new RuntimeException(
                        'cambio_desde no puede ser posterior al fin del día de búsqueda.'
                    );
                }

                return $desde->toIso8601String();
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new RuntimeException('cambio_desde inválido. Use una fecha/hora reconocible.');
            }
        }

        return $this->resolverCambioDesdeIncremental($dia);
    }

    /**
     * Para el día vigente (hoy): retoma desde la última publicación conocida + 1 minuto
     * (aunque esa pub. sea de un día anterior). Catch-up histórico (días pasados) usa el día completo.
     */
    private function resolverCambioDesdeIncremental(string $dia): ?string
    {
        $hoy = $this->oportunidades->fechaBusquedaHoy();
        if ($dia !== $hoy) {
            return null;
        }

        $ultima = $this->oportunidades->ultimaFechaPublicacionConocida();
        if ($ultima === null) {
            return null;
        }

        try {
            $finDia = Carbon::parse($dia, (string) config('app.timezone'))->endOfDay();
        } catch (\Throwable) {
            return null;
        }

        // Minuto siguiente a la última Pub. (ej. 31/07 17:35 → 17:36).
        $desde = $ultima->copy()->addMinute();
        if ($desde->greaterThan($finDia)) {
            return null;
        }

        return $desde->toIso8601String();
    }

    private function formatearFechaHoraMensaje(string $iso): string
    {
        try {
            return Carbon::parse($iso)->timezone((string) config('app.timezone'))->format('d-m-Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /**
     * Regiones con Falló (definitivo) en la última corrida completada del día.
     *
     * @return list<int>
     */
    private function regionesFallidasDefinitivasUltimaCorrida(string $dia): array
    {
        $ultima = OportunidadBusquedaCorrida::query()
            ->whereDate('fecha_busqueda', $dia)
            ->where('estado', self::ESTADO_COMPLETED)
            ->latest('id')
            ->first();

        if ($ultima === null) {
            return [];
        }

        $regiones = [];
        foreach (is_array($ultima->plan_json) ? $ultima->plan_json : [] as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            if (($paso['estado'] ?? '') !== self::PASO_RETRY_FAILED) {
                continue;
            }
            $region = (int) ($paso['region'] ?? 0);
            if ($region > 0 && ! in_array($region, $regiones, true)) {
                $regiones[] = $region;
            }
        }

        return $regiones;
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     * @return list<array<string, mixed>>
     */
    private function priorizarPasosReintentoFallidos(array $pasos): array
    {
        $reintento = [];
        $resto = [];
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            if (! empty($paso['reintento_fallo_previo'])) {
                $reintento[] = $paso;
            } else {
                $resto[] = $paso;
            }
        }

        return array_values(array_merge($reintento, $resto));
    }

    /**
     * @param  list<int>  $regionesReintento
     */
    private function mensajeInicioCorrida(
        string $dia,
        ?string $cambioDesdeIso,
        array $regionesReintento,
        bool $ventanaCompleta = false,
    ): string {
        $fecha = $this->formatearFechaMensaje($dia);
        $nReintento = count($regionesReintento);

        if ($ventanaCompleta) {
            $msg = 'Búsqueda con ventana completa encolada para '.$fecha.'.';
            if ($nReintento > 0) {
                $msg .= ' Reintento completo de '.$nReintento
                    .' región(es) fallida(s) en la corrida previa.';
            }

            return $msg;
        }

        if ($cambioDesdeIso !== null) {
            $msg = 'Búsqueda incremental encolada para '.$fecha
                .' desde '.$this->formatearFechaHoraMensaje($cambioDesdeIso).'.';
            if ($nReintento > 0) {
                $msg .= ' Reintento completo de '.$nReintento
                    .' región(es) fallida(s) en la corrida previa.';
            }

            return $msg;
        }

        return 'Búsqueda encolada para '.$fecha.'.';
    }

    public function procesar(OportunidadBusquedaCorrida $corrida): void
    {
        while ($this->procesarPaso($corrida)) {
            $corrida->refresh();
        }
    }

    /**
     * Procesa una unidad de trabajo: 1 página MP de la región actual (como Resultados por lote).
     * Si la región tiene más páginas, deja el paso en running y reencola.
     */
    public function procesarPaso(OportunidadBusquedaCorrida $corrida): bool
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
        $eventos = $this->eventosDeCorrida($corrida);
        $cursor = (int) $corrida->pasos_procesados;
        $pasos = $this->asegurarEstadosPlan($pasos, $cursor, $errores);

        $seleccion = $this->seleccionarSiguiente($pasos);
        if ($seleccion === null) {
            try {
                $this->persistirPlan($corrida, $pasos, $errores, (int) $corrida->pasos_fallidos, $corrida->mensaje, $eventos);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), self::MENSAJE_CANCELADA)) {
                    return false;
                }
                throw $e;
            }
            $this->finalizar($corrida);

            return false;
        }

        $indice = (int) $seleccion['indice'];
        $fase = (string) $seleccion['fase'];
        $paso = is_array($pasos[$indice] ?? null) ? $pasos[$indice] : [];
        $frase = trim((string) ($paso['frase'] ?? ''));
        $region = (int) ($paso['region'] ?? 0);
        $fallidos = $this->contarFallidosDefinitivos($pasos);
        $inicioPaso = now();
        $duracionPrevia = max(0, (int) ($pasos[$indice]['duracion_segundos'] ?? 0));
        $estadoPrevio = (string) ($paso['estado'] ?? self::PASO_PENDING);
        $esInicioRegion = $estadoPrevio !== self::PASO_RUNNING;

        $fechaBusqueda = $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda);
        $cambioDesde = isset($paso['cambio_desde']) ? trim((string) $paso['cambio_desde']) : '';
        $cambioDesde = $cambioDesde !== '' ? $cambioDesde : null;
        $regionNombre = (string) ($paso['region_nombre'] ?? CompraAgilRegionScope::nombreRegion($region));
        $maxPaginasPaso = max(1, min(20, (int) config('cotiz.mercadopublico.oportunidad_max_paginas', 8)));

        // Checkpoint: retomar la página pendiente; no reiniciar Metropolitana desde 1.
        $tamanoPagina = OportunidadParaCotizarService::REGION_TAMANO_PAGINA;
        $siguienteGuardada = (int) ($paso['siguiente_pagina'] ?? 0);
        $pagina = $esInicioRegion || $siguienteGuardada < 1
            ? 1
            : max(1, $siguienteGuardada);

        // Baseline canónico por número de página (evita acumular 50×N al reintentar la misma pág).
        $itemsLeidosPrevios = ($pagina - 1) * $tamanoPagina;
        $encontradasPrevias = $esInicioRegion ? 0 : max(0, (int) ($paso['encontradas'] ?? 0));
        // Base = frases ya comprometidas de páginas anteriores de esta región.
        $porFraseBase = $esInicioRegion
            ? []
            : (is_array($paso['encontradas_por_frase_base'] ?? null)
                ? $paso['encontradas_por_frase_base']
                : (is_array($paso['encontradas_por_frase'] ?? null) ? $paso['encontradas_por_frase'] : []));
        $codigosEncontradosBase = [];
        if (! $esInicioRegion && is_array($paso['codigos_encontrados'] ?? null)) {
            foreach ($paso['codigos_encontrados'] as $codigoPrev) {
                $norm = strtoupper(trim((string) $codigoPrev));
                if ($norm !== '') {
                    $codigosEncontradosBase[$norm] = true;
                }
            }
        }

        $pasos[$indice]['estado'] = self::PASO_RUNNING;
        $pasos[$indice]['pagina'] = $pagina;
        $pasos[$indice]['paginas_max'] = $maxPaginasPaso;
        $pasos[$indice]['siguiente_pagina'] = $pagina;
        $pasos[$indice]['items_leidos'] = $itemsLeidosPrevios;
        $pasos[$indice]['items_pagina'] = 0;
        $pasos[$indice]['encontradas'] = max($encontradasPrevias, count($codigosEncontradosBase));
        $pasos[$indice]['codigos_encontrados'] = array_keys($codigosEncontradosBase);
        $pasos[$indice]['encontradas_por_frase_base'] = $porFraseBase;
        $pasos[$indice]['encontradas_por_frase'] = $porFraseBase;
        $pasos[$indice]['encontradas_por_frase_pagina'] = [];
        $pasos[$indice]['duracion_segundos'] = $duracionPrevia;
        $pasos[$indice]['consulta'] = $this->oportunidades->consultaDebugPaso(
            $frase !== '' ? $frase : '(todas)',
            $region,
            null,
            null,
            $fechaBusqueda,
            $cambioDesde,
            $pagina,
        );

        $mensajeInicio = sprintf(
            'Consultando %s (paso %d/%d) — página %d/%d…',
            $regionNombre !== '' ? $regionNombre : ('región '.$region),
            $this->contarTerminados($pasos) + 1,
            count($pasos),
            $pagina,
            $maxPaginasPaso,
        );
        if ($esInicioRegion) {
            $this->pushEvento(
                $eventos,
                'region',
                sprintf(
                    'Worker inició %s (paso %d/%d). Esperando Mercado Público…',
                    $regionNombre !== '' ? $regionNombre : ('región '.$region),
                    $this->contarTerminados($pasos) + 1,
                    count($pasos),
                ),
            );
        }

        try {
            $this->persistirPlan($corrida, $pasos, $errores, $fallidos, $mensajeInicio, $eventos);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), self::MENSAJE_CANCELADA)) {
                return false;
            }
            throw $e;
        }
        $corrida->refresh();

        $assertCorridaActiva = function () use ($corrida): void {
            $corrida->refresh();
            if ($corrida->estado !== self::ESTADO_RUNNING) {
                throw new RuntimeException(self::MENSAJE_CANCELADA);
            }
        };

        $onProgreso = function (int $paginaActual, int $itemsPagina, int $itemsAcumulados, array $consulta) use (
            $corrida,
            &$pasos,
            &$eventos,
            $indice,
            $errores,
            $fallidos,
            $regionNombre,
            $region,
            $assertCorridaActiva,
            $inicioPaso,
            $duracionPrevia,
            $maxPaginasPaso,
            $encontradasPrevias,
            $porFraseBase,
            $codigosEncontradosBase,
        ): void {
            $assertCorridaActiva();
            $resp = is_array($consulta['respuesta'] ?? null) ? $consulta['respuesta'] : [];
            $fase = trim((string) ($consulta['fase'] ?? ''));
            if ($fase === '') {
                $fase = $itemsPagina > 0 ? 'mp_recibida' : 'esperando_mp';
            }

            $pasos[$indice]['estado'] = self::PASO_RUNNING;
            $pasos[$indice]['consulta'] = $consulta;
            $pasos[$indice]['pagina'] = max(1, $paginaActual);
            $pasos[$indice]['paginas_max'] = $maxPaginasPaso;
            $pasos[$indice]['items_pagina'] = max(0, $itemsPagina);
            $pasos[$indice]['items_leidos'] = max(0, $itemsAcumulados);
            $pasos[$indice]['duracion_segundos'] = $duracionPrevia + max(0, (int) $inicioPaso->diffInSeconds(now()));

            if ($fase === 'match') {
                $matchRevisados = max(0, (int) ($resp['match_revisados'] ?? 0));
                $matchTotal = max(0, (int) ($resp['match_total'] ?? $itemsPagina));
                $matchSegundos = max(0, (int) ($resp['match_segundos'] ?? 0));
                $encontradasPagina = max(0, (int) ($resp['encontradas_pagina'] ?? $resp['coinciden_hoy'] ?? 0));
                $encontradasMatchPagina = max(
                    $encontradasPagina,
                    (int) ($resp['encontradas_match_pagina'] ?? 0),
                );
                $porFrasePagina = is_array($resp['encontradas_por_frase'] ?? null)
                    ? $resp['encontradas_por_frase']
                    : [];
                $porFrase = $porFraseBase;
                foreach ($porFrasePagina as $fraseMatch => $nMatch) {
                    $clave = trim((string) $fraseMatch);
                    if ($clave === '') {
                        continue;
                    }
                    $porFrase[$clave] = (int) ($porFrase[$clave] ?? 0) + (int) $nMatch;
                }
                ksort($porFrase);
                $codigosRegion = $codigosEncontradosBase;
                $codigosPagina = is_array($resp['encontradas_codigos'] ?? null)
                    ? $resp['encontradas_codigos']
                    : [];
                foreach ($codigosPagina as $codigoMatch) {
                    $norm = strtoupper(trim((string) $codigoMatch));
                    if ($norm !== '') {
                        $codigosRegion[$norm] = true;
                    }
                }
                $pasos[$indice]['fase'] = 'match';
                $pasos[$indice]['match_revisados'] = $matchRevisados;
                $pasos[$indice]['match_total'] = $matchTotal;
                $pasos[$indice]['match_segundos'] = $matchSegundos;
                $pasos[$indice]['codigos_encontrados'] = array_keys($codigosRegion);
                $pasos[$indice]['encontradas'] = max(
                    $encontradasPrevias + $encontradasPagina,
                    count($codigosRegion),
                    $encontradasPrevias + $encontradasMatchPagina,
                );
                $pasos[$indice]['encontradas_por_frase_pagina'] = $porFrasePagina;
                $pasos[$indice]['encontradas_por_frase'] = $porFrase;
                $pasos[$indice]['encontradas_muestra'] = is_array($resp['encontradas_muestra'] ?? null)
                    ? array_values($resp['encontradas_muestra'])
                    : [];
                $frasesTxt = '';
                if ($porFrase !== []) {
                    $partes = [];
                    foreach ($porFrase as $fraseMatch => $nMatch) {
                        $partes[] = $fraseMatch.'×'.$nMatch;
                    }
                    $frasesTxt = ' · '.implode(', ', array_slice($partes, 0, 6));
                }
                $mensaje = sprintf(
                    'Procesando match %s — pág %d/%d · %d/%d ítems · %d cotiz.%s (%ds)…',
                    $regionNombre !== '' ? $regionNombre : ('región '.$region),
                    $paginaActual,
                    $maxPaginasPaso,
                    $matchRevisados > 0 ? $matchRevisados : $itemsPagina,
                    $matchTotal > 0 ? $matchTotal : max(1, $itemsPagina),
                    (int) $pasos[$indice]['encontradas'],
                    $frasesTxt,
                    $matchSegundos,
                );
            } else {
                $pasos[$indice]['fase'] = $fase === 'mp_recibida' ? 'match' : 'esperando_mp';
                $pasos[$indice]['match_revisados'] = 0;
                $pasos[$indice]['match_total'] = $itemsPagina > 0 ? $itemsPagina : 0;
                $pasos[$indice]['match_segundos'] = 0;
                $mensaje = sprintf(
                    'Consultando %s — página %d/%d (%d en página, %d acumulados)…',
                    $regionNombre !== '' ? $regionNombre : ('región '.$region),
                    $paginaActual,
                    $maxPaginasPaso,
                    $itemsPagina,
                    $itemsAcumulados,
                );
                $this->pushEvento(
                    $eventos,
                    'mp_pagina',
                    sprintf(
                        '%s · pág %d/%d · %d en página · %d acum. (Mercado Público)',
                        $regionNombre !== '' ? $regionNombre : ('región '.$region),
                        $paginaActual,
                        $maxPaginasPaso,
                        $itemsPagina,
                        $itemsAcumulados,
                    ),
                );
            }

            // En ticks de progreso no recontar BD (listarGuardadasEn es caro); sumar del plan.
            $this->persistirPlan($corrida, $pasos, $errores, $fallidos, $mensaje, $eventos, false);
        };

        $regionTerminada = false;
        $mensaje = $mensajeInicio;

        try {
            $assertCorridaActiva();

            // Plan nuevo: frase vacía/"(todas)" → una página de región.
            // Legado con frase concreta: una sola llamada (sin paginar).
            if ($frase === '' || $frase === '(todas)') {
                $resultado = $this->oportunidades->ejecutarPasoRegionPagina(
                    $region,
                    $pagina,
                    $itemsLeidosPrevios,
                    [],
                    null,
                    $fechaBusqueda,
                    $onProgreso,
                    $cambioDesde,
                );
            } else {
                $resultado = $this->oportunidades->ejecutarPaso(
                    $frase,
                    $region,
                    [],
                    null,
                    $fechaBusqueda,
                    $onProgreso,
                    $cambioDesde,
                );
                $resultado['continuar'] = false;
                $resultado['pagina'] = 1;
                $resultado['items_leidos'] = is_array($resultado['items'] ?? null)
                    ? count($resultado['items'])
                    : 0;
            }

            $assertCorridaActiva();

            $guardadasPagina = (int) ($resultado['guardadas'] ?? 0);
            if ($guardadasPagina <= 0 && is_array($resultado['items'] ?? null)) {
                $guardadasPagina = count($resultado['items']);
            }
            $encontradas = $encontradasPrevias + $guardadasPagina;
            $itemsLeidos = max($itemsLeidosPrevios, (int) ($resultado['items_leidos'] ?? $itemsLeidosPrevios));
            $paginaHecha = max(1, (int) ($resultado['pagina'] ?? $pagina));
            $continuarPaginas = (bool) ($resultado['continuar'] ?? false);

            $pasos[$indice]['pagina'] = $paginaHecha;
            $pasos[$indice]['paginas_max'] = $maxPaginasPaso;
            $pasos[$indice]['items_pagina'] = max(0, (int) ($resultado['items_pagina'] ?? 0));
            $pasos[$indice]['items_leidos'] = $itemsLeidos;
            $pasos[$indice]['encontradas'] = $encontradas;
            $pasos[$indice]['duracion_segundos'] = $duracionPrevia + max(0, (int) $inicioPaso->diffInSeconds(now()));
            $pasos[$indice]['consulta'] = is_array($resultado['consulta'] ?? null) ? $resultado['consulta'] : null;

            $porFrasePagina = is_array($resultado['encontradas_por_frase'] ?? null)
                ? $resultado['encontradas_por_frase']
                : (is_array($resultado['consulta']['respuesta']['encontradas_por_frase'] ?? null)
                    ? $resultado['consulta']['respuesta']['encontradas_por_frase']
                    : []);
            $porFraseTotal = $porFraseBase;
            foreach ($porFrasePagina as $fraseMatch => $nMatch) {
                $clave = trim((string) $fraseMatch);
                if ($clave === '') {
                    continue;
                }
                $porFraseTotal[$clave] = (int) ($porFraseTotal[$clave] ?? 0) + (int) $nMatch;
            }
            ksort($porFraseTotal);
            $codigosRegion = $codigosEncontradosBase;
            $codigosPagina = is_array($resultado['encontradas_codigos'] ?? null)
                ? $resultado['encontradas_codigos']
                : (is_array($resultado['consulta']['respuesta']['encontradas_codigos'] ?? null)
                    ? $resultado['consulta']['respuesta']['encontradas_codigos']
                    : []);
            foreach ($codigosPagina as $codigoMatch) {
                $norm = strtoupper(trim((string) $codigoMatch));
                if ($norm !== '') {
                    $codigosRegion[$norm] = true;
                }
            }
            // También sumar códigos nuevos de items por si no vino el listado.
            if (is_array($resultado['items'] ?? null)) {
                foreach ($resultado['items'] as $itemOk) {
                    if (! is_array($itemOk)) {
                        continue;
                    }
                    $norm = strtoupper(trim((string) ($itemOk['codigo'] ?? '')));
                    if ($norm !== '') {
                        $codigosRegion[$norm] = true;
                    }
                }
            }
            $pasos[$indice]['encontradas_por_frase_pagina'] = [];
            $pasos[$indice]['encontradas_por_frase'] = $porFraseTotal;
            $pasos[$indice]['encontradas_por_frase_base'] = $porFraseTotal;
            $pasos[$indice]['codigos_encontrados'] = array_keys($codigosRegion);
            $pasos[$indice]['encontradas'] = max($encontradas, count($codigosRegion));
            if (is_array($resultado['consulta']['respuesta'] ?? null)) {
                $pasos[$indice]['encontradas_muestra'] = is_array(
                    $resultado['consulta']['respuesta']['encontradas_muestra'] ?? null
                )
                    ? array_values($resultado['consulta']['respuesta']['encontradas_muestra'])
                    : ($pasos[$indice]['encontradas_muestra'] ?? []);
            }

            $matchHecho = max(0, (int) ($pasos[$indice]['match_segundos'] ?? 0));
            if ($matchHecho === 0 && is_array($resultado['consulta']['respuesta'] ?? null)) {
                $matchHecho = max(0, (int) ($resultado['consulta']['respuesta']['match_segundos'] ?? 0));
            }
            $pasos[$indice]['match_segundos_acum'] = max(0, (int) ($pasos[$indice]['match_segundos_acum'] ?? 0)) + $matchHecho;
            $pasos[$indice]['match_segundos'] = $matchHecho;

            if ($continuarPaginas) {
                $siguiente = $paginaHecha + 1;
                $pasos[$indice]['estado'] = self::PASO_RUNNING;
                $pasos[$indice]['siguiente_pagina'] = $siguiente;
                // Anticipar UI/debug a la próxima página (antes quedaba pegado en la consulta de pág N).
                $pasos[$indice]['pagina'] = $siguiente;
                $pasos[$indice]['fase'] = 'esperando_mp';
                $pasos[$indice]['items_pagina'] = 0;
                $pasos[$indice]['match_revisados'] = 0;
                $pasos[$indice]['match_total'] = 0;
                $pasos[$indice]['match_segundos'] = 0;
                $consultaSiguiente = $this->oportunidades->consultaDebugPaso(
                    $frase !== '' ? $frase : '(todas)',
                    $region,
                    $itemsLeidos,
                    $encontradas,
                    $fechaBusqueda,
                    $cambioDesde,
                    $siguiente,
                );
                $respHecha = is_array($resultado['consulta']['respuesta'] ?? null)
                    ? $resultado['consulta']['respuesta']
                    : null;
                if ($respHecha !== null && is_array($consultaSiguiente['respuesta'] ?? null)) {
                    $consultaSiguiente['respuesta']['pagina_anterior_ok'] = $paginaHecha;
                    $consultaSiguiente['respuesta']['items_pagina_anterior'] = (int) ($respHecha['items_pagina'] ?? 0);
                    $consultaSiguiente['respuesta']['muestra_pagina_anterior'] = is_array($respHecha['muestra'] ?? null)
                        ? array_values($respHecha['muestra'])
                        : [];
                    $consultaSiguiente['respuesta_json'] = json_encode(
                        $consultaSiguiente['respuesta'],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ) ?: '{}';
                }
                $pasos[$indice]['consulta'] = $consultaSiguiente;
                $mensaje = sprintf(
                    'Consultando %s — página %d/%d hecha (%d ítems; %d cotiz.). Siguiente página %d…',
                    $regionNombre !== '' ? $regionNombre : ('región '.$region),
                    $paginaHecha,
                    $maxPaginasPaso,
                    $itemsLeidos,
                    $encontradas,
                    $siguiente,
                );
                $this->pushEvento(
                    $eventos,
                    'mp_pagina_ok',
                    sprintf(
                        '%s · pág %d/%d OK · siguiente %d',
                        $regionNombre !== '' ? $regionNombre : ('región '.$region),
                        $paginaHecha,
                        $maxPaginasPaso,
                        $siguiente,
                    ),
                );
            } else {
                $regionTerminada = true;
                $pasos[$indice]['estado'] = self::PASO_OK;
                $pasos[$indice]['intentos'] = (int) ($pasos[$indice]['intentos'] ?? 0) + 1;
                unset($pasos[$indice]['siguiente_pagina']);
                $fallidos = $this->contarFallidosDefinitivos($pasos);
                $mensaje = $fase === 'reintento'
                    ? sprintf(
                        'Reintento OK región %d: %d cotización(es) (%d/%d pasos).',
                        $region,
                        $encontradas,
                        $this->contarTerminados($pasos),
                        count($pasos),
                    )
                    : sprintf(
                        'Paso región %d: %d cotización(es) (%d/%d).',
                        $region,
                        $encontradas,
                        $this->contarTerminados($pasos),
                        count($pasos),
                    );
                $this->pushEvento(
                    $eventos,
                    'region_ok',
                    sprintf(
                        '%s terminada: %d cotización(es).',
                        $regionNombre !== '' ? $regionNombre : ('región '.$region),
                        $encontradas,
                    ),
                );
            }
        } catch (Throwable $e) {
            $corrida->refresh();
            if ($corrida->estado === self::ESTADO_CANCELLED
                || str_contains($e->getMessage(), self::MENSAJE_CANCELADA)) {
                return false;
            }

            $tipoError = $this->clasificarErrorPaso($e);
            $mensajeError = mb_substr($e->getMessage(), 0, 500);
            $consultaError = $this->oportunidades->consultaDebugPaso(
                $frase !== '' ? $frase : '(todas)',
                $region,
                $itemsLeidosPrevios,
                null,
                $fechaBusqueda,
                $cambioDesde,
                $pagina,
                $mensajeError,
                $tipoError,
            );
            $pasos[$indice]['duracion_segundos'] = $duracionPrevia + max(0, (int) $inicioPaso->diffInSeconds(now()));
            $pasos[$indice]['consulta'] = $consultaError;
            $pasos[$indice]['pagina'] = $pagina;
            $pasos[$indice]['paginas_max'] = $maxPaginasPaso;

            $esRegionPaginada = $frase === '' || $frase === '(todas)';
            $puedeSeguirPagina = $esRegionPaginada && $pagina < $maxPaginasPaso;

            $errores[] = [
                'indice' => $indice,
                'frase' => $frase !== '' ? $frase : '(todas)',
                'region' => $region,
                'fase' => $fase,
                'intento' => (int) ($pasos[$indice]['intentos'] ?? 0) + ($puedeSeguirPagina ? 0 : 1),
                'pagina' => $pagina,
                'tipo' => $tipoError,
                'mensaje' => $mensajeError,
                'fecha' => now()->toIso8601String(),
            ];

            if ($puedeSeguirPagina) {
                // Timeout/lento/BD/HTTP en una página: no tumbar la región; seguir con la siguiente.
                $siguiente = $pagina + 1;
                $tamanoPagina = OportunidadParaCotizarService::REGION_TAMANO_PAGINA;
                $regionTerminada = false;
                $pasos[$indice]['estado'] = self::PASO_RUNNING;
                $pasos[$indice]['siguiente_pagina'] = $siguiente;
                $pasos[$indice]['pagina'] = $siguiente;
                $pasos[$indice]['fase'] = 'esperando_mp';
                $pasos[$indice]['items_pagina'] = 0;
                $pasos[$indice]['items_leidos'] = $pagina * $tamanoPagina;
                $pasos[$indice]['encontradas'] = $encontradasPrevias;
                $pasos[$indice]['match_revisados'] = 0;
                $pasos[$indice]['match_total'] = 0;
                $pasos[$indice]['match_segundos'] = 0;
                $pasos[$indice]['consulta'] = $this->oportunidades->consultaDebugPaso(
                    $frase !== '' ? $frase : '(todas)',
                    $region,
                    $pagina * $tamanoPagina,
                    $encontradasPrevias,
                    $fechaBusqueda,
                    $cambioDesde,
                    $siguiente,
                    $mensajeError,
                    $tipoError,
                );
                $mensaje = sprintf(
                    '%s — pág %d/%d con error (%s); se sigue con pág %d. %s',
                    $regionNombre !== '' ? $regionNombre : ('región '.$region),
                    $pagina,
                    $maxPaginasPaso,
                    $tipoError,
                    $siguiente,
                    mb_substr($mensajeError, 0, 180),
                );
                $this->pushEvento(
                    $eventos,
                    'mp_pagina_error',
                    sprintf(
                        '%s · pág %d/%d error (%s) → siguiente %d: %s',
                        $regionNombre !== '' ? $regionNombre : ('región '.$region),
                        $pagina,
                        $maxPaginasPaso,
                        $tipoError,
                        $siguiente,
                        mb_substr($mensajeError, 0, 160),
                    ),
                );
            } else {
                $regionTerminada = true;
                $intentos = (int) ($pasos[$indice]['intentos'] ?? 0) + 1;
                $pasos[$indice]['intentos'] = $intentos;
                $pasos[$indice]['estado'] = $intentos >= 2
                    ? self::PASO_RETRY_FAILED
                    : self::PASO_FAILED;
                unset($pasos[$indice]['siguiente_pagina']);
                $fallidos = $this->contarFallidosDefinitivos($pasos);
                $mensaje = $fase === 'reintento'
                    ? sprintf(
                        'Reintento fallido región %d; se sigue con la siguiente. %s',
                        $region,
                        mb_substr($mensajeError, 0, 200),
                    )
                    : sprintf(
                        'Paso fallido región %d (pág %d, %s); al cerrar la región se reintentará. %s',
                        $region,
                        $pagina,
                        $tipoError,
                        mb_substr($mensajeError, 0, 200),
                    );
                $this->pushEvento(
                    $eventos,
                    'region_error',
                    sprintf(
                        '%s falló (pág %d, %s): %s',
                        $regionNombre !== '' ? $regionNombre : ('región '.$region),
                        $pagina,
                        $tipoError,
                        mb_substr($mensajeError, 0, 180),
                    ),
                );
            }
        }

        $nuevoCursor = $regionTerminada ? $cursor + 1 : $cursor;
        $updatePaso = [
            'pasos_procesados' => $nuevoCursor,
            'pasos_fallidos' => $fallidos,
            'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasEn($fechaBusqueda)),
            'plan_json' => json_encode(array_values($pasos), JSON_UNESCAPED_UNICODE),
            'errores_json' => json_encode(array_slice($errores, -100), JSON_UNESCAPED_UNICODE),
            'mensaje' => $mensaje,
            'updated_at' => now(),
        ];
        if ($this->soportaEventosJson()) {
            $updatePaso['eventos_json'] = json_encode(array_values($eventos), JSON_UNESCAPED_UNICODE);
        }

        $actualizada = OportunidadBusquedaCorrida::query()
            ->whereKey($corrida->id)
            ->where('estado', self::ESTADO_RUNNING)
            ->where('pasos_procesados', $cursor)
            ->update($updatePaso);

        $corrida->refresh();
        if ($actualizada !== 1) {
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

        $fin = now();
        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        foreach ($pasos as $i => $paso) {
            if (! is_array($paso)) {
                continue;
            }
            $estadoPaso = (string) ($paso['estado'] ?? self::PASO_PENDING);
            if ($estadoPaso === self::PASO_RUNNING || $estadoPaso === self::PASO_PENDING) {
                $pasos[$i]['estado'] = self::PASO_CANCELLED;
            }
        }

        $mensaje = self::MENSAJE_CANCELADA.' Tiempo: '.$this->formatearDuracion($corrida->inicio, $fin);
        $payload = [
            'estado' => self::ESTADO_CANCELLED,
            'fin' => $fin,
            'plan_json' => array_values($pasos),
            'mensaje' => $mensaje,
        ];
        if ($this->soportaEventosJson()) {
            $eventos = $this->eventosDeCorrida($corrida);
            $this->pushEvento($eventos, 'cancelada', $mensaje);
            $payload['eventos_json'] = $eventos;
        }
        $corrida->fill($payload)->save();

        return $corrida;
    }

    /**
     * Reencola la corrida activa si el worker se detuvo o el job quedó colgado.
     * Seguro ante polls repetidos: no duplica jobs si ya hay uno en cola.
     */
    public function liberarCorridaColgadaIfNeeded(?OportunidadBusquedaCorrida $corrida = null): bool
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null || $corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $stalledSeg = max(60, (int) config('cotiz.mercadopublico.oportunidad_corrida_stalled_segundos', 90));
        if ($corrida->updated_at === null || ! $corrida->updated_at->lt(now()->subSeconds($stalledSeg))) {
            return false;
        }

        $pendientes = $this->contarJobsOportunidadPendientes($corrida->id);
        $reservados = $this->contarJobsOportunidadReservados($corrida->id);

        // Job en cola sin reservar: el worker no está corriendo. Solo avisa (touch evita spam).
        if ($pendientes > 0 && $reservados === 0) {
            $mensaje = 'Búsqueda en cola esperando worker. Verifique RUN_QUEUE_WORKER=true en Render.';
            $this->guardarMensajeYEvento(
                $corrida,
                $mensaje,
                'esperando_worker',
                'Esperando worker (job en cola sin tomar). Verifique RUN_QUEUE_WORKER=true.',
                true,
            );

            Log::warning('OportunidadBusqueda: corrida stalled con job pendiente (sin worker)', [
                'corrida_id' => $corrida->id,
                'jobs_pendientes' => $pendientes,
            ]);

            return true;
        }

        // Job reservado demasiado tiempo o sin job: liberar, saltar página colgada y reencolar.
        if ($reservados > 0) {
            $this->eliminarJobsOportunidad($corrida->id);
        }

        if ($this->jobOportunidadEncolado($corrida->id)) {
            return false;
        }

        // Si el proceso murió sin failed(), el checkpoint puede quedar en la misma página.
        $this->registrarInterrupcionWorker(
            $corrida,
            'Worker colgado sin progreso; se continúa con la siguiente página.',
        );
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return true;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $terminados = $this->contarTerminados($pasos);
        $siguiente = $terminados + 1;

        $mensaje = 'Búsqueda retomada automáticamente tras detectar worker detenido (paso '
            .$siguiente.'/'.max(1, (int) $corrida->total_pasos).').';
        $this->guardarMensajeYEvento(
            $corrida,
            $mensaje,
            'reencolada',
            'Worker detenido: job reencolado desde paso '.$siguiente.'/'.max(1, (int) $corrida->total_pasos).'.',
        );

        ProcessOportunidadBusquedaJob::dispatch($corrida->id);

        Log::warning('OportunidadBusqueda: corrida colgada reencolada', [
            'corrida_id' => $corrida->id,
            'pasos_terminados' => $terminados,
            'jobs_reservados_antes' => $reservados,
        ]);

        return true;
    }

    /**
     * Endpoint/manual: forzar reencolado de la corrida en curso (desde checkpoint).
     */
    public function reanudar(?OportunidadBusquedaCorrida $corrida = null): ?OportunidadBusquedaCorrida
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null || $corrida->estado !== self::ESTADO_RUNNING) {
            return $corrida;
        }

        if ($this->liberarCorridaColgadaIfNeeded($corrida)) {
            return $corrida->fresh() ?? $corrida;
        }

        if (! $this->jobOportunidadEncolado($corrida->id)) {
            ProcessOportunidadBusquedaJob::dispatch($corrida->id);
            $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
            $pasoTxt = ($this->contarTerminados($pasos) + 1).'/'.max(1, (int) $corrida->total_pasos);
            $this->guardarMensajeYEvento(
                $corrida,
                'Búsqueda reencolada (paso '.$pasoTxt.').',
                'reencolada',
                'Reencolada manual/automática (paso '.$pasoTxt.').',
            );
        }

        return $corrida->fresh() ?? $corrida;
    }

    /**
     * Registra evento cuando el job del worker falla a nivel Laravel (timeout, OOM, etc.).
     * Si hay más páginas en la región, avanza el checkpoint para no reintentar la misma página colgada.
     */
    public function registrarInterrupcionWorker(OportunidadBusquedaCorrida $corrida, ?string $detalle = null): void
    {
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
        $eventos = $this->eventosDeCorrida($corrida);
        $seleccion = $this->seleccionarSiguiente($pasos);
        $detalleTxt = $detalle !== null ? trim($detalle) : '';
        $tipoError = $detalleTxt !== ''
            ? $this->clasificarErrorPaso(new RuntimeException($detalleTxt))
            : 'timeout_worker';

        if ($seleccion !== null) {
            $indice = (int) $seleccion['indice'];
            $paso = is_array($pasos[$indice] ?? null) ? $pasos[$indice] : [];
            $frase = trim((string) ($paso['frase'] ?? ''));
            $region = (int) ($paso['region'] ?? 0);
            $regionNombre = (string) ($paso['region_nombre'] ?? CompraAgilRegionScope::nombreRegion($region));
            $maxPaginas = max(1, min(20, (int) ($paso['paginas_max']
                ?? config('cotiz.mercadopublico.oportunidad_max_paginas', 8))));
            $pagina = max(1, (int) ($paso['pagina'] ?? $paso['siguiente_pagina'] ?? 1));
            $esRegionPaginada = $frase === '' || $frase === '(todas)';
            $fechaBusqueda = $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda);
            $cambioDesde = isset($paso['cambio_desde']) ? trim((string) $paso['cambio_desde']) : '';
            $cambioDesde = $cambioDesde !== '' ? $cambioDesde : null;

            $pasos[$indice]['consulta'] = $this->oportunidades->consultaDebugPaso(
                $frase !== '' ? $frase : '(todas)',
                $region,
                max(0, (int) ($paso['items_leidos'] ?? 0)),
                null,
                $fechaBusqueda,
                $cambioDesde,
                $pagina,
                $detalleTxt !== '' ? $detalleTxt : 'Worker interrumpido (timeout o kill del proceso).',
                $tipoError,
            );

            $errores[] = [
                'indice' => $indice,
                'frase' => $frase !== '' ? $frase : '(todas)',
                'region' => $region,
                'fase' => (string) ($seleccion['fase'] ?? 'principal'),
                'intento' => (int) ($paso['intentos'] ?? 0),
                'pagina' => $pagina,
                'tipo' => $tipoError,
                'mensaje' => mb_substr(
                    $detalleTxt !== '' ? $detalleTxt : 'Worker interrumpido',
                    0,
                    500,
                ),
                'fecha' => now()->toIso8601String(),
            ];

            if ($esRegionPaginada && $pagina < $maxPaginas) {
                $siguiente = $pagina + 1;
                $tamanoPagina = OportunidadParaCotizarService::REGION_TAMANO_PAGINA;
                $pasos[$indice]['estado'] = self::PASO_RUNNING;
                $pasos[$indice]['siguiente_pagina'] = $siguiente;
                $pasos[$indice]['pagina'] = $siguiente;
                $pasos[$indice]['paginas_max'] = $maxPaginas;
                $pasos[$indice]['items_pagina'] = 0;
                $pasos[$indice]['items_leidos'] = $pagina * $tamanoPagina;
                $pasos[$indice]['fase'] = 'esperando_mp';
                $pasos[$indice]['match_revisados'] = 0;
                $pasos[$indice]['match_total'] = 0;
                $pasos[$indice]['match_segundos'] = 0;
                $pasos[$indice]['consulta'] = $this->oportunidades->consultaDebugPaso(
                    $frase !== '' ? $frase : '(todas)',
                    $region,
                    $pagina * $tamanoPagina,
                    max(0, (int) ($paso['encontradas'] ?? 0)),
                    $fechaBusqueda,
                    $cambioDesde,
                    $siguiente,
                    $detalleTxt !== '' ? $detalleTxt : 'Worker interrumpido (timeout o kill del proceso).',
                    $tipoError,
                );
                $texto = sprintf(
                    'Worker timeout en %s pág %d/%d; se sigue con pág %d.',
                    $regionNombre !== '' ? $regionNombre : ('región '.$region),
                    $pagina,
                    $maxPaginas,
                    $siguiente,
                );
                if ($detalleTxt !== '') {
                    $texto .= ' '.mb_substr($detalleTxt, 0, 120);
                }
                $this->pushEvento($eventos, 'mp_pagina_timeout', $texto);
                try {
                    $this->persistirPlan(
                        $corrida,
                        $pasos,
                        $errores,
                        $this->contarFallidosDefinitivos($pasos),
                        $texto,
                        $eventos,
                    );
                } catch (Throwable $persistError) {
                    Log::warning('OportunidadBusqueda: no se pudo persistir salto de página tras timeout worker', [
                        'corrida_id' => $corrida->id,
                        'message' => $persistError->getMessage(),
                    ]);
                    $this->guardarMensajeYEvento($corrida, $texto, 'mp_pagina_timeout', $texto);
                }

                return;
            }

            // Última página o paso con frase: marcar fallo de región y dejar que el job reencole el siguiente paso.
            $intentos = (int) ($pasos[$indice]['intentos'] ?? 0) + 1;
            $pasos[$indice]['intentos'] = $intentos;
            $pasos[$indice]['estado'] = $intentos >= 2 ? self::PASO_RETRY_FAILED : self::PASO_FAILED;
            unset($pasos[$indice]['siguiente_pagina']);
            $fallidos = $this->contarFallidosDefinitivos($pasos);
            $texto = sprintf(
                'Worker interrumpido en %s (pág %d); se reintentará o seguirá con otra región.',
                $regionNombre !== '' ? $regionNombre : ('región '.$region),
                $pagina,
            );
            if ($detalleTxt !== '') {
                $texto .= ' '.mb_substr($detalleTxt, 0, 120);
            }
            $this->pushEvento($eventos, 'worker_error', $texto);
            try {
                $this->persistirPlan($corrida, $pasos, $errores, $fallidos, $texto, $eventos);
            } catch (Throwable $persistError) {
                Log::warning('OportunidadBusqueda: no se pudo persistir fallo tras interrupción worker', [
                    'corrida_id' => $corrida->id,
                    'message' => $persistError->getMessage(),
                ]);
                $this->guardarMensajeYEvento($corrida, $texto, 'worker_error', $texto);
            }

            return;
        }

        $paso = ((int) $corrida->pasos_procesados) + 1;
        $texto = 'Worker interrumpido; se reintentará desde el paso '.$paso.'.';
        if ($detalleTxt !== '') {
            $texto .= ' '.mb_substr($detalleTxt, 0, 160);
        }
        $this->guardarMensajeYEvento($corrida, $texto, 'worker_error', $texto);
    }

    /**
     * @return 'base_datos'|'timeout_mp'|'http_mp'|'timeout_worker'|'otro'
     */
    private function clasificarErrorPaso(Throwable $e): string
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            return 'base_datos';
        }

        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'sqlstate')
            || str_contains($msg, 'postgres')
            || str_contains($msg, 'mysql')
            || str_contains($msg, 'connection: pgsql')
            || str_contains($msg, 'connection: mysql')
            || str_contains($msg, 'deadlock')
            || str_contains($msg, 'could not connect to the database')) {
            return 'base_datos';
        }

        if ($e instanceof ConnectionException
            || str_contains($msg, 'cURL error 28')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'operation timed out')) {
            return 'timeout_mp';
        }

        if ($e instanceof RequestException
            || str_contains($msg, 'http ')
            || str_contains($msg, '502')
            || str_contains($msg, '503')
            || str_contains($msg, '504')) {
            return 'http_mp';
        }

        if (str_contains($msg, 'maximum execution')
            || str_contains($msg, 'has been attempted too many times')
            || str_contains($msg, 'killed')
            || str_contains($msg, 'worker')) {
            return 'timeout_worker';
        }

        return 'otro';
    }

    public function jobOportunidadEncolado(int $corridaId): bool
    {
        return $this->contarJobsOportunidadPendientes($corridaId) > 0
            || $this->contarJobsOportunidadReservados($corridaId) > 0;
    }

    public function contarJobsOportunidadPendientes(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessOportunidadBusquedaJob%')
            ->whereNull('reserved_at');

        return (int) $this->filtrarJobsOportunidadPorCorrida($query, $corridaId)->count();
    }

    public function contarJobsOportunidadReservados(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessOportunidadBusquedaJob%')
            ->whereNotNull('reserved_at');

        return (int) $this->filtrarJobsOportunidadPorCorrida($query, $corridaId)->count();
    }

    public function eliminarJobsOportunidad(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')->where('payload', 'like', '%ProcessOportunidadBusquedaJob%');

        return $this->filtrarJobsOportunidadPorCorrida($query, $corridaId)->delete();
    }

    private function filtrarJobsOportunidadPorCorrida(\Illuminate\Database\Query\Builder $query, ?int $corridaId): \Illuminate\Database\Query\Builder
    {
        if ($corridaId === null) {
            return $query;
        }

        return $query->where('payload', 'like', '%i:'.$corridaId.';%');
    }

    private function corridaEstaStalled(OportunidadBusquedaCorrida $corrida): bool
    {
        if ($corrida->estado !== self::ESTADO_RUNNING || $corrida->updated_at === null) {
            return false;
        }

        $stalledSeg = max(60, (int) config('cotiz.mercadopublico.oportunidad_corrida_stalled_segundos', 90));

        return $corrida->updated_at->lt(now()->subSeconds($stalledSeg));
    }

    /**
     * Continúa el pipeline tras terminar (o omitir) la consulta de resultados MP:
     * encola la búsqueda de cotizaciones si este sitio es ANALISIS_ADMIN.
     *
     * @return array{accion: string, mensaje: string, corrida_id: int|null}
     */
    public function iniciarTrasResultados(string $usuario = 'sistema'): array
    {
        if (! $this->habilitada()) {
            return [
                'accion' => 'omitido',
                'mensaje' => 'Búsqueda automática deshabilitada en este sitio.',
                'corrida_id' => null,
            ];
        }

        $activa = $this->corridaEnCurso();
        if ($activa !== null) {
            if (! $this->jobOportunidadEncolado($activa->id)) {
                ProcessOportunidadBusquedaJob::dispatch($activa->id);
            }

            return [
                'accion' => 'en_curso',
                'mensaje' => 'Ya hay una corrida de oportunidades en curso.',
                'corrida_id' => $activa->id,
            ];
        }

        try {
            $corrida = $this->iniciar($usuario);

            return [
                'accion' => 'encolada',
                'mensaje' => 'Búsqueda de oportunidades encolada tras resultados MP.',
                'corrida_id' => $corrida->id,
            ];
        } catch (RuntimeException $e) {
            return [
                'accion' => 'omitido',
                'mensaje' => $e->getMessage(),
                'corrida_id' => null,
            ];
        }
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
            $reanudada = false;
            if ($reanudarActiva) {
                $reanudada = $this->liberarCorridaColgadaIfNeeded($activa);
                if (! $reanudada && ! $this->jobOportunidadEncolado($activa->id)) {
                    ProcessOportunidadBusquedaJob::dispatch($activa->id);
                    $reanudada = true;
                }
            }

            return [
                'accion' => $reanudada ? 'reanudada' : 'en_curso',
                'mensaje' => $reanudada
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
     * @param  array{incluir_items?: bool, retomar_vinculo?: bool}  $opciones
     * @return array<string, mixed>|null
     */
    public function estado(?OportunidadBusquedaCorrida $corrida = null, array $opciones = []): ?array
    {
        $incluirItems = (bool) ($opciones['incluir_items'] ?? false);
        $retomarVinculo = (bool) ($opciones['retomar_vinculo'] ?? false);

        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return null;
        }

        $reanudadaAuto = false;
        if ($corrida->estado === self::ESTADO_RUNNING) {
            $reanudadaAuto = $this->liberarCorridaColgadaIfNeeded($corrida);
            $corrida = $corrida->fresh() ?? $corrida;
        }

        // Recuperación solo bajo demanda (no en cada poll liviano).
        // No arranca vínculo si hay otra búsqueda running (lo bloquea VinculoService).
        if ($retomarVinculo && $corrida->estado === self::ESTADO_COMPLETED) {
            try {
                $this->vinculos->asegurarTrasBusquedaCompletada(
                    $corrida->fecha_busqueda,
                    (string) ($corrida->usuario ?? 'sistema'),
                );
            } catch (\Throwable $e) {
                Log::warning('No se pudo retomar vinculación tras búsqueda completada', [
                    'fecha_busqueda' => (string) $corrida->fecha_busqueda,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $total = max(0, (int) $corrida->total_pasos);
        $terminados = $this->contarTerminados($pasos);

        $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
        $ultimoError = $errores !== [] ? $errores[array_key_last($errores)] : null;
        $fechaBusqueda = $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda);
        $duracionSegundos = $this->duracionSegundos($corrida->inicio, $corrida->fin ?? ($corrida->estado === self::ESTADO_RUNNING ? now() : null));
        $vinculoEstado = $this->vinculos->estado();
        $pasosResumen = $this->resumirPasosCorrida($pasos, $errores, $fechaBusqueda);
        $ultimaConsulta = null;
        foreach (array_reverse($pasosResumen) as $pasoResumen) {
            if (is_array($pasoResumen['consulta'] ?? null)) {
                $ultimaConsulta = $pasoResumen['consulta'];
                break;
            }
        }

        $workerStalled = $this->corridaEstaStalled($corrida);
        $siguienteFecha = $corrida->estado === self::ESTADO_COMPLETED
            ? $this->proximaFechaPendienteDespues($fechaBusqueda)
            : null;

        $payload = [
            'id' => $corrida->id,
            'estado' => $corrida->estado,
            'usuario' => $this->etiquetaUsuarioCorrida($corrida->usuario ?? null),
            'fecha_busqueda' => $fechaBusqueda,
            'fecha_siguiente_pendiente' => $siguienteFecha,
            'inicio' => $corrida->inicio?->toIso8601String(),
            'fin' => $corrida->fin?->toIso8601String(),
            'updated_at' => $corrida->updated_at?->toIso8601String(),
            'duracion_segundos' => $duracionSegundos,
            'duracion_texto' => $duracionSegundos !== null ? $this->formatearSegundos($duracionSegundos) : null,
            'total_pasos' => $total,
            'pasos_procesados' => $terminados,
            'pasos_fallidos' => (int) $corrida->pasos_fallidos,
            'oportunidades_encontradas' => (int) $corrida->oportunidades_encontradas,
            'progreso' => $total > 0 ? min(100, (int) round(($terminados / $total) * 100)) : 0,
            'mensaje' => $corrida->mensaje,
            'errores' => $errores,
            'ultimo_error' => is_array($ultimoError) ? $ultimoError : null,
            'ultima_consulta' => $ultimaConsulta,
            'worker_stalled' => $workerStalled,
            'reanudada_auto' => $reanudadaAuto,
            'eventos' => $this->eventosParaUi($corrida),
            'pasos_resumen' => $pasosResumen,
            'vinculo' => $vinculoEstado,
            'vinculo_pendientes' => $corrida->estado === self::ESTADO_RUNNING
                ? 0
                : $this->vinculos->contarPendientesSafe($fechaBusqueda),
            'vinculo_aviso' => ($corrida->estado === self::ESTADO_RUNNING
                || ($vinculoEstado['estado'] ?? null) === OportunidadVinculoService::ESTADO_RUNNING)
                ? null
                : $this->vinculos->avisoPendientes($fechaBusqueda),
        ];

        if ($incluirItems) {
            // Listado acumulado (catch-up): vigentes desde fecha de inicio.
            $payload['items'] = $this->oportunidades->listarGuardadasVigentesDesde();
        }

        return $payload;
    }

    private function etiquetaUsuarioCorrida(mixed $usuario): string
    {
        $valor = trim((string) $usuario);
        if ($valor === '' || strcasecmp($valor, 'sistema') === 0) {
            return 'sistema';
        }

        return $valor;
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
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatearDuracion(mixed $inicio, mixed $fin): string
    {
        $segs = $this->duracionSegundos($inicio, $fin);

        return $segs === null ? '—' : $this->formatearSegundos($segs);
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

        return $s.'s';
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
                $estado === self::PASO_CANCELLED => ['cancelado', 'Cancelado'],
                $estado === self::PASO_RUNNING => ['en_curso', 'En curso'],
                default => ['pendiente', 'Pendiente'],
            };

            $encontradas = array_key_exists('encontradas', $paso)
                ? (int) $paso['encontradas']
                : null;

            $duracionSegundos = array_key_exists('duracion_segundos', $paso) && $paso['duracion_segundos'] !== null
                ? max(0, (int) $paso['duracion_segundos'])
                : null;

            $pagina = array_key_exists('pagina', $paso) && $paso['pagina'] !== null
                ? max(1, (int) $paso['pagina'])
                : null;
            $paginasMax = array_key_exists('paginas_max', $paso) && $paso['paginas_max'] !== null
                ? max(1, (int) $paso['paginas_max'])
                : null;
            $itemsLeidos = array_key_exists('items_leidos', $paso) && $paso['items_leidos'] !== null
                ? max(0, (int) $paso['items_leidos'])
                : null;
            $itemsPagina = array_key_exists('items_pagina', $paso) && $paso['items_pagina'] !== null
                ? max(0, (int) $paso['items_pagina'])
                : null;
            $fase = trim((string) ($paso['fase'] ?? ''));
            $matchRevisados = array_key_exists('match_revisados', $paso) && $paso['match_revisados'] !== null
                ? max(0, (int) $paso['match_revisados'])
                : null;
            $matchTotal = array_key_exists('match_total', $paso) && $paso['match_total'] !== null
                ? max(0, (int) $paso['match_total'])
                : null;
            $matchSegundos = array_key_exists('match_segundos', $paso) && $paso['match_segundos'] !== null
                ? max(0, (int) $paso['match_segundos'])
                : null;
            $matchSegundosAcum = array_key_exists('match_segundos_acum', $paso) && $paso['match_segundos_acum'] !== null
                ? max(0, (int) $paso['match_segundos_acum'])
                : null;
            $encontradasPorFrase = is_array($paso['encontradas_por_frase'] ?? null)
                ? $paso['encontradas_por_frase']
                : null;
            $encontradasMuestra = is_array($paso['encontradas_muestra'] ?? null)
                ? array_values($paso['encontradas_muestra'])
                : null;

            $consulta = is_array($paso['consulta'] ?? null) ? $paso['consulta'] : null;
            if ($consulta === null) {
                $cambioDesdePaso = isset($paso['cambio_desde']) ? trim((string) $paso['cambio_desde']) : '';
                $consulta = $this->oportunidades->consultaDebugPaso(
                    (string) ($paso['frase'] ?? '(todas)'),
                    (int) ($paso['region'] ?? 0),
                    null,
                    $encontradas,
                    $fechaBusqueda,
                    $cambioDesdePaso !== '' ? $cambioDesdePaso : null,
                    $pagina,
                );
            }

            $out[] = [
                'indice' => $i,
                'fecha_busqueda' => $fechaBusqueda,
                'region' => (int) ($paso['region'] ?? 0),
                'region_nombre' => (string) ($paso['region_nombre'] ?? ''),
                'frase' => (string) ($paso['frase'] ?? ''),
                'intentos' => $intentos,
                'encontradas' => $encontradas,
                'encontradas_por_frase' => $encontradasPorFrase,
                'encontradas_muestra' => $encontradasMuestra,
                'pagina' => $pagina,
                'paginas_max' => $paginasMax,
                'items_pagina' => $itemsPagina,
                'items_leidos' => $itemsLeidos,
                'fase' => $fase !== '' ? $fase : null,
                'match_revisados' => $matchRevisados,
                'match_total' => $matchTotal,
                'match_segundos' => $matchSegundos,
                'match_segundos_acum' => $matchSegundosAcum,
                'duracion_segundos' => $duracionSegundos,
                'duracion_texto' => $duracionSegundos !== null ? $this->formatearSegundos($duracionSegundos) : null,
                'consulta' => $consulta,
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
                'frase' => trim((string) ($paso['frase'] ?? '')) ?: '(todas)',
                'region' => (int) ($paso['region'] ?? 0),
                'region_nombre' => (string) ($paso['region_nombre'] ?? ''),
                'estado' => self::PASO_PENDING,
                'intentos' => 0,
                'encontradas' => null,
                'pagina' => null,
                'paginas_max' => null,
                'items_leidos' => null,
                'duracion_segundos' => null,
                'consulta' => null,
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
                $estadoPaso = (string) ($pasos[$i]['estado'] ?? '');
                if ($estadoPaso === self::PASO_PENDING || $estadoPaso === self::PASO_RUNNING) {
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
     * @param  list<array<string, mixed>>  $eventos
     */
    private function persistirPlan(
        OportunidadBusquedaCorrida $corrida,
        array $pasos,
        array $errores,
        int $fallidos,
        ?string $mensaje,
        array $eventos = [],
        bool $recontarGuardadas = true,
    ): void {
        $encontradas = $recontarGuardadas
            ? count($this->oportunidades->listarGuardadasEn($corrida->fecha_busqueda))
            : $this->sumarEncontradasPasos($pasos);

        $update = [
            'plan_json' => json_encode(array_values($pasos), JSON_UNESCAPED_UNICODE),
            'errores_json' => json_encode(array_slice($errores, -100), JSON_UNESCAPED_UNICODE),
            'pasos_fallidos' => $fallidos,
            'oportunidades_encontradas' => $encontradas,
            'mensaje' => $mensaje,
            'updated_at' => now(),
        ];
        if ($this->soportaEventosJson()) {
            $update['eventos_json'] = json_encode(array_values($eventos), JSON_UNESCAPED_UNICODE);
        }

        $actualizada = OportunidadBusquedaCorrida::query()
            ->whereKey($corrida->id)
            ->where('estado', self::ESTADO_RUNNING)
            ->update($update);

        $corrida->refresh();

        if ($actualizada !== 1) {
            throw new RuntimeException(self::MENSAJE_CANCELADA);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     */
    private function sumarEncontradasPasos(array $pasos): int
    {
        $total = 0;
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            $total += max(0, (int) ($paso['encontradas'] ?? 0));
        }

        return $total;
    }

    private function soportaEventosJson(): bool
    {
        static $cache = null;
        if ($cache === null) {
            $cache = Schema::hasColumn('oportunidad_busqueda_corridas', 'eventos_json');
        }

        return $cache;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventosDeCorrida(OportunidadBusquedaCorrida $corrida): array
    {
        if (! $this->soportaEventosJson()) {
            return [];
        }

        return is_array($corrida->eventos_json) ? array_values($corrida->eventos_json) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $eventos
     */
    private function pushEvento(array &$eventos, string $tipo, string $texto): void
    {
        $ultimo = $eventos !== [] ? $eventos[array_key_last($eventos)] : null;
        if (is_array($ultimo)
            && (string) ($ultimo['tipo'] ?? '') === $tipo
            && (string) ($ultimo['texto'] ?? '') === mb_substr($texto, 0, 300)) {
            return;
        }

        $eventos[] = [
            't' => now()->toIso8601String(),
            'tipo' => $tipo,
            'texto' => mb_substr($texto, 0, 300),
        ];

        if (count($eventos) > 80) {
            $eventos = array_values(array_slice($eventos, -80));
        }
    }

    private function guardarMensajeYEvento(
        OportunidadBusquedaCorrida $corrida,
        string $mensaje,
        string $tipo,
        string $textoEvento,
        bool $evitarSpamMismoTipo = false,
    ): void {
        $payload = ['mensaje' => $mensaje];
        if ($this->soportaEventosJson()) {
            $eventos = $this->eventosDeCorrida($corrida);
            $ultimo = $eventos !== [] ? $eventos[array_key_last($eventos)] : null;
            if (! $evitarSpamMismoTipo
                || ! is_array($ultimo)
                || (string) ($ultimo['tipo'] ?? '') !== $tipo) {
                $this->pushEvento($eventos, $tipo, $textoEvento);
            }
            $payload['eventos_json'] = $eventos;
        }
        $corrida->fill($payload)->save();
    }

    /**
     * @return list<array{t: string, tipo: string, texto: string}>
     */
    private function eventosParaUi(OportunidadBusquedaCorrida $corrida): array
    {
        $eventos = $this->eventosDeCorrida($corrida);
        if ($eventos === []) {
            return [];
        }

        $out = [];
        foreach (array_reverse($eventos) as $evento) {
            if (! is_array($evento)) {
                continue;
            }
            $texto = trim((string) ($evento['texto'] ?? ''));
            if ($texto === '') {
                continue;
            }
            $out[] = [
                't' => (string) ($evento['t'] ?? ''),
                'tipo' => (string) ($evento['tipo'] ?? 'info'),
                'texto' => $texto,
            ];
            if (count($out) >= 40) {
                break;
            }
        }

        return $out;
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

            // Día en curso ya completo: permite otra corrida incremental (nuevas pubs. del día).
            if ($dia->equalTo($hoy)) {
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
        $fin = now();
        $tiempo = $this->formatearDuracion($corrida->inicio, $fin);
        $mensaje = $fallidos > 0
            ? 'Búsqueda terminada con '.$fallidos.' paso(s) fallido(s) tras reintento por región. Tiempo: '.$tiempo
            : 'Búsqueda terminada correctamente. Tiempo: '.$tiempo;
        $payload = [
            'estado' => self::ESTADO_COMPLETED,
            'fin' => $fin,
            'pasos_fallidos' => $fallidos,
            'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasEn($corrida->fecha_busqueda)),
            'mensaje' => $mensaje,
        ];
        if ($this->soportaEventosJson()) {
            $eventos = $this->eventosDeCorrida($corrida);
            $this->pushEvento($eventos, 'completada', $mensaje);
            $payload['eventos_json'] = $eventos;
        }
        $corrida->fill($payload)->save();

        // Pipeline: tras búsqueda → vinculación. Si no hay pendientes de vincular,
        // igual corre sync al par y el catch-up del día siguiente.
        try {
            $vinculo = $this->vinculos->iniciarTrasBusqueda(
                $corrida->fecha_busqueda,
                (string) ($corrida->usuario ?? 'sistema'),
            );
            if ($vinculo === null) {
                try {
                    $this->encontradaRelay->sincronizarPipelineTrasVinculacion('búsqueda-sin-vinculo');
                } catch (\Throwable $e) {
                    Log::warning('Pipeline sync al par tras búsqueda (sin vinculación) falló', [
                        'fecha_busqueda' => (string) $corrida->fecha_busqueda,
                        'message' => $e->getMessage(),
                    ]);
                }
                $this->continuarCatchUpTrasVinculacion(
                    $corrida->fecha_busqueda,
                    (string) ($corrida->usuario ?? 'sistema'),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo encolar vinculación de oportunidades', [
                'fecha_busqueda' => (string) $corrida->fecha_busqueda,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tras vincular+sync del día D, encola la búsqueda del día D+1 si el catch-up lo requiere.
     */
    public function continuarCatchUpTrasVinculacion(mixed $fechaBusqueda, string $usuario = 'sistema'): void
    {
        if (! $this->habilitada()) {
            return;
        }

        $siguienteFecha = $this->proximaFechaPendienteDespues($fechaBusqueda);
        if ($siguienteFecha === null || $this->corridaEnCurso() !== null) {
            return;
        }

        $this->iniciar(trim($usuario) ?: 'sistema', $siguienteFecha);
    }
}
