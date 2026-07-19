<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\CorreosChileDexTarifa;
use App\Services\Admin\CorreosChileDexImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class CorreosChileTarifaController extends Controller
{
    public function __construct(protected CorreosChileDexImportService $importService) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $query = CorreosChileDexTarifa::query()->orderBy('destino');

        if ($q !== '') {
            $key = CorreosChileDexTarifa::normalizeDestinoKey($q);
            $query->where(function ($builder) use ($key) {
                $builder->where('destino_key', 'like', '%'.$key.'%')
                    ->orWhere('origen', 'like', '%'.$key.'%');
            });
        }

        $tarifas = $query->paginate(40)->withQueryString();

        $ultima = CorreosChileDexTarifa::query()
            ->whereNotNull('imported_at')
            ->orderByDesc('imported_at')
            ->first();

        $total = CorreosChileDexTarifa::query()->count();

        $tramos = [];
        if ($ultima && is_array($ultima->tarifas)) {
            $tramos = array_keys($ultima->tarifas);
        }

        return view('admin.correos-chile.index', [
            'tarifas' => $tarifas,
            'ultima' => $ultima,
            'total' => $total,
            'tramos' => $tramos,
            'q' => $q,
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'archivo' => [
                'required',
                'file',
                'max:20480',
                'mimes:xlsx,xls,csv',
            ],
        ], [
            'archivo.required' => 'Seleccione el Excel de tarifas Correos Chile.',
            'archivo.mimes' => 'El archivo debe ser Excel (.xlsx, .xls) o CSV.',
            'archivo.max' => 'El archivo no puede superar 20 MB.',
        ]);

        try {
            $result = $this->importService->importFromUpload(
                $data['archivo'],
                $request->user()
            );
        } catch (Throwable $e) {
            return redirect()
                ->route('admin.correos-chile.index')
                ->with('error', 'No se pudo importar la tarifa: '.$e->getMessage());
        }

        $msg = "Tarifa Correos Chile importada: {$result['imported']} destinos";
        if ($result['skipped'] > 0) {
            $msg .= " ({$result['skipped']} filas omitidas)";
        }
        $msg .= '.';

        return redirect()
            ->route('admin.correos-chile.index')
            ->with('success', $msg);
    }
}
