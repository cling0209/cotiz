<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Support\CotizInstanciaPar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        $this->despertarSitioPar();

        $nota->load(['detalle.producto']);

        if ($nota->detalle->isEmpty()) {
            throw new RuntimeException('La cotización no tiene líneas de detalle.');
        }

        // El dueño manda: si un admin envía la cotización de un ejecutivo, en el otro
        // sitio debe quedar a nombre del ejecutivo, no de quien apretó el botón.
        $usuarioRelay = trim((string) ($nota->usuario ?: $usuarioEnvio));
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
        $producto = $linea->resolveProducto();
        $payload = [
            'accion' => 'graba_detalle',
            'nronota' => $nronotaDestino,
            'prod_item' => $linea->codigoProducto(),
            'prod_valor' => (int) $linea->prod_valor,
            'cantidad' => (int) $linea->cantidad,
            'orden' => (int) $linea->orden,
            'prod_valor_costo' => (int) $linea->prod_valor_costo,
            'prod_item_agile' => (string) ($linea->prod_item_agile ?? ''),
            'prod_descripcion_agile' => (string) ($linea->prod_descripcion_agile ?? ''),
            'prod_nombre' => $producto?->prod_nombre ?? $linea->prod_descripcion_agile ?? $linea->codigoProducto(),
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
            throw new RuntimeException($this->mensajeErrorHttp($response, $url));
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? (string) ($data['mensaje'] ?? 'Error desconocido') : 'Respuesta inválida';

            throw new RuntimeException('No se realizó el envío: '.$mensaje);
        }

        return (int) ($data['nronota'] ?? 0);
    }

    private function despertarSitioPar(): void
    {
        $url = CotizInstanciaPar::urlDespertarSitioPar();
        if ($url === '') {
            return;
        }

        $maxSeg = max(0, min(60, (int) config('cotiz.api_nota.consulta_par_timeout', 15)));
        $intervalo = max(1, (int) config('cotiz.api_nota.consulta_par_espera_segundos', 5));
        $inicio = time();

        while (true) {
            try {
                if (Http::timeout(8)->get($url)->successful()) {
                    return;
                }
            } catch (\Throwable $e) {
                Log::info('Envío cotización: sitio par sin respuesta aún', [
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]);
            }

            if ($maxSeg <= 0 || (time() - $inicio) >= $maxSeg) {
                Log::warning('Envío cotización: sitio par no despertó a tiempo, se intentará enviar igual', [
                    'url' => $url,
                    'max_seg' => $maxSeg,
                ]);

                return;
            }

            sleep($intervalo);
        }
    }

    private function mensajeErrorHttp(Response $response, string $url): string
    {
        $peer = $this->nombreInstanciaPar($url);
        $data = $response->json();
        if (is_array($data)) {
            $mensaje = trim((string) ($data['mensaje'] ?? ''));
            if ($mensaje !== '') {
                return $peer.': '.$mensaje;
            }
        }

        return match ($response->status()) {
            401 => $peer.': autorización rechazada (401). Verifique que COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD sean iguales en Romulo y Reicol.',
            404 => $peer.': ruta no encontrada (404). URL configurada: '.$url,
            502, 503, 504 => $peer.': servicio no disponible ('.$response->status().'). Espere unos segundos e intente de nuevo.',
            default => 'Error HTTP al enviar cotización a la API remota ('.$response->status().').',
        };
    }

    private function nombreInstanciaPar(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        if (str_contains($host, 'reicol')) {
            return 'Reicol';
        }
        if (str_contains($host, 'romulo')) {
            return 'Romulo';
        }

        return $host !== '' ? $host : CotizInstanciaPar::nombrePar();
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
