<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\OportunidadPalabraClave;
use App\Services\CompraAgilRegionScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OportunidadPalabraClaveController extends Controller
{
    private const ORDEN_CAMPOS = ['frase', 'creador', 'fecha'];

    public function index(Request $request): View
    {
        $ordenCampo = (string) $request->input('orden_campo', 'frase');
        if (! in_array($ordenCampo, self::ORDEN_CAMPOS, true)) {
            $ordenCampo = 'frase';
        }

        $ordenDir = strtoupper((string) $request->input('orden_dir', 'ASC'));
        if (! in_array($ordenDir, ['ASC', 'DESC'], true)) {
            $ordenDir = 'ASC';
        }

        $query = OportunidadPalabraClave::query()->with(['creador', 'regiones']);

        if ($ordenCampo === 'creador') {
            $query
                ->leftJoin('users', 'users.id', '=', 'oportunidad_palabras_clave.created_by')
                ->select('oportunidad_palabras_clave.*')
                ->orderByRaw('LOWER(COALESCE(users.username, \'\')) '.$ordenDir)
                ->orderBy('oportunidad_palabras_clave.id');
        } elseif ($ordenCampo === 'fecha') {
            $query
                ->orderBy('created_at', $ordenDir)
                ->orderBy('id');
        } else {
            $query
                ->orderByRaw('LOWER(frase) '.$ordenDir)
                ->orderBy('id');
        }

        $palabras = $query->get();

        $filtros = [
            'orden_campo' => $ordenCampo,
            'orden_dir' => $ordenDir,
        ];

        return view('admin.oportunidades.palabras-clave.index', [
            'palabras' => $palabras,
            'filtros' => $filtros,
            'regionesDisponibles' => $this->regionesDisponibles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $frase = $this->normalizarFrase((string) $request->input('frase', ''));
        $request->merge(['frase' => $frase]);

        $request->validate([
            'frase' => [
                'required',
                'string',
                'min:2',
                'max:200',
                Rule::unique('oportunidad_palabras_clave', 'frase'),
            ],
            'regiones' => ['nullable', 'array'],
            'regiones.*' => ['integer'],
        ], [
            'frase.required' => 'Indique la palabra clave.',
            'frase.unique' => 'Esa palabra clave ya está registrada.',
            'frase.min' => 'La palabra clave debe tener al menos 2 caracteres.',
        ]);

        $maxOrden = (int) OportunidadPalabraClave::query()->max('orden');

        $palabra = OportunidadPalabraClave::query()->create([
            'frase' => $frase,
            'orden' => $maxOrden + 1,
            'created_by' => $request->user()?->id,
        ]);

        $this->syncRegiones($palabra, $this->normalizarRegiones($request));

        return redirect()
            ->route('admin.oportunidades.palabras-clave.index')
            ->with('success', 'Palabra clave agregada.');
    }

    public function update(Request $request, OportunidadPalabraClave $palabra): RedirectResponse
    {
        $request->validate([
            'regiones' => ['nullable', 'array'],
            'regiones.*' => ['integer'],
        ]);

        $this->syncRegiones($palabra, $this->normalizarRegiones($request));

        return redirect()
            ->route('admin.oportunidades.palabras-clave.index')
            ->with('success', 'Regiones de «'.$palabra->frase.'» actualizadas.');
    }

    public function destroy(OportunidadPalabraClave $palabra): RedirectResponse
    {
        $palabra->delete();
        $this->renumerar();

        return redirect()
            ->route('admin.oportunidades.palabras-clave.index')
            ->with('success', 'Palabra clave eliminada.');
    }

    public function mover(Request $request, OportunidadPalabraClave $palabra): RedirectResponse
    {
        $data = $request->validate([
            'direccion' => ['required', Rule::in(['up', 'down'])],
        ]);

        $lista = OportunidadPalabraClave::query()
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        $indice = $lista->search(fn (OportunidadPalabraClave $p) => (int) $p->id === (int) $palabra->id);
        if ($indice === false) {
            return redirect()
                ->route('admin.oportunidades.palabras-clave.index')
                ->with('error', 'No se encontró la palabra clave.');
        }

        $otroIndice = $data['direccion'] === 'up' ? $indice - 1 : $indice + 1;
        if ($otroIndice < 0 || $otroIndice >= $lista->count()) {
            return redirect()->route('admin.oportunidades.palabras-clave.index');
        }

        $actual = $lista[$indice];
        $otro = $lista[$otroIndice];
        $ordenActual = (int) $actual->orden;
        $ordenOtro = (int) $otro->orden;

        DB::transaction(function () use ($actual, $otro, $ordenActual, $ordenOtro) {
            $actual->orden = $ordenOtro;
            $actual->save();
            $otro->orden = $ordenActual;
            $otro->save();
        });

        $this->renumerar();

        return redirect()
            ->route('admin.oportunidades.palabras-clave.index')
            ->with('success', 'Prioridad de búsqueda actualizada.');
    }

    public function reordenar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', Rule::exists('oportunidad_palabras_clave', 'id')],
        ]);

        $ids = array_map('intval', $data['ids']);
        $total = OportunidadPalabraClave::query()->count();

        if (count($ids) !== $total) {
            return response()->json([
                'ok' => false,
                'error' => 'La lista enviada no coincide con las palabras clave actuales.',
            ], 422);
        }

        DB::transaction(function () use ($ids) {
            $n = 1;
            foreach ($ids as $id) {
                OportunidadPalabraClave::query()
                    ->where('id', $id)
                    ->update(['orden' => $n]);
                $n++;
            }
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Prioridad de búsqueda actualizada.',
            'info' => null,
            'error' => null,
        ]);
    }

    private function renumerar(): void
    {
        $n = 1;
        foreach (
            OportunidadPalabraClave::query()
                ->orderBy('orden')
                ->orderBy('id')
                ->get() as $palabra
        ) {
            if ((int) $palabra->orden !== $n) {
                $palabra->orden = $n;
                $palabra->save();
            }
            $n++;
        }
    }

    private function normalizarFrase(string $frase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $frase) ?? $frase);
    }

    /**
     * @return array<int, string>
     */
    private function regionesDisponibles(): array
    {
        $out = [];
        foreach (CompraAgilRegionScope::regionesIncluidas() as $codigo) {
            $out[(int) $codigo] = CompraAgilRegionScope::nombreRegion((int) $codigo);
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function normalizarRegiones(Request $request): array
    {
        $permitidas = CompraAgilRegionScope::regionesIncluidas();
        $input = $request->input('regiones', []);
        if (! is_array($input)) {
            return [];
        }

        $codigos = [];
        foreach ($input as $valor) {
            $codigo = (int) $valor;
            if ($codigo > 0 && in_array($codigo, $permitidas, true)) {
                $codigos[$codigo] = $codigo;
            }
        }

        return array_values($codigos);
    }

    /**
     * @param  list<int>  $codigos  vacío = todas las regiones
     */
    private function syncRegiones(OportunidadPalabraClave $palabra, array $codigos): void
    {
        DB::transaction(function () use ($palabra, $codigos) {
            $palabra->regiones()->delete();

            foreach ($codigos as $codigo) {
                $palabra->regiones()->create([
                    'region_codigo' => $codigo,
                ]);
            }
        });
    }
}
