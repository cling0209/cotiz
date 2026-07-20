<?php

namespace App\Services;

use App\Jobs\ProcessOportunidadVinculoJob;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadVinculoCorrida;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    private const PASO_CANCELLED = 'cancelled';

    private const MENSAJE_CANCELADA = 'Vinculación cancelada por el usuario.';

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
        return $this->iniciarConDetalle($fechaBusqueda, $usuario)['corrida'];
    }

    /**
     * @return array{
     *   ok: bool,
     *   corrida: ?OportunidadVinculoCorrida,
     *   motivo: ?string,
     *   pendientes: int
     * }
     */
    public function iniciarConDetalle(mixed $fechaBusqueda, string $usuario = 'sistema'): array
    {
        if (! $this->oportunidades->apiConfigurada()) {
            return [
                'ok' => false,
                'corrida' => null,
                'motivo' => 'API Mercado Público no configurada (falta ticket).',
                'pendientes' => 0,
            ];
        }

        $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
        $existente = $this->corridaEnCurso();
        if ($existente !== null) {
            $this->liberarCorridaColgadaIfNeeded($existente);
            $existente = $existente->fresh() ?? $existente;
            $this->agregarPasosPendientes($existente, $dia);

            return [
                'ok' => true,
                'corrida' => $existente->fresh() ?? $existente,
                'motivo' => null,
                'pendientes' => $this->contarPendientes($dia),
            ];
        }

        $pasos = $this->construirPlan($dia);
        $pendientes = count($pasos);
        if ($pasos === []) {
            return [
                'ok' => false,
                'corrida' => null,
                'motivo' => 'No hay cotizaciones vigentes pendientes de vincular.',
                'pendientes' => 0,
            ];
        }

        try {
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
        } catch (Throwable $e) {
            Log::error('OportunidadVinculoService: no se pudo crear corrida', [
                'fecha_busqueda' => $dia,
                'message' => $e->getMessage(),
            ]);

            $motivo = 'No se pudo iniciar la vinculación: '.$e->getMessage();
            $msg = mb_strtolower($e->getMessage());
            if (str_contains($msg, 'oportunidad_vinculo')
                || str_contains($msg, 'undefined table')
                || str_contains($msg, 'does not exist')
                || str_contains($msg, 'no such table')) {
                $motivo = 'Falta la migración de vinculaciones en el servidor. Ejecute php artisan migrate.';
            }

            return [
                'ok' => false,
                'corrida' => null,
                'motivo' => $motivo,
                'pendientes' => $pendientes,
            ];
        }

        ProcessOportunidadVinculoJob::dispatch($corrida->id);

        return [
            'ok' => true,
            'corrida' => $corrida,
            'motivo' => null,
            'pendientes' => $pendientes,
        ];
    }

    /**
     * Si la búsqueda ya terminó y aún hay cotizaciones sin vincular, encola (o retoma) el 2.º proceso.
     * Sirve de recuperación cuando el encolado al finalizar falló o el plan del día quedó vacío.
     */
    public function asegurarTrasBusquedaCompletada(mixed $fechaBusqueda, string $usuario = 'sistema'): ?OportunidadVinculoCorrida
    {
        $enCurso = $this->corridaEnCurso();
        if ($enCurso !== null) {
            $this->liberarCorridaColgadaIfNeeded($enCurso);

            return $this->corridaEnCurso() ?? $enCurso->fresh() ?? $enCurso;
        }

        return $this->iniciarTrasBusqueda($fechaBusqueda, $usuario);
    }

    /**
     * Aviso para la UI cuando hay pendientes y no hay corrida de vínculo en curso.
     *
     * @return array{pendientes: int, mensaje: string, puede_iniciar: bool}|null
     */
    public function avisoPendientes(mixed $fechaBusqueda = null): ?array
    {
        if ($this->corridaEnCurso() !== null) {
            return null;
        }

        try {
            $pendientes = $this->contarPendientes($fechaBusqueda);
        } catch (Throwable $e) {
            Log::warning('OportunidadVinculoService: no se pudo contar pendientes', [
                'message' => $e->getMessage(),
            ]);

            return [
                'pendientes' => 0,
                'mensaje' => 'No se pudo consultar pendientes de vinculación (¿faltan migraciones?). '
                    .'Detalle: '.mb_substr($e->getMessage(), 0, 160),
                'puede_iniciar' => true,
            ];
        }

        if ($pendientes <= 0) {
            return null;
        }

        return [
            'pendientes' => $pendientes,
            'mensaje' => 'Hay '.$pendientes.' cotización(es) vigente(s) sin vincular al maestro. '
                .'Pulse «Iniciar vinculación» para arrancar el 2.º proceso.',
            'puede_iniciar' => true,
        ];
    }

    public function contarPendientes(mixed $fechaBusqueda = null): int
    {
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
        // Vigentes pendientes desde fecha de inicio (catch-up): sin cerrar, sin vincular, sin tomadas.
        $desde = $this->oportunidades->normalizarFechaBusqueda(
            config('cotiz.mercadopublico.fecha_inicio_busqueda', '2026-07-14'),
        );
        $hasta = $this->oportunidades->normalizarFechaBusqueda($dia);
        $codigosTomados = $this->oportunidades->codigosTomadosNormalizados();

        $rows = OportunidadEncontrada::query()
            ->whereDate('fecha_busqueda', '>=', $desde)
            ->whereDate('fecha_busqueda', '<=', $hasta)
            // Pendientes reales, o “0% falso” (cerrado sin preview: MP no respondió).
            ->where(function ($query) {
                $query->whereRaw('vinculo_completo IS NOT TRUE')
                    ->orWhere(function ($q) {
                        $q->whereRaw('vinculo_completo IS TRUE')
                            ->whereNull('vinculo_preview_json');
                    });
            })
            ->where(function ($query) {
                $query->whereNull('fecha_cierre')
                    ->orWhere('fecha_cierre', '>', now());
            })
            ->when(
                $codigosTomados !== [],
                fn ($query) => $query->whereNotIn('codigo', $codigosTomados),
            )
            ->orderBy('indice_region_config')
            ->orderBy('fecha_busqueda')
            ->orderBy('codigo')
            ->get(['codigo', 'region', 'nombre_region', 'indice_region_config', 'fecha_busqueda']);

        $pasos = [];
        $vistos = [];
        foreach ($rows as $row) {
            $codigo = strtoupper(trim((string) $row->codigo));
            if ($codigo === '' || isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;
            $pasos[] = [
                'codigo' => $codigo,
                'fecha_busqueda' => $this->oportunidades->normalizarFechaBusqueda($row->fecha_busqueda),
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
            $fechaPaso = $paso['fecha_busqueda'] ?? $corrida->fecha_busqueda;
            $resultado = $this->vincularCodigo((string) $paso['codigo'], $fechaPaso);
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
            $fechaPaso = $paso['fecha_busqueda'] ?? $corrida->fecha_busqueda;
            // Solo cierra el vínculo si aún se pudo leer MP; si MP no respondió, deja pendiente.
            $this->intentarCerrarTrasFallo((string) ($paso['codigo'] ?? ''), $fechaPaso);
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
        $cab = is_array($parseado['cabecera'] ?? null) ? $parseado['cabecera'] : [];
        $attrs = [
            'cantidad_productos' => $total > 0 ? $total : null,
            'productos_vinculados' => $vinculados,
            'porcentaje_vinculo' => $porcentaje,
            'vinculo_completo' => true,
            'vinculo_at' => now(),
            'vinculo_preview_json' => $previewCache,
        ];

        if (isset($cab['region']) && is_numeric($cab['region']) && (int) $cab['region'] > 0) {
            $attrs['region'] = (int) $cab['region'];
            $nombreRegion = trim((string) ($cab['nombre_region'] ?? ''));
            $attrs['nombre_region'] = $nombreRegion !== ''
                ? mb_substr($nombreRegion, 0, 100)
                : CompraAgilRegionScope::nombreRegion((int) $cab['region']);
        }
        if (trim((string) ($cab['comuna'] ?? '')) !== '') {
            $attrs['comuna'] = mb_substr(trim((string) $cab['comuna']), 0, 120);
        }
        if (trim((string) ($cab['direccion_entrega'] ?? '')) !== '') {
            $attrs['direccion'] = mb_substr(trim((string) $cab['direccion_entrega']), 0, 255);
        }

        $rows = $this->encontrarFilasParaCodigo($codigo, $fechaBusqueda);
        foreach ($rows as $row) {
            $fill = $attrs;
            if ($fill['cantidad_productos'] === null) {
                $fill['cantidad_productos'] = $row->cantidad_productos ?? 0;
            }
            $row->fill($fill)->save();

            $this->encontradaRelay->replicarItems([
                $row->toResumenConPreviewVinculo() + [
                    'fecha_busqueda' => $this->oportunidades->normalizarFechaBusqueda($row->fecha_busqueda),
                ],
            ], OportunidadEncontradaRelayService::ACCION_VINCULO);
        }

        return [
            'total' => $total,
            'vinculados' => $vinculados,
            'porcentaje' => $porcentaje,
        ];
    }

    /**
     * Localiza la(s) fila(s) de oportunidad_encontradas a actualizar.
     * Preferir fecha_busqueda del paso; si no hay match, cualquier fila del código (días anteriores del catch-up).
     *
     * @return \Illuminate\Support\Collection<int, OportunidadEncontrada>
     */
    private function encontrarFilasParaCodigo(string $codigo, mixed $fechaBusqueda = null): \Illuminate\Support\Collection
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return collect();
        }

        if ($fechaBusqueda !== null && trim((string) $fechaBusqueda) !== '') {
            $dia = $this->oportunidades->normalizarFechaBusqueda($fechaBusqueda);
            $porDia = OportunidadEncontrada::query()
                ->where('codigo', $codigo)
                ->whereDate('fecha_busqueda', $dia)
                ->get();
            if ($porDia->isNotEmpty()) {
                return $porDia;
            }
        }

        // Fallback: todas las del código aún sin vincular; si ninguna, la más reciente.
        $pendientes = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereRaw('vinculo_completo IS NOT TRUE')
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->get();
        if ($pendientes->isNotEmpty()) {
            return $pendientes;
        }

        $ultima = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        return $ultima !== null ? collect([$ultima]) : collect();
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
            ->whereRaw('vinculo_completo IS TRUE')
            ->whereNotNull('vinculo_preview_json')
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->armarRespuestaPreview($row->vinculo_preview_json, true);
    }

    /**
     * Preview guardado de una oportunidad (consulta en listado), aunque no esté 100% vinculada.
     *
     * @return array{
     *   cabecera: array<string, mixed>,
     *   lineas: list<array<string, mixed>>,
     *   resumen: array<string, mixed>,
     *   desde_cache: bool,
     *   puede_importar: bool,
     *   error_cabecera: null,
     *   codigo: string,
     *   porcentaje_vinculo: int|null,
     *   productos_vinculados: int|null,
     *   cantidad_productos: int|null
     * }|null
     */
    public function previewGuardado(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return null;
        }

        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereNotNull('vinculo_preview_json')
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $base = $this->armarRespuestaPreview($row->vinculo_preview_json, true);
        if ($base === null) {
            return null;
        }

        return $base + [
            'codigo' => $codigo,
            'porcentaje_vinculo' => $row->porcentaje_vinculo !== null ? (int) $row->porcentaje_vinculo : null,
            'productos_vinculados' => $row->productos_vinculados !== null ? (int) $row->productos_vinculados : null,
            'cantidad_productos' => $row->cantidad_productos !== null ? (int) $row->cantidad_productos : null,
        ];
    }

    /**
     * Detalle de productos para el modal: cache de vinculación o, si falta, consulta viva a MP.
     * Así se listan los productos de Mercado Público aunque aún no haya match interno (0% vinculado).
     *
     * @return array{
     *   cabecera: array<string, mixed>,
     *   lineas: list<array<string, mixed>>,
     *   resumen: array<string, mixed>,
     *   desde_cache: bool,
     *   puede_importar: bool,
     *   error_cabecera: null,
     *   codigo: string,
     *   porcentaje_vinculo: int|null,
     *   productos_vinculados: int|null,
     *   cantidad_productos: int|null
     * }|null
     */
    public function previewParaDetalle(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return null;
        }

        $guardado = $this->previewGuardado($codigo);
        if ($guardado !== null) {
            return $guardado;
        }

        if (! $this->oportunidades->apiConfigurada()) {
            return null;
        }

        try {
            $payload = $this->api->detalle($codigo);
            $parseado = $this->mapper->fromDetalle($payload);
            $preview = $this->importService->previewDesdeDatos($parseado);
        } catch (Throwable $e) {
            Log::warning('OportunidadVinculo: no se pudo obtener detalle MP para modal', [
                'codigo' => $codigo,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $base = $this->armarRespuestaPreview($this->empaquetarPreviewCache($preview), false);
        if ($base === null) {
            return null;
        }

        $resumen = is_array($base['resumen'] ?? null) ? $base['resumen'] : [];
        $total = (int) ($resumen['total'] ?? count($base['lineas']));
        $vinculados = (int) ($resumen['vinculados'] ?? 0);
        $porcentaje = $total > 0 ? (int) round(($vinculados / $total) * 100) : 0;

        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        return $base + [
            'codigo' => $codigo,
            'porcentaje_vinculo' => $row?->porcentaje_vinculo !== null
                ? (int) $row->porcentaje_vinculo
                : $porcentaje,
            'productos_vinculados' => $row?->productos_vinculados !== null
                ? (int) $row->productos_vinculados
                : $vinculados,
            'cantidad_productos' => $row?->cantidad_productos !== null
                ? (int) $row->cantidad_productos
                : ($total > 0 ? $total : null),
        ];
    }

    /**
     * @param  mixed  $previewRaw
     * @return array{
     *   cabecera: array<string, mixed>,
     *   lineas: list<array<string, mixed>>,
     *   resumen: array<string, mixed>,
     *   desde_cache: bool,
     *   puede_importar: bool,
     *   error_cabecera: null
     * }|null
     */
    private function armarRespuestaPreview(mixed $previewRaw, bool $puedeImportar): ?array
    {
        $preview = is_array($previewRaw) ? $previewRaw : null;
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
            'puede_importar' => $puedeImportar,
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

    /**
     * Tras un fallo de vinculación: si MP responde, guarda preview (puede ser 0% real)
     * y marca procesado. Si MP no responde, no marca 0% ni vinculo_completo: queda pendiente.
     */
    private function intentarCerrarTrasFallo(string $codigo, mixed $fechaBusqueda): void
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return;
        }

        if (! $this->oportunidades->apiConfigurada()) {
            Log::warning('OportunidadVinculo: fallo sin cerrar (API MP no configurada); queda pendiente', [
                'codigo' => $codigo,
            ]);

            return;
        }

        try {
            $payload = $this->api->detalle($codigo);
            $parseado = $this->mapper->fromDetalle($payload);
            $preview = $this->importService->previewDesdeDatos($parseado);
        } catch (Throwable $e) {
            // No inventar 0%: el próximo proceso de vinculación la reintentará.
            Log::warning('OportunidadVinculo: MP no respondió tras fallo; se deja pendiente de vincular', [
                'codigo' => $codigo,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $previewCache = $this->empaquetarPreviewCache($preview);
        $resumen = is_array($previewCache['resumen'] ?? null) ? $previewCache['resumen'] : [];
        $total = (int) ($resumen['total'] ?? count($previewCache['lineas'] ?? []));
        $vinculados = (int) ($resumen['vinculados'] ?? 0);
        $porcentaje = $total > 0 ? (int) round(($vinculados / $total) * 100) : 0;
        $cab = is_array($parseado['cabecera'] ?? null) ? $parseado['cabecera'] : [];

        $rows = $this->encontrarFilasParaCodigo($codigo, $fechaBusqueda);
        foreach ($rows as $row) {
            try {
                $fill = [
                    'cantidad_productos' => $total > 0 ? $total : ($row->cantidad_productos ?? null),
                    'productos_vinculados' => $vinculados,
                    'porcentaje_vinculo' => $porcentaje,
                    'vinculo_completo' => true,
                    'vinculo_at' => now(),
                    'vinculo_preview_json' => $previewCache,
                ];
                if (isset($cab['region']) && is_numeric($cab['region']) && (int) $cab['region'] > 0) {
                    $fill['region'] = (int) $cab['region'];
                    $nombreRegion = trim((string) ($cab['nombre_region'] ?? ''));
                    $fill['nombre_region'] = $nombreRegion !== ''
                        ? mb_substr($nombreRegion, 0, 100)
                        : CompraAgilRegionScope::nombreRegion((int) $cab['region']);
                }
                $row->fill($fill)->save();
            } catch (Throwable $e) {
                Log::warning('OportunidadVinculo: no se pudo guardar preview tras fallo', [
                    'codigo' => $codigo,
                    'id' => $row->id,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $this->encontradaRelay->replicarItems([
                $row->toResumenConPreviewVinculo() + [
                    'fecha_busqueda' => $this->oportunidades->normalizarFechaBusqueda($row->fecha_busqueda),
                ],
            ], OportunidadEncontradaRelayService::ACCION_VINCULO);
        }
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

        // Despierta el par y reenvía vinculaciones que fallaron al sync en vivo.
        try {
            $this->encontradaRelay->sincronizarPendientesTrasProceso('vinculación');
        } catch (Throwable $e) {
            Log::warning('Sync oportunidades al par tras vinculación falló', [
                'corrida_id' => $corrida->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancela la corrida de vinculación en curso para poder iniciar otra.
     * Los pasos ya ok/failed se conservan; pending/running quedan cancelled (siguen pendientes de vincular).
     */
    public function cancelar(?OportunidadVinculoCorrida $corrida = null): ?OportunidadVinculoCorrida
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null) {
            return null;
        }

        $this->eliminarJobsVinculo($corrida->id);

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

    /**
     * Reencola la corrida de vinculación si el worker se detuvo o el job quedó colgado.
     * Si hay un paso en "running" demasiado tiempo, lo marca fallido y sigue con el siguiente.
     */
    public function liberarCorridaColgadaIfNeeded(?OportunidadVinculoCorrida $corrida = null): bool
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null || $corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $stalledSeg = max(60, (int) config('cotiz.mercadopublico.oportunidad_corrida_stalled_segundos', 90));
        if ($corrida->updated_at === null || ! $corrida->updated_at->lt(now()->subSeconds($stalledSeg))) {
            return false;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
        $fallidosExtra = 0;
        [$pasos, $fallidosExtra, $errores] = $this->marcarPasosRunningColgados($pasos, $errores, $stalledSeg, $corrida);

        $pendientes = $this->contarJobsVinculoPendientes($corrida->id);
        $reservados = $this->contarJobsVinculoReservados($corrida->id);

        if ($pendientes > 0 && $reservados === 0) {
            $terminadosCola = $this->contarTerminados($pasos);
            $corrida->fill([
                'plan_json' => $pasos,
                'errores_json' => $errores,
                'pasos_procesados' => $terminadosCola,
                'pasos_fallidos' => (int) $corrida->pasos_fallidos + $fallidosExtra,
                'mensaje' => 'Vinculación en cola esperando worker. Verifique RUN_QUEUE_WORKER=true en Render.',
            ])->save();

            Log::warning('OportunidadVinculo: corrida stalled con job pendiente (sin worker)', [
                'corrida_id' => $corrida->id,
                'jobs_pendientes' => $pendientes,
            ]);

            if ($this->indiceSiguientePendiente($pasos) === null) {
                $this->finalizar($corrida->fresh() ?? $corrida);
            }

            return true;
        }

        if ($reservados > 0) {
            $this->eliminarJobsVinculo($corrida->id);
        }

        if ($this->jobVinculoEncolado($corrida->id)) {
            if ($fallidosExtra > 0) {
                $corrida->fill([
                    'plan_json' => $pasos,
                    'errores_json' => $errores,
                    'pasos_procesados' => $this->contarTerminados($pasos),
                    'pasos_fallidos' => (int) $corrida->pasos_fallidos + $fallidosExtra,
                ])->save();
            }

            return false;
        }

        $terminados = $this->contarTerminados($pasos);
        $siguiente = $terminados + 1;
        $mensaje = 'Vinculación retomada automáticamente tras detectar worker detenido (paso '
            .$siguiente.'/'.max(1, (int) $corrida->total_pasos).').';

        $corrida->fill([
            'plan_json' => $pasos,
            'errores_json' => $errores,
            'pasos_procesados' => $terminados,
            'pasos_fallidos' => (int) $corrida->pasos_fallidos + $fallidosExtra,
            'mensaje' => $mensaje,
        ])->save();

        if ($this->indiceSiguientePendiente($pasos) === null) {
            $this->finalizar($corrida->fresh() ?? $corrida);

            return true;
        }

        ProcessOportunidadVinculoJob::dispatch($corrida->id);

        Log::warning('OportunidadVinculo: corrida colgada reencolada', [
            'corrida_id' => $corrida->id,
            'pasos_terminados' => $terminados,
            'pasos_marcados_fallidos' => $fallidosExtra,
            'jobs_reservados_antes' => $reservados,
        ]);

        return true;
    }

    public function jobVinculoEncolado(int $corridaId): bool
    {
        return $this->contarJobsVinculoPendientes($corridaId) > 0
            || $this->contarJobsVinculoReservados($corridaId) > 0;
    }

    public function contarJobsVinculoPendientes(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessOportunidadVinculoJob%')
            ->whereNull('reserved_at');

        return (int) $this->filtrarJobsVinculoPorCorrida($query, $corridaId)->count();
    }

    public function contarJobsVinculoReservados(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')
            ->where('payload', 'like', '%ProcessOportunidadVinculoJob%')
            ->whereNotNull('reserved_at');

        return (int) $this->filtrarJobsVinculoPorCorrida($query, $corridaId)->count();
    }

    public function eliminarJobsVinculo(?int $corridaId = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs')->where('payload', 'like', '%ProcessOportunidadVinculoJob%');

        return $this->filtrarJobsVinculoPorCorrida($query, $corridaId)->delete();
    }

    private function filtrarJobsVinculoPorCorrida(\Illuminate\Database\Query\Builder $query, ?int $corridaId): \Illuminate\Database\Query\Builder
    {
        if ($corridaId === null) {
            return $query;
        }

        return $query->where('payload', 'like', '%i:'.$corridaId.';%');
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     * @param  list<array<string, mixed>>  $errores
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array<string, mixed>>}
     */
    private function marcarPasosRunningColgados(
        array $pasos,
        array $errores,
        int $stalledSeg,
        OportunidadVinculoCorrida $corrida,
    ): array {
        $fallidos = 0;
        foreach ($pasos as $i => $paso) {
            if (! is_array($paso) || ($paso['estado'] ?? '') !== self::PASO_RUNNING) {
                continue;
            }

            $inicioRaw = trim((string) ($paso['inicio'] ?? ''));
            $colgado = true;
            if ($inicioRaw !== '') {
                try {
                    $colgado = Carbon::parse($inicioRaw)->lt(now()->subSeconds($stalledSeg));
                } catch (Throwable) {
                    $colgado = true;
                }
            }

            if (! $colgado) {
                // Worker murió entre pasos: devolver a pending para reintentar.
                $pasos[$i]['estado'] = self::PASO_PENDING;
                unset($pasos[$i]['inicio']);

                continue;
            }

            $codigo = (string) ($paso['codigo'] ?? '');
            $fechaPaso = $paso['fecha_busqueda'] ?? $corrida->fecha_busqueda;
            $this->intentarCerrarTrasFallo($codigo, $fechaPaso);

            $pasos[$i]['estado'] = self::PASO_FAILED;
            $pasos[$i]['fin'] = now()->toIso8601String();
            $pasos[$i]['error'] = 'Paso colgado (sin avance); se marcó fallido para continuar.';
            $errores[] = [
                'codigo' => $codigo !== '' ? $codigo : null,
                'error' => 'Paso colgado: worker sin avance.',
                'at' => now()->toIso8601String(),
            ];
            $fallidos++;
        }

        return [$pasos, $fallidos, $errores];
    }

    private function corridaEstaStalled(OportunidadVinculoCorrida $corrida): bool
    {
        if ($corrida->estado !== self::ESTADO_RUNNING || $corrida->updated_at === null) {
            return false;
        }

        $stalledSeg = max(60, (int) config('cotiz.mercadopublico.oportunidad_corrida_stalled_segundos', 90));

        return $corrida->updated_at->lt(now()->subSeconds($stalledSeg));
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

        $reanudadaAuto = false;
        if ($corrida->estado === self::ESTADO_RUNNING) {
            try {
                $reanudadaAuto = $this->liberarCorridaColgadaIfNeeded($corrida);
                $corrida = $corrida->fresh() ?? $corrida;
            } catch (Throwable $e) {
                Log::warning('OportunidadVinculo: no se pudo liberar corrida colgada', [
                    'corrida_id' => $corrida->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $total = max(0, (int) $corrida->total_pasos);
        $terminados = $this->contarTerminados($pasos);
        $progresoPorRegion = $this->progresoPorRegion($pasos);
        $duracionSegundos = $this->duracionSegundos(
            $corrida->inicio,
            $corrida->fin ?? ($corrida->estado === self::ESTADO_RUNNING ? now() : null),
        );
        $ultimaActividad = $this->ultimaActividadIso($pasos, $corrida);
        $ultimaHace = null;
        if ($ultimaActividad !== null) {
            try {
                $ultimaHace = max(0, (int) Carbon::parse($ultimaActividad)->diffInSeconds(now()));
            } catch (Throwable) {
                $ultimaHace = null;
            }
        }

        $workerStalled = $this->corridaEstaStalled($corrida);

        return [
            'id' => $corrida->id,
            'estado' => $corrida->estado,
            'usuario' => trim((string) ($corrida->usuario ?? 'sistema')) ?: 'sistema',
            'fecha_busqueda' => $this->oportunidades->normalizarFechaBusqueda($corrida->fecha_busqueda),
            'inicio' => $corrida->inicio?->toIso8601String(),
            'fin' => $corrida->fin?->toIso8601String(),
            'duracion_segundos' => $duracionSegundos,
            'duracion_texto' => $duracionSegundos !== null ? $this->formatearSegundos($duracionSegundos) : null,
            'ultima_actividad' => $ultimaActividad,
            'ultima_actividad_hace_segundos' => $ultimaHace,
            'total_pasos' => $total,
            'pasos_procesados' => $terminados,
            'pasos_fallidos' => (int) $corrida->pasos_fallidos,
            'progreso' => $total > 0 ? min(100, (int) round(($terminados / $total) * 100)) : 0,
            // Lista 0..n: conserva el orden de MERCADOPUBLICO_REGIONES (JSON no reordena).
            'progreso_regiones' => array_values($progresoPorRegion),
            'progreso_por_region' => $progresoPorRegion,
            'mensaje' => $corrida->mensaje,
            'worker_stalled' => $workerStalled,
            'reanudada_auto' => $reanudadaAuto,
        ];
    }

    /**
     * ISO de la última actividad del plan (fin de paso, inicio de paso en curso o updated_at).
     *
     * @param  list<array<string, mixed>>  $pasos
     */
    private function ultimaActividadIso(array $pasos, OportunidadVinculoCorrida $corrida): ?string
    {
        $mejor = null;
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            foreach (['fin', 'inicio'] as $campo) {
                $raw = trim((string) ($paso[$campo] ?? ''));
                if ($raw === '') {
                    continue;
                }
                try {
                    $ts = Carbon::parse($raw);
                } catch (Throwable) {
                    continue;
                }
                if ($mejor === null || $ts->gt($mejor)) {
                    $mejor = $ts;
                }
            }
        }

        if ($mejor === null && $corrida->updated_at !== null) {
            $mejor = $corrida->updated_at;
        }
        if ($mejor === null && $corrida->inicio !== null) {
            $mejor = $corrida->inicio;
        }

        return $mejor?->toIso8601String();
    }

    /**
     * Avance del 2.º proceso agrupado por código de región Mercado Público.
     * El orden de las claves sigue MERCADOPUBLICO_REGIONES (indice_region_config).
     *
     * @param  list<array<string, mixed>>  $pasos
     * @return array<string, array{
     *     region: int,
     *     region_nombre: string,
     *     indice_region_config: int,
     *     total: int,
     *     hechos: int,
     *     porcentaje: int,
     *     productos_vinculados: int,
     *     productos_total: int,
     *     porcentaje_vinculados: int,
     *     ok: int,
     *     failed: int
     * }>
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
            // Siempre el orden actual de MERCADOPUBLICO_REGIONES (no el índice guardado en el plan).
            $indice = CompraAgilRegionScope::indiceEnConfig($region > 0 ? $region : null);

            if (! isset($byRegion[$key])) {
                $byRegion[$key] = [
                    'region' => $region,
                    'region_nombre' => (string) ($paso['region_nombre'] ?? $paso['nombre_region'] ?? ''),
                    'indice_region_config' => $indice,
                    'total' => 0,
                    'hechos' => 0,
                    'porcentaje' => 0,
                    'productos_vinculados' => 0,
                    'productos_total' => 0,
                    'porcentaje_vinculados' => 0,
                    'ok' => 0,
                    'failed' => 0,
                ];
            }

            $byRegion[$key]['total']++;
            $estado = (string) ($paso['estado'] ?? self::PASO_PENDING);
            if (in_array($estado, [self::PASO_OK, self::PASO_FAILED], true)) {
                $byRegion[$key]['hechos']++;
            }
            if ($estado === self::PASO_OK) {
                $byRegion[$key]['ok']++;
                $byRegion[$key]['productos_vinculados'] += max(0, (int) ($paso['vinculados'] ?? 0));
                $byRegion[$key]['productos_total'] += max(0, (int) ($paso['total'] ?? 0));
            } elseif ($estado === self::PASO_FAILED) {
                $byRegion[$key]['failed']++;
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
            $stats['porcentaje_vinculados'] = $stats['productos_total'] > 0
                ? min(100, (int) round(($stats['productos_vinculados'] / $stats['productos_total']) * 100))
                : 0;
            if ($stats['region_nombre'] === '') {
                $stats['region_nombre'] = 'Región '.$stats['region'];
            }
        }
        unset($stats);

        uasort($byRegion, static function (array $a, array $b): int {
            $cmp = ((int) ($a['indice_region_config'] ?? 999)) <=> ((int) ($b['indice_region_config'] ?? 999));
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($a['region'] ?? 0)) <=> ((int) ($b['region'] ?? 0));
        });

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
