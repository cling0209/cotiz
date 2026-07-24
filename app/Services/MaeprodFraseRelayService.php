<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\MaeprodFrase;
use App\Support\CotizInstanciaPar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MaeprodFraseRelayService
{
    public function __construct(
        protected MaeprodBusquedaSimilitudService $busquedaSimilitud,
    ) {}

    /**
     * Replica alta de frase al sitio par (sin reenviar si llega por API).
     */
    public function replicarAgregar(MaeprodFrase $frase): string
    {
        return $this->enviar('graba', [
            'prod_item' => $frase->prod_item,
            'frase' => $frase->frase,
            'frase_norm' => $frase->frase_norm,
        ]);
    }

    /**
     * @param  array{prod_item: string, frase: string, frase_norm: string}  $snapshot
     */
    public function replicarEliminar(array $snapshot): string
    {
        return $this->enviar('elimina', [
            'prod_item' => $snapshot['prod_item'],
            'frase' => $snapshot['frase'],
            'frase_norm' => $snapshot['frase_norm'],
        ]);
    }

    /**
     * Aplica payload recibido del par (sin reenviar).
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     created?: bool,
     *     deleted?: bool,
     *     skipped?: bool,
     *     prod_item: string,
     *     frase_norm: string,
     *     mensaje?: string
     * }
     */
    public function recibir(string $accion, array $payload): array
    {
        $prodItem = trim((string) ($payload['prod_item'] ?? ''));
        $fraseDisplay = trim(preg_replace('/\s+/u', ' ', (string) ($payload['frase'] ?? '')) ?? '');
        $fraseNorm = trim((string) ($payload['frase_norm'] ?? ''));
        if ($fraseNorm === '' && $fraseDisplay !== '') {
            $fraseNorm = $this->busquedaSimilitud->normalizarTexto($fraseDisplay);
        }
        if ($fraseNorm === '' && isset($payload['frase'])) {
            $fraseNorm = $this->busquedaSimilitud->normalizarTexto((string) $payload['frase']);
        }

        $fraseNorm = mb_substr($fraseNorm, 0, 200);

        if ($prodItem === '') {
            throw new RuntimeException('no viene prod_item');
        }
        if ($fraseNorm === '') {
            throw new RuntimeException('no viene frase / frase_norm');
        }

        return match ($accion) {
            'graba' => $this->recibirGraba($prodItem, $fraseDisplay, $fraseNorm),
            'elimina' => $this->recibirElimina($prodItem, $fraseNorm),
            default => throw new RuntimeException('Accion no existe: '.$accion),
        };
    }

    /**
     * @return array{created: bool, skipped: bool, prod_item: string, frase_norm: string, mensaje?: string}
     */
    private function recibirGraba(string $prodItem, string $fraseDisplay, string $fraseNorm): array
    {
        if (! Maeprod::query()->whereKey($prodItem)->exists()) {
            return [
                'created' => false,
                'skipped' => true,
                'prod_item' => $prodItem,
                'frase_norm' => $fraseNorm,
                'mensaje' => 'Producto no existe en este sitio; frase no aplicada',
            ];
        }

        $existente = MaeprodFrase::query()->where('frase_norm', $fraseNorm)->first();
        if ($existente) {
            if ($existente->prod_item !== $prodItem) {
                throw new RuntimeException(
                    'frase_norm ya asignada a otro producto ('.$existente->prod_item.')'
                );
            }

            if ($fraseDisplay !== '' && $existente->frase !== $fraseDisplay) {
                $existente->frase = mb_substr($fraseDisplay, 0, 200);
                $existente->save();
            }

            return [
                'created' => false,
                'skipped' => false,
                'prod_item' => $prodItem,
                'frase_norm' => $fraseNorm,
            ];
        }

        MaeprodFrase::query()->create([
            'prod_item' => $prodItem,
            'frase' => mb_substr($fraseDisplay !== '' ? $fraseDisplay : $fraseNorm, 0, 200),
            'frase_norm' => $fraseNorm,
        ]);

        return [
            'created' => true,
            'skipped' => false,
            'prod_item' => $prodItem,
            'frase_norm' => $fraseNorm,
        ];
    }

    /**
     * @return array{deleted: bool, skipped: bool, prod_item: string, frase_norm: string}
     */
    private function recibirElimina(string $prodItem, string $fraseNorm): array
    {
        $query = MaeprodFrase::query()->where('frase_norm', $fraseNorm);
        $row = $query->first();
        if (! $row) {
            return [
                'deleted' => false,
                'skipped' => false,
                'prod_item' => $prodItem,
                'frase_norm' => $fraseNorm,
            ];
        }

        // Si el par envía prod_item distinto al local, no borrar (defensa).
        if ($row->prod_item !== $prodItem) {
            throw new RuntimeException(
                'frase_norm pertenece a otro producto ('.$row->prod_item.')'
            );
        }

        $row->delete();

        return [
            'deleted' => true,
            'skipped' => false,
            'prod_item' => $prodItem,
            'frase_norm' => $fraseNorm,
        ];
    }

    /**
     * @param  array{prod_item: string, frase: string, frase_norm: string}  $datos
     */
    private function enviar(string $accion, array $datos): string
    {
        $destino = $this->urlPar();
        if ($destino === '') {
            return '';
        }

        $userAuth = (string) config('cotiz.api_nota.user', '');
        $passwordAuth = (string) config('cotiz.api_nota.password', '');
        if ($userAuth === '' || $passwordAuth === '') {
            throw new RuntimeException(
                'Faltan COTIZ_API_NOTA_USER o COTIZ_API_NOTA_PASSWORD para sincronizar con el par.'
            );
        }

        $peer = CotizInstanciaPar::nombrePar();

        $payload = [
            'accion' => $accion,
            'replicacion' => true,
            'origen_sistema' => (string) config('cotiz.sistema', config('app.name')),
            'prod_item' => $datos['prod_item'],
            'frase' => $datos['frase'],
            'frase_norm' => $datos['frase_norm'],
        ];

        try {
            $response = Http::timeout(30)
                ->asJson()
                ->withBasicAuth($userAuth, $passwordAuth)
                ->post($destino, $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo conectar con '.$peer.': '.$e->getMessage()
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->mensajeErrorHttp($response, $peer));
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? trim((string) ($data['mensaje'] ?? '')) : '';

            throw new RuntimeException(
                $mensaje !== '' ? $peer.': '.$mensaje : 'Respuesta inválida de '.$peer.'.'
            );
        }

        if (! empty($data['skipped'])) {
            $detalle = trim((string) ($data['mensaje'] ?? 'frase no aplicada en el par'));

            return 'En '.$peer.': '.$detalle;
        }

        return 'También sincronizado en '.$peer.'.';
    }

    private function urlPar(): string
    {
        $explicit = trim((string) config('cotiz.api_maeprod_frase.url', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $usuarioUrl = trim((string) config('cotiz.api_usuario.url', ''));
        if ($usuarioUrl === '') {
            $base = CotizInstanciaPar::basePar();

            return $base ? rtrim($base, '/').'/api/v1/maeprod-frase' : '';
        }

        if (str_ends_with($usuarioUrl, '/usuario')) {
            return substr($usuarioUrl, 0, -strlen('/usuario')).'/maeprod-frase';
        }

        return rtrim($usuarioUrl, '/').'/maeprod-frase';
    }

    private function mensajeErrorHttp(Response $response, string $peer): string
    {
        $data = $response->json();
        if (is_array($data)) {
            $mensaje = trim((string) ($data['mensaje'] ?? ''));
            if ($mensaje !== '') {
                return $peer.': '.$mensaje;
            }
        }

        return match ($response->status()) {
            401 => $peer.': autorización rechazada (401).',
            404 => $peer.': ruta no encontrada (404).',
            default => $peer.': error HTTP '.$response->status().'.',
        };
    }

    public function logFalloSync(string $contexto, \Throwable $e): void
    {
        Log::warning('maeprod_frase.relay_fail', [
            'contexto' => $contexto,
            'error' => $e->getMessage(),
        ]);
    }
}
