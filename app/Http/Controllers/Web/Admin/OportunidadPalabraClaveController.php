<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\OportunidadPalabraClave;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OportunidadPalabraClaveController extends Controller
{
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

        return redirect()
            ->route('admin.oportunidades.palabras-clave.index')
            ->with('success', 'Palabra clave agregada.');
    }

    public function destroy(OportunidadPalabraClave $palabra): RedirectResponse
    {
        $palabra->delete();

        return redirect()
            ->route('admin.oportunidades.palabras-clave.index')
            ->with('success', 'Palabra clave eliminada.');
    }

    private function normalizarFrase(string $frase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $frase) ?? $frase);
    }
}
