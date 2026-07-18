<?php

namespace App\Services;

use App\Jobs\ProcessOportunidadVinculoJob;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadVinculoCorrida;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OportunidadVinculoService
{
    public const ESTADO_RUNNING = 'running';

    public const ESTADO_COMPLETED = 'completed';

    public const ESTADO_CANCELLED = 'cancelled';

    public const ESTADO_ERROR = 'error';

    private const PASO_PENDING = 'pending';

    private const PASO_RUNNING = 'running';

    private const PASO_OK = 'ok';

    private const PASO_FAILED = 'failed';

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilPayloadMapper $mapper,
        protected CompraAgilImportService $importService,
        protected OportunidadParaCotizarService $oportunidades,
        protected OportunidadEncontradaRelayService $encontradaRelay,
    ) {}

    public function corridaEnCurso(): ?OportunidadVinculoCorrida
    {
        return OportunidadVinculoCorrida::query()
            ->where('estado', self::ESTADO_RUNNING)
            ->latest('id')
            ->first();
    }

    public function ultimaCorrida(): ?OportunidadVinculoCorrida
    {
        return OportunidadVinculoCorrida::query()->latest('id')->first();
    }

    /**
     * Encola vinculaciones internas tras terminar la búsqueda del día.
     * Orden: fecha, indice_region_config (MERCADOPUBLICO_REGIONES) y código.
     */
    public function iniciarTrasBusqueda(mixed $fechaBusqueda, string $usuario = 'sistema'): ?OportunidadVinculoCorrida
    {
        if (! $this->oportunidades->apiConfigurada()) {
            return null;
        }

        $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
        $existente = $this->corridaEnCurso();
        if ($existente !== null) {
            $this->agregarPasosPendientes($existente, $dia);

            return $existente->fresh() ?? $existente;
        }

        $pasos = $this->construirPlan($dia);
        if ($pasos === []) {
            return null;
        }

        $corrida = OportunidadVinculoCorrida::query()->create([
            'usuario' => trim($usuario) ?: 'sistema',
            'fecha_busqueda' => $dia,
            'inicio' => now(),
            'estado' => self::ESTADO_RUNNING,
            'total_pasos' => count($pasos),
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'plan_json' => $pasos,
            'errores_json' => [],
            'mensaje' => 'Vinculación interna encolada ('.$this->formatearFecha($dia).').',
        ]);

        ProcessOportunidadVinculoJob::dispatch($corrida->id);

        return $corrida;
    }

    private function agregarPasosPendientes(OportunidadVinculoCorrida $corrida, string $dia): void
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
            'mensaje' => 'Se agregaron '.$agregados.' cotización(es) al plan de vinculación.',
        ])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function construirPlan(string $dia): array
    {
        $rows = OportunidadEncontrada::query()
            ->whereDate('fecha_busqueda', $dia)
            ->where(function ($query) {
                $query->where('vinculo_completo', false)
                    ->orWhereNull('vinculo_completo');
            })
            ->where(function ($query) {
                $query->whereNull('fecha_cierre')
                    ->orWhere('fecha_cierre', '>', now());
            })
            ->orderBy('indice_region_config')
            ->orderBy('codigo')
            ->get(['codigo', 'region', 'nombre_region', 'indice_region_config']);

        $pasos = [];
        foreach ($rows as $row) {
            $codigo = strtoupper(trim((string) $row->codigo));
            if ($codigo === '') {
                continue;
            }
            $pasos[] = [
                'codigo' => $codigo,
                'region' => $row->region !== null ? (int) $row->region : null,
                'region_nombre' => (string) ($row->nombre_region ?: CompraAgilRegionScope::nombreRegion((int) ($row->region ?? 0))),
                'indice_region_config' => (int) $row->indice_region_config,
                'estado' => self::PASO_PENDING,
            ];
        }

        return $pasos;
    }

    /**
     * Procesa un paso (una cotización). Retorna true si quedan más.
     */
    public function procesarPaso(OportunidadVinculoCorrida $corrida): bool
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
        $pasos[$indice]['estado'] = self::PASO_RUNNING;
        $pasos[$indice]['inicio'] = now()->toIso8601String();
        $corrida->fill([
            'plan_json' => $pasos,
            'mensaje' => 'Vinculando '.$paso['codigo'].' ('.($indice + 1).'/'.count($pasos).')…',
        ])->save();

        $inicioMs = microtime(true);
        try {
            $resultado = $this->vincularCodigo((string) $paso['codigo'], $corrida->fecha_busqueda);
            $pasos = is_array($corrida->fresh()?->plan_json) ? $corrida->fresh()->plan_json : $pasos;
            $pasos[$indice]['estado'] = self::PASO_OK;
            $pasos[$indice]['fin'] = now()->toIso8601String();
            $pasos[$indice]['duracion_ms'] = (int) round((microtime(true) - $inicioMs) * 1000);
            $pasos[$indice]['vinculados'] = $resultado['vinculados'];
            $pasos[$indice]['total'] = $resultado['total'];
            $pasos[$indice]['porcentaje'] = $resultado['porcentaje'];
        } catch (Throwable $e) {
            Log::warning('OportunidadVinculoService: fallo al vincular', [
                'codigo' => $paso['codigo'] ?? null,
                'message' => $e->getMessage(),
            ]);
            $this->marcarEncontradaSinVinculo((string) ($paso['codigo'] ?? ''), $corrida->fecha_busqueda);
            $pasos = is_array($corrida->fresh()?->plan_json) ? $corrida->fresh()->plan_json : $pasos;
            $pasos[$indice]['estado'] = self::PASO_FAILED;
            $pasos[$indice]['fin'] = now()->toIso8601String();
            $pasos[$indice]['error'] = mb_substr($e->getMessage(), 0, 240);
            $pasos[$indice]['duracion_ms'] = (int) round((microtime(true) - $inicioMs) * 1000);

            $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
            $errores[] = [
                'codigo' => $paso['codigo'] ?? null,
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
            'mensaje' => 'Vinculadas '.$terminados.'/'.count($pasos).'…',
        ])->save();

        if ($this->indiceSiguientePendiente($pasos) === null) {
            $this->finalizar($corrida->fresh() ?? $corrida);

            return false;
        }

        return true;
    }

    /**
     * @return array{total: int, vinculados: int, porcentaje: int}
     */
    public function vincularCodigo(string $codigo, mixed $fechaBusqueda = null): array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            throw new RuntimeException('Código vacío.');
        }

        $payload = $this->api->detalle($codigo);
        $parseado = $this->mapper->fromDetalle($payload);
        $preview = $this->importService->previewDesdeDatos($parseado);
        $resumen = is_array($preview['resumen'] ?? null)
            ? $preview['resumen']
            : $this->importService->resumenDesdeLineasPreview($preview['lineas'] ?? []);

        $total = (int) ($resumen['total'] ?? 0);
        $vinculados = (int) ($resumen['vinculados'] ?? 0);
        $porcentaje = $total > 0 ? (int) round(($vinculados / $total) * 100) : 0;
        $previewCache = $this->empaquetarPreviewCache($preview);

        $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereDate('fecha_busqueda', $dia)
            ->first();

        if ($row !== null) {
            $row->fill([
                'cantidad_productos' => $total > 0 ? $total : ($row->cantidad_productos ?? 0),
                'productos_vinculados' => $vinculados,
                'porcentaje_vinculo' => $porcentaje,
                'vinculo_completo' => true,
                'vinculo_at' => now(),
                'vinculo_preview_json' => $previewCache,
            ])->save();

            $this->encontradaRelay->replicarItems([
                $row->toResumen() + ['fecha_busqueda' => $dia],
            ]);
        }

        return [
            'total' => $total,
            'vinculados' => $vinculados,
            'porcentaje' => $porcentaje,
        ];
    }

    /**
     * Preview ya calculado en la vinculación masiva (para no reanalizar al cotizar).
     *
     * @return array{
     *   cabecera: array<string, mixed>,
     *   lineas: list<array<string, mixed>>,
     *   resumen: array<string, mixed>,
     *   desde_cache: bool,
     *   puede_importar: bool,
     *   error_cabecera: null
     * }|null
     */
    public function previewCacheado(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return null;
        }

        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->where('vinculo_completo', true)
            ->whereNotNull('vinculo_preview_json')
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $preview = is_array($row->vinculo_preview_json) ? $row->vinculo_preview_json : null;
        if ($preview === null || ! is_array($preview['lineas'] ?? null)) {
            return null;
        }

        $lineas = array_values($preview['lineas']);
        $resumen = is_array($preview['resumen'] ?? null)
            ? $preview['resumen']
            : $this->importService->resumenDesdeLineasPreview($lineas);

        return [
            'cabecera' => is_array($preview['cabecera'] ?? null) ? $preview['cabecera'] : [],
            'lineas' => $lineas,
            'resumen' => $resumen,
            'desde_cache' => true,
            'puede_importar' => true,
            'error_cabecera' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{cabecera: array<string, mixed>, lineas: list<array<string, mixed>>, resumen: array<string, mixed>}
     */
    private function empaquetarPreviewCache(array $preview): array
    {
        $lineas = is_array($preview['lineas'] ?? null) ? array_values($preview['lineas']) : [];
        $resumen = is_array($preview['resumen'] ?? null)
            ? $preview['resumen']
            : $this->importService->resumenDesdeLineasPreview($lineas);

        return [
            'cabecera' => is_array($preview['cabecera'] ?? null) ? $preview['cabecera'] : [],
            'lineas' => $lineas,
            'resumen' => $resumen,
        ];
    }

    private function marcarEncontradaSinVinculo(string $codigo, mixed $fechaBusqueda): void
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return;
        }

        $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereDate('fecha_busqueda', $dia)
            ->first();

        if ($row === null) {
            return;
        }

        // Completo con 0 para no reintentar eternamente; no se muestra % si total es null?
        // Usuario: mostrar solo si completo. Marcamos completo con 0/0.
        $row->fill([
            'productos_vinculados' => 0,
            'porcentaje_vinculo' => 0,
            'vinculo_completo' => true,
            'vinculo_at' => now(),
            'vinculo_preview_json' => null,
        ])->save();

        $this->encontradaRelay->replicarItems([
            $row->toResumen() + ['fecha_busqueda' => $dia],
        ]);
    }

    private function finalizar(OportunidadVinculoCorrida $corrida): void
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
                ? 'Vinculación terminada con '.$fallidos.' fallo(s). Tiempo: '.$tiempo
                : 'Vinculación terminada correctamente. Tiempo: '.$tiempo,
        ])->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function estado(?OportunidadVinculoCorrida $corrida = null): ?array
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return null;
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
            'progreso_por_region' => $this->progresoPorRegion($pasos),
            'mensaje' => $corrida->mensaje,
        ];
    }

    /**
     * Avance del 2.º proceso agrupado por código de región Mercado Público.
     *
     * @param  list<array<string, mixed>>  $pasos
     * @return array<string, array{total: int, hechos: int, porcentaje: int, region_nombre: string}>
     */
    private function progresoPorRegion(array $pasos): array
    {
        $byRegion = [];
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }

            $region = (int) ($paso['region'] ?? 0);
            $key = (string) $region;
            if (! isset($byRegion[$key])) {
                $byRegion[$key] = [
                    'total' => 0,
                    'hechos' => 0,
                    'porcentaje' => 0,
                    'region_nombre' => (string) ($paso['region_nombre'] ?? $paso['nombre_region'] ?? ''),
                ];
            }

            $byRegion[$key]['total']++;
            $estado = (string) ($paso['estado'] ?? self::PASO_PENDING);
            if (in_array($estado, [self::PASO_OK, self::PASO_FAILED], true)) {
                $byRegion[$key]['hechos']++;
            }
            $nombre = trim((string) ($paso['region_nombre'] ?? $paso['nombre_region'] ?? ''));
            if ($nombre !== '' && $byRegion[$key]['region_nombre'] === '') {
                $byRegion[$key]['region_nombre'] = $nombre;
            }
        }

        foreach ($byRegion as &$stats) {
            $stats['porcentaje'] = $stats['total'] > 0
                ? min(100, (int) round(($stats['hechos'] / $stats['total']) * 100))
                : 0;
        }
        unset($stats);

        return $byRegion;
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
