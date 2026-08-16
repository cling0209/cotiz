<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\CompraAgilImportService;
use App\Services\CompraAgilOportunidadService;
use App\Services\MaterialesExcelImportService;
use App\Services\MaterialesImportLockService;
use App\Services\MaterialesPdfImportService;
use App\Services\NotaDetalleService;
use App\Services\NotaService;
use App\Services\OrganismoObservacionService;
use App\Services\OportunidadParaCotizarService;
use App\Services\OportunidadVinculoService;
use App\Support\CotizacionListadoRetorno;
use RuntimeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class CotizacionController extends Controller
{
    private bool $visitaOportunidadRegistrada = false;

    public function __construct(
        protected NotaService $notaService,
        protected NotaDetalleService $detalleService,
        protected CompraAgilImportService $compraAgilImport,
        protected CompraAgilOportunidadService $compraAgilOportunidad,
        protected MaterialesPdfImportService $materialesPdfImport,
        protected MaterialesExcelImportService $materialesExcelImport,
        protected OportunidadParaCotizarService $oportunidadParaCotizar,
        protected OportunidadVinculoService $oportunidadVinculo,
        protected OrganismoObservacionService $organismoObservacion,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $usuario = $request->user()->username;
        $codigo = strtoupper(trim((string) $request->query('codigo', '')));
        $interna = $request->boolean('es_interna');

        if ($request->isMethod('POST') && ! $request->filled('from') && $request->hasSession()) {
            $request->session()->forget(CotizacionListadoRetorno::SESSION_KEY);
        }

        // Contar visita apenas llega desde Oportunidades (antes de posibles redirects).
        $this->registrarVisitaOportunidadSiCorresponde($request, $codigo);

        if (! $interna && ($pendiente = $this->notaService->pendienteSinNumeroCotizacion($usuario))) {
            $this->olvidarNotaMaterializada();
            $params = $this->paramsRutaEdit($request, $pendiente->nronota);
            if ($codigo !== '') {
                $params['codigo'] = $codigo;
            }

            return redirect()
                ->route('admin.cotizaciones.edit', $params)
                ->with('error', 'Complete el número de cotización de la nota #'.$pendiente->nronota.' antes de crear otra.');
        }

        if ($vacia = $this->notaService->ultimaSinProductos($usuario, $interna)) {
            $this->olvidarNotaMaterializada();
            $params = $this->paramsRutaEdit($request, $vacia->nronota);
            if ($codigo !== '') {
                $params['codigo'] = $codigo;
            }

            return redirect()
                ->route('admin.cotizaciones.edit', $params)
                ->with('info', 'La nota #'.$vacia->nronota.' no tiene productos. Complétela antes de crear otra.');
        }

        // Borrador: no reserva nronota hasta importar productos o grabar.
        $this->olvidarNotaMaterializada();

        return $this->vistaFormulario(
            $request,
            $this->notaService->borrador($usuario, $interna),
            $codigo,
            $interna
                ? 'Cotización interna: el código se asigna al grabar (CM- + número de nota) y no se consulta en Mercado Público.'
                : ($codigo !== ''
                    ? 'Importando Compra Ágil '.$codigo.'…'
                    : 'Importe desde Compra Ágil para comenzar. El número de nota se genera al importar o al grabar.'),
        );
    }

    public function retomar(Request $request): RedirectResponse
    {
        $nota = $this->notaService->obtenerUltima($request->user()->username);

        if (! $nota) {
            return redirect()->route('admin.cotizaciones.create')
                ->with('error', 'No hay cotizaciones anteriores. Se abrirá una nueva.');
        }

        return redirect()->route('admin.cotizaciones.edit', $nota->nronota);
    }

    public function edit(Request $request, int $nronota): View|RedirectResponse
    {
        if ($nronota === 0) {
            return $this->create($request);
        }

        // Sin eager load de detalle/producto: lineasDeNota() ya los resuelve en lote.
        $nota = Nota::query()->find($nronota);

        if (! $nota) {
            return $this->notaNoEncontrada($nronota);
        }

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $codigoImportar = strtoupper(trim((string) $request->query('codigo', '')));

        return $this->vistaFormulario($request, $nota, $codigoImportar);
    }

    /**
     * @return View
     */
    private function vistaFormulario(
        Request $request,
        Nota $nota,
        string $codigoImportar = '',
        ?string $flashInfo = null,
    ): View {
        if ($flashInfo !== null) {
            session()->flash('info', $flashInfo);
        }

        $this->registrarVisitaOportunidadSiCorresponde($request, $codigoImportar);

        CotizacionListadoRetorno::syncSession($request);

        $esBorrador = $this->notaService->esBorrador($nota);
        $lineas = $esBorrador
            ? collect()
            : $this->detalleService->lineasDeNota($nota);
        $hayPrecioAntiguo = $lineas->contains(fn ($row) => $row['prod_valor_fecha_antigua']);
        $previewImportarCompraAgil = $this->previewVinculoCacheadoParaFormulario($nota, $codigoImportar);

        $esInterna = $nota->esCotizacionInterna();
        $requiereNumero = $esInterna ? false : $nota->requiereNumeroCotizacion();
        $cotizacionListadoQuery = CotizacionListadoRetorno::query($request);
        $desdeAdjudicadas = ($cotizacionListadoQuery['from'] ?? '') === CotizacionListadoRetorno::FROM_ADJUDICADAS;
        $desdeOportunidades = ($cotizacionListadoQuery['from'] ?? '') === CotizacionListadoRetorno::FROM_OPORTUNIDADES
            && $codigoImportar !== '';

        return view('admin.cotizaciones.form', [
            'nota' => $nota,
            'esBorrador' => $esBorrador,
            'esInterna' => $esInterna,
            'lineas' => $lineas,
            'total' => $lineas->sum(fn ($row) => $row['total']),
            'resumenLineas' => $esBorrador
                ? ['total' => 0, 'con_agile' => 0, 'sin_agile' => 0]
                : $this->detalleService->resumenDesdeColeccionLineas($lineas),
            'hayPrecioAntiguo' => $hayPrecioAntiguo,
            'umbralPrecioMeses' => config('cotiz.prod_valor_fecha_meses'),
            'requiereNumeroCotizacion' => $requiereNumero,
            'abrirImportarAlInicio' => ! $desdeAdjudicadas
                && $requiereNumero
                && $lineas->isEmpty(),
            'codigoImportarCompraAgil' => $codigoImportar,
            'previewImportarCompraAgil' => $previewImportarCompraAgil,
            'desdeAdjudicadas' => $desdeAdjudicadas,
            'desdeOportunidades' => $desdeOportunidades,
            'oportunidadYaVinculada' => $previewImportarCompraAgil !== null,
            'cotizacionListadoUrl' => CotizacionListadoRetorno::url($request),
            'cotizacionListadoLabel' => CotizacionListadoRetorno::label($request),
            'cotizacionListadoQuery' => $cotizacionListadoQuery,
            'mostrarSoftland' => $request->user()->isSuperAdmin(),
            'observacionesOrganismo' => $this->organismoObservacion->observacionesParaRut($nota->rutempresa),
        ]);
    }

    /**
     * Si la oportunidad ya fue vinculada en segundo plano, reutiliza ese preview.
     *
     * @return array<string, mixed>|null
     */
    private function previewVinculoCacheadoParaFormulario(Nota $nota, string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return null;
        }

        $preview = $this->oportunidadVinculo->previewCacheado($codigo);
        if ($preview === null) {
            return null;
        }

        $errorLocal = $this->notaService->validarNumeroCotizacion($nota, $codigo);
        if ($errorLocal !== null) {
            $preview['error_cabecera'] = $errorLocal;
            $preview['puede_importar'] = false;
        }

        return $preview;
    }

    /**
     * Resuelve la nota: existente, borrador en memoria, o la materializa al grabar/importar.
     *
     * @return array{0: Nota|null, 1: bool} [nota, recienCreada]
     */
    private function resolverNota(Request $request, int $nronota, bool $persistir = false): array
    {
        if ($nronota > 0) {
            $nota = Nota::query()->find($nronota);
            if (! $nota) {
                return [null, false];
            }
            if (! $this->puedeVer($request, $nota)) {
                abort(403);
            }

            return [$nota, false];
        }

        $usuario = $request->user()->username;
        $interna = $request->boolean('es_interna');

        if (! $interna && ($pendiente = $this->notaService->pendienteSinNumeroCotizacion($usuario))) {
            return [$pendiente, false];
        }

        if ($vacia = $this->notaService->ultimaSinProductos($usuario, $interna)) {
            return [$vacia, false];
        }

        if (! $persistir) {
            return [$this->notaService->borrador($usuario, $interna), false];
        }

        if ($materializada = $this->notaMaterializadaEnSesion($usuario)) {
            if ($materializada->esCotizacionInterna() === $interna) {
                return [$materializada, false];
            }
            $this->olvidarNotaMaterializada();
        }

        $nota = $this->notaService->crear($usuario, interna: $interna);
        $this->recordarNotaMaterializada($nota);

        return [$nota, true];
    }

    private function notaMaterializadaEnSesion(string $usuario): ?Nota
    {
        $id = (int) session('cotiz.borrador_materializado_nronota', 0);
        if ($id <= 0) {
            return null;
        }

        $nota = Nota::query()->find($id);
        if (! $nota || $nota->usuario !== $usuario) {
            session()->forget('cotiz.borrador_materializado_nronota');

            return null;
        }

        return $nota;
    }

    private function recordarNotaMaterializada(Nota $nota): void
    {
        session(['cotiz.borrador_materializado_nronota' => (int) $nota->nronota]);
    }

    private function olvidarNotaMaterializada(): void
    {
        session()->forget('cotiz.borrador_materializado_nronota');
    }

    /**
     * Cuenta una visita al llegar a cotizar desde Oportunidades (?codigo=...).
     */
    private function registrarVisitaOportunidadSiCorresponde(Request $request, string $codigo): void
    {
        $codigo = strtoupper(trim($codigo));
        $userId = (int) ($request->user()?->id ?? 0);
        if ($codigo === '' || $userId <= 0) {
            return;
        }

        $omitKey = 'oportunidad_visita_omitir.'.$userId;
        $omitir = strtoupper(trim((string) $request->session()->pull($omitKey, '')));
        if ($omitir === $codigo || $this->visitaOportunidadRegistrada) {
            $this->visitaOportunidadRegistrada = true;

            return;
        }

        try {
            $this->oportunidadParaCotizar->registrarVisita($userId, $codigo);
            $this->visitaOportunidadRegistrada = true;
            // Si create redirige a edit con el mismo codigo, no contar dos veces.
            $request->session()->flash($omitKey, $codigo);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{nronota: int, edit_url: string, recien_creada: bool}
     */
    private function metaNotaJson(Nota $nota, bool $recienCreada = false): array
    {
        return [
            'nronota' => (int) $nota->nronota,
            'edit_url' => route('admin.cotizaciones.edit', $this->paramsRutaEdit(request(), (int) $nota->nronota)),
            'recien_creada' => $recienCreada,
        ];
    }

    private function respuestaNotaNoExiste(int $nronota): JsonResponse
    {
        return response()->json([
            'error' => "La cotización #{$nronota} no existe.",
        ], 404);
    }

    public function update(Request $request, int $nronota): RedirectResponse
    {
        [$notaCheck] = $this->resolverNota($request, $nronota, false);

        if (! $notaCheck && $nronota > 0) {
            return $this->notaNoEncontrada($nronota);
        }

        $accion = $request->string('accion')->toString();

        if ($accion === 'aplicar_factor') {
            [$nota] = $this->resolverNota($request, $nronota, true);
            if (! $nota) {
                return $this->notaNoEncontrada($nronota);
            }
            $nronota = (int) $nota->nronota;

            $factor = $this->notaService->parseFactorPrecioVenta($request->input('factor_precio_venta'));

            if ($factor === null) {
                return back()->withInput()->withErrors([
                    'factor_precio_venta' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                ]);
            }

            $result = $this->detalleService->aplicarFactorPrecioVenta(
                $nota,
                $factor,
                $request->user()->username,
            );

            $factorFmt = number_format($result['factor'], 2, ',', '');

            return $this->redirectTrasGuardar(
                $request,
                $nronota,
                'Se actualizaron '.$result['ok'].' de '.$result['total'].' filas. Factor guardado ('.$factorFmt.').',
            );
        }

        $input = $request->all();
        if (array_key_exists('factor_precio_venta', $input) && trim((string) $input['factor_precio_venta']) !== '') {
            $factor = $this->notaService->parseFactorPrecioVenta($input['factor_precio_venta']);
            if ($factor === null) {
                return back()->withInput()->withErrors([
                    'factor_precio_venta' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                ]);
            }
            $request->merge(['factor_precio_venta' => $factor]);
        }

        $lineasJson = $this->lineasDesdeJson($request);
        if ($lineasJson !== null) {
            $request->merge(['lineas' => $lineasJson]);
        }

        $interna = $this->esInterna($request, $notaCheck);
        $datos = $request->validate(array_merge($this->reglasCabecera($interna), [
            'lineas_json' => ['nullable', 'string', 'max:500000'],
            'lineas' => ['nullable', 'array'],
            'lineas.*.prod_item' => ['required_with:lineas', 'string', 'max:50'],
            'lineas.*.orden' => ['required_with:lineas', 'integer'],
            'lineas.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'lineas.*.prod_valor' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_item_softland' => ['nullable', 'string', 'max:20'],
            'lineas.*.prod_descripcion_maestro' => ['nullable', 'string', 'max:500'],
            'lineas.*.observacion' => ['nullable', 'string'],
            'lineas.*.observacion_cliente' => ['nullable', 'string'],
        ]));

        $notaParaValidar = $notaCheck ?? $this->notaService->borrador($request->user()->username, $interna);
        if (! $interna) {
            if ($error = $this->notaService->validarNumeroCotizacionDisponible($notaParaValidar, $datos['encargado'], false, true)) {
                return back()->withInput()->withErrors(['encargado' => $error]);
            }

            try {
                $this->compraAgilOportunidad->assertExisteEnMpSiCompraAgil($datos['encargado']);
            } catch (RuntimeException $e) {
                return back()->withInput()->withErrors(['encargado' => $e->getMessage()]);
            }
        }

        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->notaNoEncontrada($nronota);
        }

        $nronota = (int) $nota->nronota;

        $lineas = $datos['lineas'] ?? [];
        unset($datos['lineas']);

        if ($request->user()->isEjecutivo()) {
            foreach ($lineas as &$linea) {
                unset($linea['prod_item_softland']);
            }
            unset($linea);
        }

        $eraSinNumero = $nota->requiereNumeroCotizacion();

        try {
            $this->notaService->modificarCabecera($nota, $datos, $request->user()->username);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['encargado' => $e->getMessage()]);
        }

        if ($lineas !== []) {
            $this->detalleService->guardarLineas($nota->fresh(), $lineas, $request->user()->username);
        }

        $mensaje = $recienCreada
            ? 'Cotización #'.$nronota.' creada y guardada.'
            : ($eraSinNumero
                ? 'Número de cotización guardado. Ya puede agregar productos.'
                : 'Cotización guardada.');

        return $this->redirectTrasGuardar($request, $nronota, $mensaje);
    }

    public function guardarCabecera(Request $request, int $nronota): JsonResponse
    {
        [$notaCheck] = $this->resolverNota($request, $nronota, false);

        if (! $notaCheck) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        if (array_key_exists('factor_precio_venta', $request->all())
            && trim((string) $request->input('factor_precio_venta')) !== '') {
            $factor = $this->notaService->parseFactorPrecioVenta($request->input('factor_precio_venta'));
            if ($factor === null) {
                return response()->json([
                    'error' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                    'errors' => ['factor_precio_venta' => ['El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).']],
                ], 422);
            }
            $request->merge(['factor_precio_venta' => $factor]);
        }

        $interna = $this->esInterna($request, $notaCheck);
        $datos = $request->validate($this->reglasCabecera($interna));

        if (! $interna) {
            if ($error = $this->notaService->validarNumeroCotizacionDisponible($notaCheck, $datos['encargado'], false, true)) {
                return response()->json([
                    'error' => $error,
                    'errors' => ['encargado' => [$error]],
                ], 422);
            }

            try {
                $this->compraAgilOportunidad->assertExisteEnMpSiCompraAgil($datos['encargado']);
            } catch (RuntimeException $e) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'errors' => ['encargado' => [$e->getMessage()]],
                ], 422);
            }
        }

        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $eraSinNumero = $nota->requiereNumeroCotizacion();

        try {
            $this->notaService->modificarCabecera($nota, $datos, $request->user()->username);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'errors' => ['encargado' => [$e->getMessage()]],
            ], 422);
        }

        $mensaje = $recienCreada
            ? 'Cotización #'.$nota->nronota.' creada y guardada.'
            : ($eraSinNumero
                ? 'Número de cotización guardado. Ya puede agregar productos.'
                : 'Cotización guardada.');

        return response()->json(array_merge([
            'ok' => true,
            'mensaje' => $mensaje,
            'era_sin_numero' => $eraSinNumero,
        ], $this->metaNotaJson($nota, $recienCreada)));
    }

    public function guardarLineasLote(Request $request, int $nronota): JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $datos = $request->validate($this->reglasLineasLote());

        $lineas = $datos['lineas'];

        if ($request->user()->isEjecutivo()) {
            foreach ($lineas as &$linea) {
                unset($linea['prod_item_softland']);
            }
            unset($linea);
        }

        try {
            $resultado = $this->detalleService->guardarLineas(
                $nota->fresh(),
                $lineas,
                $request->user()->username,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(array_merge([
            'ok' => true,
            'guardadas' => $resultado['actualizadas'],
            'omitidas' => $resultado['omitidas'],
            'recibidas' => $resultado['recibidas'],
        ], $this->metaNotaJson($nota, $recienCreada)));
    }

    public function aplicarFactor(Request $request, int $nronota): JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $factor = $this->notaService->parseFactorPrecioVenta($request->input('factor_precio_venta'));
        if ($factor === null) {
            return response()->json([
                'error' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
            ], 422);
        }

        $result = $this->detalleService->aplicarFactorPrecioVenta(
            $nota,
            $factor,
            $request->user()->username,
        );

        return response()->json(array_merge([
            'ok' => true,
            'factor_precio_venta_fmt' => number_format($result['factor'], 2, ',', ''),
            'ok_count' => $result['ok'],
            'total' => $result['total'],
            'lineas' => $result['lineas'],
        ], $this->metaNotaJson($nota, $recienCreada)));
    }

    public function agregarLinea(Request $request, int $nronota): RedirectResponse|JsonResponse
    {
        [$notaCheck] = $this->resolverNota($request, $nronota, false);

        if (! $notaCheck && $nronota > 0) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $notaParaValidar = $notaCheck ?? $this->notaService->borrador($request->user()->username, $request->boolean('es_interna'));
        if ($respuesta = $this->rechazarSinNumeroCotizacion($request, $notaParaValidar)) {
            return $respuesta;
        }

        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $datos = $request->validate([
            'prod_item' => ['required', 'string', 'max:50'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'prod_valor' => ['nullable', 'integer', 'min:0'],
            'prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'factor_precio_venta' => ['nullable', 'string'],
        ]);

        $producto = Maeprod::query()->find($datos['prod_item']);
        if (! $producto) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Producto no encontrado.'], 422);
            }

            return back()->with('error', 'Producto no encontrado.');
        }

        $costo = array_key_exists('prod_valor_costo', $datos) && $datos['prod_valor_costo'] !== null
            ? (int) $datos['prod_valor_costo']
            : (int) ($producto->prod_valor_costo ?? 0);

        $factorOverride = null;
        if (array_key_exists('factor_precio_venta', $datos) && trim((string) $datos['factor_precio_venta']) !== '') {
            $factorOverride = $this->notaService->parseFactorPrecioVenta($datos['factor_precio_venta']);
            if ($factorOverride === null) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                    ], 422);
                }

                return back()->withErrors([
                    'factor_precio_venta' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                ]);
            }
        }

        $prodValor = $this->detalleService->precioVentaSegunFactor(
            $nota,
            $costo,
            (int) ($producto->prod_valor ?? 0),
            $factorOverride,
        );

        $detalle = $this->detalleService->agregarLinea(
            $nota,
            $datos['prod_item'],
            (int) $datos['cantidad'],
            $prodValor,
            $costo,
            $request->user()->username,
        );

        if ($request->expectsJson()) {
            $totalLineas = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->count();
            $idx = max(0, $totalLineas - 1);
            $row = $this->detalleService->filaLineaParaFormulario($nota, $detalle);

            return response()->json(array_merge([
                'ok' => true,
                'idx' => $idx,
                'orden' => $detalle->orden,
                'prod_item' => (string) $detalle->prod_item,
                'prod_nombre' => $row['prod_nombre'],
                'image_url' => $row['image_url'] ?? '',
                'html' => view('admin.cotizaciones.partials.linea-detalle-row', [
                    'idx' => $idx,
                    'row' => $row,
                    'isFirst' => $idx === 0,
                    'isLast' => $idx === $totalLineas - 1,
                    'totalLineas' => $totalLineas,
                    'mostrarSoftland' => $request->user()->isSuperAdmin(),
                ])->render(),
                'delete_form_html' => view('admin.cotizaciones.partials.linea-detalle-delete-form', [
                    'nota' => $nota,
                    'row' => $row,
                ])->render(),
                'resumen' => $this->detalleService->resumenLineasNota($nota),
            ], $this->metaNotaJson($nota, $recienCreada)));
        }

        return redirect()
            ->route('admin.cotizaciones.edit', $this->paramsRutaEdit($request, (int) $nota->nronota))
            ->with('success', 'Línea agregada.');
    }

    public function eliminarLinea(Request $request, int $nronota): RedirectResponse|JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $datos = $request->validate([
            'prod_item' => ['nullable', 'string'],
            'orden' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->detalleService->eliminarLinea(
                $nota,
                (int) $datos['orden'],
                $datos['prod_item'] ?? null,
                $request->user()->username,
            );
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'resumen' => $this->detalleService->resumenLineasNota($nota),
                'lineas' => $this->detalleService->lineasOrdenJson($nota),
            ]);
        }

        return back()->with('success', 'Línea eliminada.');
    }

    public function cambiarOrdenLinea(Request $request, int $nronota): JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $datos = $request->validate([
            'prod_item' => ['required', 'string', 'max:50'],
            'orden' => ['required', 'integer', 'min:1'],
            'direccion' => ['nullable', 'in:up,down'],
            'orden_nuevo' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            if (! empty($datos['direccion'])) {
                $delta = $datos['direccion'] === 'up' ? -1 : 1;
                $this->detalleService->moverLineaRelativo(
                    $nota,
                    $datos['prod_item'],
                    (int) $datos['orden'],
                    $delta,
                );
            } else {
                if (! isset($datos['orden_nuevo'])) {
                    return response()->json(['error' => 'Indique dirección o nuevo orden.'], 422);
                }

                $this->detalleService->cambiarOrden(
                    $nota,
                    $datos['prod_item'],
                    (int) $datos['orden'],
                    (int) $datos['orden_nuevo'],
                );
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Grabado con éxito.',
            'lineas' => $this->detalleService->lineasOrdenJson($nota),
        ]);
    }

    public function importarCompraAgilPreview(Request $request, int $nronota): JsonResponse
    {
        [$nota] = $this->resolverNota($request, $nronota, false);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        if ($respuesta = $this->rechazarSiInternaNoUsaMp($nota)) {
            return $respuesta;
        }

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:50000'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($datos['desde'], $datos['hasta'])) {
            $resultado = $this->compraAgilImport->previewLote(
                $datos['texto'],
                (int) $datos['desde'],
                (int) $datos['hasta'],
            );

            $errorCabecera = null;
            $puedeImportar = true;

            if ((int) $datos['desde'] === 0 && ($resultado['cabecera']['codigo_cotizacion'] ?? '') !== '') {
                $errorCabecera = $this->notaService->validarNumeroCotizacionDisponible(
                    $nota,
                    $resultado['cabecera']['codigo_cotizacion'],
                    true,
                );
                if ($errorCabecera !== null) {
                    $puedeImportar = false;
                } else {
                    try {
                        $this->compraAgilOportunidad->assertExisteEnMpSiCompraAgil(
                            (string) $resultado['cabecera']['codigo_cotizacion'],
                        );
                    } catch (RuntimeException $e) {
                        $errorCabecera = $e->getMessage();
                        $puedeImportar = false;
                    }
                }
            }

            return response()->json(array_merge($resultado, [
                'error_cabecera' => $errorCabecera,
                'puede_importar' => $puedeImportar,
            ]));
        }

        $preview = $this->compraAgilImport->preview($datos['texto']);
        $errorCabecera = null;
        $puedeImportar = true;

        if ($preview['cabecera']['codigo_cotizacion'] !== '') {
            $errorCabecera = $this->notaService->validarNumeroCotizacionDisponible(
                $nota,
                $preview['cabecera']['codigo_cotizacion'],
                true,
            );
            if ($errorCabecera !== null) {
                $puedeImportar = false;
            } else {
                try {
                    $this->compraAgilOportunidad->assertExisteEnMpSiCompraAgil(
                        (string) $preview['cabecera']['codigo_cotizacion'],
                    );
                } catch (RuntimeException $e) {
                    $errorCabecera = $e->getMessage();
                    $puedeImportar = false;
                }
            }
        }

        return response()->json(array_merge($preview, [
            'error_cabecera' => $errorCabecera,
            'puede_importar' => $puedeImportar,
        ]));
    }

    public function coincidenciasCompraAgil(Request $request, int $nronota): JsonResponse
    {
        [$nota] = $this->resolverNota($request, $nronota, false);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        if ($respuesta = $this->rechazarSiInternaNoUsaMp($nota)) {
            return $respuesta;
        }

        $request->validate([
            'texto' => ['nullable', 'string', 'max:50000'],
        ]);

        $detalle = $this->detalleService->resumenLineasNota($nota);

        return response()->json([
            'con_agile' => $detalle['con_agile'],
            'total' => $detalle['con_agile'],
            'detalle' => $detalle,
        ]);
    }

    public function limpiarLineasAgileCompraAgil(Request $request, int $nronota): JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        if ($respuesta = $this->rechazarSiInternaNoUsaMp($nota)) {
            return $respuesta;
        }

        $eliminadas = $this->detalleService->eliminarTodasLineasAgile($nota);

        return response()->json(array_merge([
            'ok' => true,
            'eliminadas' => $eliminadas,
            'detalle' => $this->detalleService->resumenLineasNota($nota->fresh()),
        ], $this->metaNotaJson($nota, $recienCreada)));
    }

    public function importarCompraAgil(Request $request, int $nronota): JsonResponse
    {
        [$notaCheck] = $this->resolverNota($request, $nronota, false);

        if (! $notaCheck) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        if ($respuesta = $this->rechazarSiInternaNoUsaMp($notaCheck)) {
            return $respuesta;
        }

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:50000'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
        ]);

        $parseado = $this->compraAgilImport->parseTexto($datos['texto']);

        if (($parseado['cabecera']['codigo_cotizacion'] ?? '') !== ''
            && (! isset($datos['desde']) || (int) $datos['desde'] === 0)) {
            if ($error = $this->notaService->validarNumeroCotizacionDisponible(
                $notaCheck,
                $parseado['cabecera']['codigo_cotizacion'],
                true,
            )) {
                return response()->json(['error' => $error], 422);
            }

            try {
                $this->compraAgilOportunidad->assertExisteEnMpSiCompraAgil(
                    (string) $parseado['cabecera']['codigo_cotizacion'],
                );
            } catch (RuntimeException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
        }

        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        try {
            if (isset($datos['desde'], $datos['hasta'])) {
                $resultado = $this->compraAgilImport->aplicarLote(
                    $nota,
                    $datos['texto'],
                    $request->user()->username,
                    (int) $datos['desde'],
                    (int) $datos['hasta'],
                );
            } else {
                $resultado = $this->compraAgilImport->aplicar($nota, $datos['texto'], $request->user()->username);
            }
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al importar. Intente con menos líneas o contacte al administrador.',
            ], 500);
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $resultado, $this->metaNotaJson($nota, $recienCreada)));
    }

    public function importarPdfPreview(Request $request, int $nronota): JsonResponse
    {
        [$nota] = $this->resolverNota($request, $nronota, false);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $datos = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf,docx', 'max:10240'],
            'columna_cantidad' => ['required', 'string', 'max:80'],
            'columna_producto' => ['required', 'string', 'max:80'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
            'lock_id' => ['required', 'string', 'max:64'],
        ]);

        $lockId = (string) $datos['lock_id'];
        $desde = (int) ($datos['desde'] ?? 0);
        $archivo = $request->file('pdf');
        $nombreArchivo = $archivo?->getClientOriginalName() ?: 'archivo.pdf';
        $columnaCantidad = trim((string) $datos['columna_cantidad']);
        $columnaProducto = trim((string) $datos['columna_producto']);

        try {
            $this->gestionarLockAnalisisMateriales($request, $lockId, 'pdf', $nombreArchivo, $desde);

            if (isset($datos['desde'], $datos['hasta'])) {
                $resultado = $this->materialesPdfImport->previewLote(
                    $archivo,
                    (int) $datos['desde'],
                    (int) $datos['hasta'],
                    $lockId,
                    $columnaCantidad,
                    $columnaProducto,
                );
            } else {
                $resultado = $this->materialesPdfImport->preview(
                    $archivo,
                    $lockId,
                    $columnaCantidad,
                    $columnaProducto,
                );
            }
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaLockAnalisisMateriales($e);
        } catch (RuntimeException $e) {
            app(MaterialesImportLockService::class)->release($lockId);

            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            app(MaterialesImportLockService::class)->release($lockId);
            report($e);

            return response()->json([
                'error' => $this->mensajeErrorImportMateriales($e, 'Error al analizar el PDF o Word.'),
            ], 500);
        } finally {
            if (isset($resultado) && is_array($resultado)) {
                $this->liberarLockAnalisisMaterialesSiCorresponde(
                    $lockId,
                    $resultado,
                    ! isset($datos['desde'], $datos['hasta']),
                );
            }
        }

        return response()->json(array_merge($resultado, [
            'error_cabecera' => null,
            'puede_importar' => true,
        ]));
    }

    public function importarPdf(Request $request, int $nronota): JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        if ($respuesta = $this->rechazarSinNumeroCotizacion($request, $nota)) {
            return $respuesta;
        }

        $lineasPreview = $this->lineasImportPdfDesdeRequest($request);
        if ($lineasPreview !== null) {
            $datos = $request->validate([
                'desde' => ['nullable', 'integer', 'min:0'],
                'hasta' => ['nullable', 'integer', 'min:0'],
                'cabecera' => ['nullable', 'array'],
                'cabecera.codigo_cotizacion' => ['nullable', 'string', 'max:100'],
                'cabecera.empresa' => ['nullable', 'string', 'max:255'],
                'cabecera.rutempresa' => ['nullable', 'string', 'max:30'],
                'cabecera.nombre' => ['nullable', 'string', 'max:500'],
                'cabecera_json' => ['nullable', 'string', 'max:20000'],
                'lineas_json' => ['nullable', 'string', 'max:2000000'],
                'lineas' => ['nullable', 'array'],
            ]);

            $cabecera = $this->cabeceraImportPdfDesdeRequest($request, $datos);

            try {
                $desde = (int) ($datos['desde'] ?? 0);
                $hasta = (int) ($datos['hasta'] ?? count($lineasPreview));
                $resultado = $this->materialesPdfImport->aplicarLoteDesdePreview(
                    $nota,
                    [
                        'cabecera' => $cabecera,
                        'lineas' => $lineasPreview,
                    ],
                    $request->user()->username,
                    $desde,
                    $hasta,
                );
            } catch (RuntimeException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'error' => config('app.debug')
                        ? $e->getMessage()
                        : 'Error interno al importar desde PDF o Word.',
                ], 500);
            }

            return response()->json(array_merge([
                'ok' => true,
            ], $resultado));
        }

        $datos = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf,docx', 'max:10240'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            if (isset($datos['desde'], $datos['hasta'])) {
                $resultado = $this->materialesPdfImport->aplicarLote(
                    $nota,
                    $request->file('pdf'),
                    $request->user()->username,
                    (int) $datos['desde'],
                    (int) $datos['hasta'],
                );
            } else {
                $resultado = $this->materialesPdfImport->aplicar(
                    $nota,
                    $request->file('pdf'),
                    $request->user()->username,
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al importar desde PDF o Word.',
            ], 500);
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $resultado, $this->metaNotaJson($nota, $recienCreada)));
    }

    public function importarExcelPreview(Request $request, int $nronota): JsonResponse
    {
        [$nota] = $this->resolverNota($request, $nronota, false);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $datos = $request->validate([
            'excel' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'columna_descripcion' => ['required', 'string', 'max:10'],
            'columna_cantidad' => ['required', 'string', 'max:10'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
            'lock_id' => ['required', 'string', 'max:64'],
        ]);

        $lockId = (string) $datos['lock_id'];
        $desde = (int) ($datos['desde'] ?? 0);
        $archivo = $request->file('excel');
        $nombreArchivo = $archivo?->getClientOriginalName() ?: 'archivo.xlsx';

        try {
            $this->gestionarLockAnalisisMateriales($request, $lockId, 'excel', $nombreArchivo, $desde);

            if (isset($datos['desde'], $datos['hasta'])) {
                $resultado = $this->materialesExcelImport->previewLote(
                    $archivo,
                    (string) $datos['columna_descripcion'],
                    (string) $datos['columna_cantidad'],
                    (int) $datos['desde'],
                    (int) $datos['hasta'],
                );
            } else {
                $resultado = $this->materialesExcelImport->preview(
                    $archivo,
                    (string) $datos['columna_descripcion'],
                    (string) $datos['columna_cantidad'],
                );
            }
        } catch (\InvalidArgumentException $e) {
            return $this->respuestaLockAnalisisMateriales($e);
        } catch (RuntimeException $e) {
            app(MaterialesImportLockService::class)->release($lockId);

            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            app(MaterialesImportLockService::class)->release($lockId);
            report($e);

            return response()->json([
                'error' => $this->mensajeErrorImportMateriales($e, 'Error al analizar el Excel.'),
            ], 500);
        } finally {
            if (isset($resultado) && is_array($resultado)) {
                $this->liberarLockAnalisisMaterialesSiCorresponde(
                    $lockId,
                    $resultado,
                    ! isset($datos['desde'], $datos['hasta']),
                );
            }
        }

        return response()->json(array_merge($resultado, [
            'error_cabecera' => null,
            'puede_importar' => true,
        ]));
    }

    public function estadoLockAnalisisMateriales(): JsonResponse
    {
        $lock = app(MaterialesImportLockService::class);
        $current = $lock->currentOrReleaseIfAbandoned();

        return response()->json([
            'active' => $current !== null,
            'lock' => $current,
        ]);
    }

    public function liberarLockAnalisisMateriales(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'lock_id' => ['required', 'string', 'max:64'],
        ]);

        app(MaterialesImportLockService::class)->release((string) $datos['lock_id']);

        return response()->json(['ok' => true]);
    }

    public function importarExcel(Request $request, int $nronota): JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        if ($respuesta = $this->rechazarSinNumeroCotizacion($request, $nota)) {
            return $respuesta;
        }

        $lineasPreview = $this->lineasImportPdfDesdeRequest($request);
        if ($lineasPreview === null) {
            return response()->json([
                'error' => 'No hay líneas del análisis para importar. Analice el Excel de nuevo.',
            ], 422);
        }

        $datos = $request->validate([
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
            'cabecera' => ['nullable', 'array'],
            'cabecera.codigo_cotizacion' => ['nullable', 'string', 'max:100'],
            'cabecera.empresa' => ['nullable', 'string', 'max:255'],
            'cabecera.rutempresa' => ['nullable', 'string', 'max:30'],
            'cabecera.nombre' => ['nullable', 'string', 'max:500'],
            'cabecera_json' => ['nullable', 'string', 'max:20000'],
            'lineas_json' => ['nullable', 'string', 'max:2000000'],
            'lineas' => ['nullable', 'array'],
        ]);

        $cabecera = $this->cabeceraImportPdfDesdeRequest($request, $datos);

        try {
            $desde = (int) ($datos['desde'] ?? 0);
            $hasta = (int) ($datos['hasta'] ?? count($lineasPreview));
            $resultado = $this->materialesExcelImport->aplicarLoteDesdePreview(
                $nota,
                [
                    'cabecera' => $cabecera,
                    'lineas' => $lineasPreview,
                ],
                $request->user()->username,
                $desde,
                $hasta,
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al importar desde Excel.',
            ], 500);
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $resultado, $this->metaNotaJson($nota, $recienCreada)));
    }

    public function vincularLineaAgile(Request $request, int $nronota): JsonResponse
    {
        [$nota, $recienCreada] = $this->resolverNota($request, $nronota, true);

        if (! $nota) {
            return $this->respuestaNotaNoExiste($nronota);
        }

        $datos = $request->validate([
            'orden' => ['required', 'integer', 'min:1'],
            'prod_item_agile' => ['required', 'string', 'max:50'],
            'prod_item' => ['required', 'string', 'max:50'],
            'prod_valor' => ['nullable', 'integer', 'min:0'],
            'factor_precio_venta' => ['nullable', 'string'],
        ]);

        $factorOverride = null;
        if (array_key_exists('factor_precio_venta', $datos) && trim((string) $datos['factor_precio_venta']) !== '') {
            $factorOverride = $this->notaService->parseFactorPrecioVenta($datos['factor_precio_venta']);
            if ($factorOverride === null) {
                return response()->json([
                    'error' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                ], 422);
            }
        }

        try {
            $resultado = $this->detalleService->vincularLineaAgile(
                $nota,
                (int) $datos['orden'],
                $datos['prod_item_agile'],
                $datos['prod_item'],
                $request->user()->username,
                null,
                $factorOverride,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(array_merge([
            'ok' => true,
            'linea' => $resultado,
        ], $this->metaNotaJson($nota, $recienCreada)));
    }

    public function buscarProductos(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();
        $familia = $request->string('familia')->trim()->toString() ?: null;
        $modo = $request->string('modo')->trim()->toString() ?: 'similitud';
        $porTexto = $modo === 'texto';

        if ($porTexto) {
            $productos = $this->detalleService->buscarProductosPorTexto($term, $familia);
            $limit = null;
        } else {
            $limiteConfig = (int) config('cotiz.buscar_productos_limite', 15);
            $maxLimite = (int) config('cotiz.buscar_productos_max_limite', 50);
            $limit = min(
                max(1, (int) $request->input('limit', $limiteConfig)),
                max(1, $maxLimite),
            );
            $productos = $this->detalleService->buscarProductos($term, $familia, $limit);
        }

        return response()->json([
            'data' => $productos->map(fn (Maeprod $p) => [
                'prod_item' => (string) $p->prod_item,
                'prod_item_softland' => (string) ($p->prod_item_softland ?? ''),
                'prod_nombre' => $p->prod_nombre,
                'prod_valor' => $p->prod_valor,
                'prod_valor_costo' => $p->prod_valor_costo,
                'prod_familia' => $p->prod_familia,
                'prod_stock_real' => $p->prod_stock_real,
                'prod_gramaje' => $p->prod_gramaje,
                'peso_kg' => $p->peso_kg !== null ? (float) $p->peso_kg : null,
                'image_url' => $p->imageUrl(),
            ]),
            'meta' => [
                'q' => $term,
                'modo' => $porTexto ? 'texto' : 'similitud',
                'count' => $productos->count(),
                'limit' => $limit,
                'min_chars' => (int) config('cotiz.buscar_productos_min_chars', 2),
            ],
        ]);
    }

    private function notaNoEncontrada(int $nronota): RedirectResponse
    {
        return redirect()
            ->route('admin.cotizaciones.index')
            ->with(
                'error',
                "La cotización #{$nronota} no existe. Use el listado para abrirla o cree una nueva.",
            );
    }

    private function redirectTrasGuardar(Request $request, int $nronota, string $mensaje): RedirectResponse
    {
        $params = $this->paramsRutaEdit($request, $nronota);

        return redirect()
            ->route('admin.cotizaciones.edit', $params)
            ->with('success', $mensaje);
    }

    /**
     * @param  array<string, scalar>  $extra
     * @return array<string, scalar>
     */
    private function paramsRutaEdit(Request $request, int $nronota, array $extra = []): array
    {
        return array_merge(['nronota' => $nronota], CotizacionListadoRetorno::query($request), $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasCabecera(bool $interna = false): array
    {
        return [
            'descripcion' => ['required', 'string', 'max:500'],
            'empresa' => ['nullable', 'string', 'max:100'],
            'encargado' => $interna ? ['nullable', 'string', 'max:100'] : ['required', 'string', 'max:100'],
            'celular' => ['nullable', 'string', 'max:15'],
            'contacto' => ['nullable', 'string', 'max:100'],
            'contactocorreo' => ['nullable', 'string', 'max:60'],
            'rutempresa' => ['nullable', 'string', 'max:10'],
            'diashabiles' => ['nullable', 'integer', 'min:0'],
            'ocompra' => ['nullable', 'string', 'max:20'],
            'fechaentrega' => ['nullable', 'date'],
            'factor_precio_venta' => ['nullable', 'numeric', 'min:0'],
            'direccion_entrega' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'integer', 'min:1', 'max:16'],
            'nombre_region' => ['nullable', 'string', 'max:100'],
            'comuna' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasLineasLote(): array
    {
        return [
            'lineas' => ['required', 'array', 'min:1', 'max:10'],
            'lineas.*.prod_item' => ['required', 'string', 'max:50'],
            'lineas.*.orden' => ['required', 'integer'],
            'lineas.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'lineas.*.prod_valor' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_item_softland' => ['nullable', 'string', 'max:20'],
            'lineas.*.prod_descripcion_maestro' => ['nullable', 'string', 'max:500'],
            'lineas.*.observacion' => ['nullable', 'string'],
            'lineas.*.observacion_cliente' => ['nullable', 'string'],
        ];
    }

    /**
     * Líneas del preview PDF/Word enviadas al confirmar (sin re-subir el archivo).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function lineasImportPdfDesdeRequest(Request $request): ?array
    {
        if ($request->has('lineas') && is_array($request->input('lineas'))) {
            $decoded = $request->input('lineas');
        } else {
            $json = $request->string('lineas_json')->trim()->toString();
            if ($json === '') {
                return null;
            }
            $decoded = json_decode($json, true);
        }

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $lineas = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $descripcion = trim((string) ($item['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }
            $lineas[] = $item;
        }

        return $lineas === [] ? null : $lineas;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string}
     */
    private function cabeceraImportPdfDesdeRequest(Request $request, array $datos): array
    {
        $cabecera = is_array($datos['cabecera'] ?? null) ? $datos['cabecera'] : [];
        if ($cabecera === []) {
            $json = $request->string('cabecera_json')->trim()->toString();
            if ($json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $cabecera = $decoded;
                }
            }
        }

        return [
            'codigo_cotizacion' => trim((string) ($cabecera['codigo_cotizacion'] ?? '')),
            'empresa' => trim((string) ($cabecera['empresa'] ?? '')),
            'rutempresa' => trim((string) ($cabecera['rutempresa'] ?? '')),
            'nombre' => trim((string) ($cabecera['nombre'] ?? '')),
        ];
    }

    /**
     * Líneas empaquetadas en JSON (1 campo POST) para evitar truncado por max_input_vars.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function lineasDesdeJson(Request $request): ?array
    {
        $json = $request->string('lineas_json')->trim()->toString();
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $lineas = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['prod_item']) || ! isset($item['orden'])) {
                continue;
            }
            $linea = [
                'prod_item' => (string) $item['prod_item'],
                'orden' => (int) $item['orden'],
                'cantidad' => isset($item['cantidad']) ? (int) $item['cantidad'] : null,
                'prod_valor' => isset($item['prod_valor']) ? (int) $item['prod_valor'] : null,
                'prod_valor_costo' => isset($item['prod_valor_costo']) ? (int) $item['prod_valor_costo'] : null,
                'prod_item_softland' => isset($item['prod_item_softland']) ? (string) $item['prod_item_softland'] : null,
            ];
            if (array_key_exists('prod_descripcion_maestro', $item)) {
                $linea['prod_descripcion_maestro'] = (string) $item['prod_descripcion_maestro'];
            }
            if (array_key_exists('observacion', $item)) {
                $linea['observacion'] = (string) $item['observacion'];
            }
            if (array_key_exists('observacion_cliente', $item)) {
                $linea['observacion_cliente'] = (string) $item['observacion_cliente'];
            }
            $lineas[] = $linea;
        }

        return $lineas === [] ? null : $lineas;
    }

    private function puedeVer(Request $request, Nota $nota): bool
    {
        $user = $request->user();

        if ($user->perfil === User::PERFIL_SUPERADMIN) {
            return true;
        }

        return $nota->usuario === $user->username;
    }

    private function gestionarLockAnalisisMateriales(
        Request $request,
        string $lockId,
        string $tipo,
        string $nombreArchivo,
        int $desde,
    ): void {
        $usuario = $request->user();
        $lock = app(MaterialesImportLockService::class);

        if ($desde === 0) {
            $lock->acquire(
                (int) $usuario->id,
                (string) $usuario->username,
                $lockId,
                $tipo,
                $nombreArchivo,
            );

            return;
        }

        $lock->assertOwnerOrRenew($lockId);
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    private function liberarLockAnalisisMaterialesSiCorresponde(
        string $lockId,
        array $resultado,
        bool $analisisEnUnaSolaPeticion,
    ): void {
        if ($analisisEnUnaSolaPeticion || ($resultado['completado'] ?? false) === true) {
            app(MaterialesImportLockService::class)->release($lockId);
        }
    }

    private function respuestaLockAnalisisMateriales(\InvalidArgumentException $e): JsonResponse
    {
        $lock = app(MaterialesImportLockService::class)->current();

        return response()->json([
            'error' => $e->getMessage(),
            'code' => 'materiales_import_locked',
            'lock' => $lock,
        ], 409);
    }

    /**
     * Mensaje seguro para mostrar en UI al fallar import PDF/Excel (sin rutas vendor ni stack).
     */
    private function mensajeErrorImportMateriales(\Throwable $e, string $fallback): string
    {
        if ($e instanceof RuntimeException || $e instanceof \InvalidArgumentException) {
            $mensaje = trim($e->getMessage());

            return $mensaje !== '' ? $mensaje : $fallback;
        }

        $mensaje = trim($e->getMessage());
        if ($mensaje !== ''
            && mb_strlen($mensaje) <= 500
            && ! preg_match('/vendor[\\\\\\/]|stack trace|\.php:\d+/iu', $mensaje)) {
            return $mensaje;
        }

        if (config('app.debug') && $mensaje !== '') {
            return $mensaje;
        }

        return $fallback;
    }

    private function esInterna(Request $request, ?Nota $nota = null): bool
    {
        if ($nota !== null && $nota->esCotizacionInterna()) {
            return true;
        }

        return $request->boolean('es_interna');
    }

    private function rechazarSiInternaNoUsaMp(Nota $nota): ?JsonResponse
    {
        if (! $nota->esCotizacionInterna()) {
            return null;
        }

        return response()->json([
            'error' => 'Esta cotización interna no se importa ni se consulta en Mercado Público.',
        ], 422);
    }

    private function rechazarSinNumeroCotizacion(Request $request, Nota $nota): RedirectResponse|JsonResponse|null
    {
        if ($this->esInterna($request, $nota)) {
            return null;
        }

        if ($error = $this->notaService->validarNumeroCotizacion($nota)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $error], 422);
            }

            return back()->with('error', $error);
        }

        return null;
    }
}
