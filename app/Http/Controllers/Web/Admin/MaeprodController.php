<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Services\MaeprodAdminService;
use App\Services\MaeprodImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaeprodController extends Controller
{
    public function __construct(
        protected MaeprodAdminService $maeprodService,
        protected MaeprodImportService $importService,
    ) {}

    public function index(Request $request): View
    {
        $productos = $this->maeprodService->listado(
            $request->string('q')->trim()->toString() ?: null,
            $request->string('familia')->trim()->toString() ?: null,
            (int) config('cotiz.listado_por_pagina', 20),
        );

        return view('admin.maeprod.index', [
            'productos' => $productos,
            'familias' => $this->maeprodService->familias(),
            'filtros' => [
                'q' => $request->string('q')->toString(),
                'familia' => $request->string('familia')->toString(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.maeprod.form', [
            'producto' => null,
            'familias' => $this->maeprodService->familias(),
            'storageImagenConfigurado' => $this->maeprodService->almacenamientoImagenConfigurado(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate($this->maeprodService->reglasValidacion(true));
        $datos = $this->maeprodService->normalizarDatosConImagen($datos, $request->file('imagen'));

        $this->maeprodService->crear($datos, $request->user()->username);

        return redirect()
            ->route('admin.productos.index')
            ->with('success', 'Producto creado.');
    }

    public function edit(string $prod_item): View
    {
        $producto = Maeprod::query()->findOrFail($prod_item);

        return view('admin.maeprod.form', [
            'producto' => $producto,
            'familias' => $this->maeprodService->familias(),
            'storageImagenConfigurado' => $this->maeprodService->almacenamientoImagenConfigurado(),
        ]);
    }

    public function update(Request $request, string $prod_item): RedirectResponse
    {
        $producto = Maeprod::query()->findOrFail($prod_item);

        $datos = $request->validate($this->maeprodService->reglasValidacion(false));
        $datos = $this->maeprodService->normalizarDatosConImagen($datos, $request->file('imagen'), $producto);

        $this->maeprodService->actualizar($producto, $datos, $request->user()->username);

        return redirect()
            ->route('admin.productos.edit', $producto->prod_item)
            ->with('success', 'Producto actualizado.');
    }

    public function importForm(): View
    {
        return view('admin.maeprod.import');
    }

    public function downloadImportTemplate(): StreamedResponse
    {
        return $this->importService->templateCsvDownloadResponse();
    }

    public function exportCsv(): StreamedResponse
    {
        return $this->importService->exportCsvResponse();
    }

    public function storeImport(Request $request): RedirectResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        $result = $this->importService->importFromUploadedFile(
            $request->file('archivo'),
            $request->user()->username,
        );

        $total = $result['created'] + $result['updated'];

        if ($total === 0 && $result['errors'] !== []) {
            return redirect()
                ->route('admin.productos.import')
                ->with('error', 'No se importó ningún producto.')
                ->with('import_errors', array_slice($result['errors'], 0, 30));
        }

        $mensaje = "Importación completada: {$result['created']} creados, {$result['updated']} actualizados.";
        if ($result['skipped'] > 0) {
            $mensaje .= " {$result['skipped']} filas omitidas.";
        }

        return redirect()
            ->route('admin.productos.index')
            ->with('success', $mensaje)
            ->with('import_errors', $result['errors'] !== [] ? array_slice($result['errors'], 0, 30) : null);
    }
}
