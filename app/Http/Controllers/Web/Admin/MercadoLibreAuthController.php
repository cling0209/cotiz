<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CotizarIaService;
use App\Services\MercadoLibreApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class MercadoLibreAuthController extends Controller
{
    public function conectar(MercadoLibreApiService $mercadolibre): RedirectResponse
    {
        $this->autorizado();

        return redirect()->away($mercadolibre->urlAutorizacion());
    }

    public function callback(Request $request, MercadoLibreApiService $mercadolibre): Response
    {
        $this->autorizado();

        $codigo = trim((string) $request->query('code', ''));
        if ($codigo === '') {
            return response('Mercado Libre no devolvió el código de autorización.', 400);
        }

        try {
            $mercadolibre->guardarCodigo($codigo);
        } catch (RuntimeException $e) {
            return response($e->getMessage(), 502);
        }

        return response('Mercado Libre quedó conectado. Puedes volver a cotizar.');
    }

    private function autorizado(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && CotizarIaService::usuarioPermitido($user), 403);
    }
}
