<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\NotaDetalle;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NotaEnvioRelayService
{
    public function __construct(
        protected NotaRecepcionApiService $recepcionService,
    ) {}

    /**
     * Equivalente legacy apinotaenvio.php: lee la nota local y la reenvía a apinota remoto.
     */
    public function relay(Nota $nota, ?string $usuarioEnvio = null): void
    {
        $destino = trim((string) config('cotiz.api_nota.url', ''));
        if ($destino === '') {
            throw new RuntimeException('No está configurada la URL de recepción API (COTIZ_API_NOTA_URL).');
        }

        $nota->load(['detalle.producto']);

        if ($nota->detalle->isEmpty()) {
            throw new RuntimeException('La cotización no tiene líneas de detalle.');
        }

        $usuarioRelay = trim((string) ($usuarioEnvio ?: $nota->usuario));
        if ($usuarioRelay === '') {
            throw new RuntimeException('La cotización no tiene usuario asignado.');
        }

        $resumen = $nota->toArray();
        $resumen['accion'] = 'graba_resumen';
        $resumen['notaorigen'] = $nota->nronota;
        $resumen['nronota'] = 0;
        $resumen['usuario'] = $usuarioRelay;
        $resumen['sistema'] = config('app.name');
        $resumen['enviadoapi'] = 0;
        unset($resumen['detalle']);

        $nronotaDestino = $this->postRemoto($destino, $resumen);
        if ($nronotaDestino <= 0) {
            throw new RuntimeException('No se obtuvo nronota destino al grabar resumen.');
        }

        foreach ($nota->detalle as $linea) {
            $this->enviarDetalle($destino, $nota, $linea, $nronotaDestino);
        }
    }

    /**
     * Recibe petición apinotaenvio (solo nronota) y ejecuta relay local → remoto.
     */
    public function relayDesdeSolicitud(array $payload): void
    {
        $nronota = (int) ($payload['nronota'] ?? 0);
        if ($nronota <= 0) {
            throw new RuntimeException('nronota inválido');
        }

        $nota = Nota::query()->find($nronota);
        if (! $nota) {
            throw new RuntimeException('La cotización no existe.');
        }

        $this->relay($nota);
    }

    private function enviarDetalle(string $destino, Nota $nota, NotaDetalle $linea, int $nronotaDestino): void
    {
        $producto = $linea->producto;
        $payload = [
            'accion' => 'graba_detalle',
            'nronota' => $nronotaDestino,
            'prod_item' => $linea->prod_item,
            'prod_valor' => (int) $linea->prod_valor,
            'cantidad' => (int) $linea->cantidad,
            'orden' => (int) $linea->orden,
            'prod_valor_costo' => (int) $linea->prod_valor_costo,
            'prod_item_agile' => (string) ($linea->prod_item_agile ?? ''),
            'prod_descripcion_agile' => (string) ($linea->prod_descripcion_agile ?? ''),
            'prod_nombre' => $producto?->prod_nombre ?? $linea->prod_descripcion_agile ?? $linea->prod_item,
            'prod_familia' => $producto?->prod_familia ?? '',
            'prod_imagen' => $producto?->prod_imagen ?? '',
            'prod_gramaje' => $producto?->prod_gramaje ?? '',
            'prod_item_softland' => $producto?->prod_item_softland ?? '',
            'prod_user_upd' => $producto?->prod_user_upd ?? (string) $nota->usuario,
            'base64' => $this->imagenBase64($producto?->resolveImageUrl()),
        ];

        $this->postRemoto($destino, $payload);
    }

    /**
     * @return int nronota devuelto por graba_resumen (0 si no aplica)
     */
    private function postRemoto(string $url, array $payload): int
    {
        $request = Http::timeout(60)->asJson();
        $user = (string) config('cotiz.api_nota.user', '');
        $password = (string) config('cotiz.api_nota.password', '');

        if ($user !== '') {
            $request = $request->withBasicAuth($user, $password);
        }

        $response = $request->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Error HTTP al enviar cotización a la API remota.');
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? (string) ($data['mensaje'] ?? 'Error desconocido') : 'Respuesta inválida';

            throw new RuntimeException('No se realizó el envío: '.$mensaje);
        }

        return (int) ($data['nronota'] ?? 0);
    }

    private function imagenBase64(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        try {
            $response = Http::timeout(10)->get($url);
            if ($response->successful()) {
                return base64_encode($response->body());
            }
        } catch (\Throwable) {
            // Sin imagen remota: el destino puede usar catálogo existente.
        }

        return '';
    }
}
