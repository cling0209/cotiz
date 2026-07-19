<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Services\CorreosChileDexQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CotizacionEnvioDexController extends Controller
{
    public function __construct(
        protected CorreosChileDexQuoteService $quoteService,
    ) {}

    public function catalogo(Request $request, int $nronota): JsonResponse
    {
        $this->autorizarAcceso($request, $nronota);

        return response()->json([
            'ok' => true,
            ...$this->quoteService->catalogo(),
        ]);
    }

    public function cotizar(Request $request, int $nronota): JsonResponse
    {
        $this->autorizarAcceso($request, $nronota);

        $datos = $request->validate([
            'origen' => ['required', 'string', 'max:80'],
            'destino' => ['required', 'string', 'max:120'],
            'peso_kg' => ['required', 'numeric', 'min:0.001', 'max:100000'],
        ]);

        try {
            $resultado = $this->quoteService->cotizar(
                (string) $datos['origen'],
                (string) $datos['destino'],
                (float) $datos['peso_kg'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            ...$resultado,
        ]);
    }

    /**
     * nronota 0 (borrador) permitido: solo consulta tarifas globales.
     * Si la nota existe, exige dueño o superadmin.
     */
    private function autorizarAcceso(Request $request, int $nronota): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($nronota <= 0) {
            return;
        }

        $nota = Nota::query()->find($nronota);
        if (! $nota) {
            abort(404);
        }

        if (! $user->isSuperAdmin() && trim((string) $nota->usuario) !== trim((string) $user->username)) {
            abort(403);
        }
    }
}
