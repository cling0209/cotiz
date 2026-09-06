<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Parametro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParametroController extends Controller
{
    public function index(): View
    {
        return view('admin.parametros.index', [
            'parametros' => Parametro::query()->orderBy('nombre')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'valores' => ['required', 'array'],
            'valores.*' => ['nullable', 'string', 'max:500'],
        ]);

        $claves = Parametro::query()->pluck('clave')->all();
        foreach ($claves as $clave) {
            if (! array_key_exists($clave, $data['valores'])) {
                continue;
            }
            $valor = trim((string) $data['valores'][$clave]);
            if ($clave === Parametro::CLAVE_PAGO_COTIZACION_REALIZADA) {
                $valor = preg_replace('/[^\d]/', '', $valor) ?? '';
                if ($valor === '') {
                    return back()
                        ->withInput()
                        ->withErrors(['valores.'.$clave => 'Ingrese un monto entero válido.']);
                }
            }
            Parametro::setValue($clave, $valor);
        }

        return redirect()
            ->route('admin.parametros.index')
            ->with('success', 'Parámetros actualizados.');
    }
}
