<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\OportunidadPalabraClave;
use App\Services\OportunidadPalabraClaveRelayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OportunidadPalabraClaveController extends Controller
{
    public function __construct(
        protected OportunidadPalabraClaveRelayService $relay,
    ) {}

    public function index(): View
    {
        $palabras = OportunidadPalabraClave::query()
            ->with('creador')
            ->orderBy('frase')
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

        OportunidadPalabraClave::query()->create([
            'frase' => $frase,
            'created_by' => $request->user()?->id,
        ]);

        $local = trim((string) config('cotiz.sistema', config('app.name', 'este sistema')));
        $mensajeLocal = 'Palabra clave agregada en '.$local.'.';

        try {
            $mensajeRemoto = $this->relay->replicarAgregar($frase);

            return redirect()
                ->route('admin.oportunidades.palabras-clave.index')
                ->with('success', $mensajeLocal.' '.$mensajeRemoto);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.oportunidades.palabras-clave.index')
                ->with('success', $mensajeLocal)
                ->with('info', 'El otro sitio no respondió; se sincronizará al levantar el contenedor.')
                ->with('error', $e->getMessage());
        }
    }

    public function destroy(OportunidadPalabraClave $palabra): RedirectResponse
    {
        $frase = $palabra->frase;
        $palabra->delete();

        $local = trim((string) config('cotiz.sistema', config('app.name', 'este sistema')));
        $mensajeLocal = 'Palabra clave eliminada en '.$local.'.';

        try {
            $mensajeRemoto = $this->relay->replicarEliminar($frase);

            return redirect()
                ->route('admin.oportunidades.palabras-clave.index')
                ->with('success', $mensajeLocal.' '.$mensajeRemoto);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.oportunidades.palabras-clave.index')
                ->with('success', $mensajeLocal)
                ->with('info', 'El otro sitio no respondió; se sincronizará al levantar el contenedor.')
                ->with('error', $e->getMessage());
        }
    }

    private function normalizarFrase(string $frase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $frase) ?? $frase);
    }
}
