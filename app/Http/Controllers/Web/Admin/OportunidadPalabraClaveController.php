<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\OportunidadPalabraClave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OportunidadPalabraClaveController extends Controller
{
    public function index(): View
    {
        $palabras = OportunidadPalabraClave::query()
            ->with('creador')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        return view('admin.oportunidades.palabras-clave.index', compact('palabras'));
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
        ], [
            'frase.required' => 'Indique la palabra clave.',
            'frase.unique' => 'Esa palabra clave ya está registrada.',
            'frase.min' => 'La palabra clave debe tener al menos 2 caracteres.',
        ]);

        $maxOrden = (int) OportunidadPalabraClave::query()->max('orden');

        OportunidadPalabraClave::query()->create([
            'frase' => $frase,
            'orden' => $maxOrden + 1,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.oportunidades.palabras-clave.index')
            ->with('success', 'Palabra clave agregada (al final de la prioridad).');
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
}
