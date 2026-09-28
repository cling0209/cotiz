<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Búsqueda pública de publicaciones en Mercado Libre Chile.
 * El access token sale de client credentials o, si la API lo rechaza, de un refresh token
 * obtenido una vez con Authorization Code.
 */
class MercadoLibreApiService
{
    private const CACHE_ACCESS = 'mercadolibre:access_token';

    private const ARCHIVO_TOKEN = 'app/mercadolibre-oauth.json';

    public function configurado(): bool
    {
        return (bool) config('cotiz.mercadolibre.habilitado', true)
            && $this->clientId() !== ''
            && $this->clientSecret() !== '';
    }

    public function urlAutorizacion(): string
    {
        $this->exigirConfigurado();

        return 'https://auth.mercadolibre.cl/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    public function guardarCodigo(string $codigo): void
    {
        $this->exigirConfigurado();
        $codigo = trim($codigo);
        if ($codigo === '') {
            throw new RuntimeException('Mercado Libre no devolvió el código de autorización.');
        }

        $this->pedirToken([
            'grant_type' => 'authorization_code',
            'code' => $codigo,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Hasta 5 publicaciones con precio, stock y enlace de ficha.
     *
     * @return list<array{titulo: string, precio_clp: int, unidades_por_pack: int, stock_disponible: int, url: string}>
     */
    public function buscar(string $consulta): array
    {
        $consulta = trim($consulta);
        if ($consulta === '' || ! $this->configurado()) {
            return [];
        }

        $respuesta = $this->consultarBusqueda($consulta, $this->accessToken());
        if ($respuesta['status'] === 401) {
            Cache::forget(self::CACHE_ACCESS);
            $respuesta = $this->consultarBusqueda($consulta, $this->accessToken());
        }
        if ($respuesta['status'] === 403) {
            throw new RuntimeException(
                'Mercado Libre rechazó la búsqueda. Entra una vez a '.url('/admin/mercadolibre/conectar').' con la cuenta y vuelve a cotizar.'
            );
        }
        if ($respuesta['status'] < 200 || $respuesta['status'] >= 300) {
            throw new RuntimeException('Mercado Libre respondió HTTP '.$respuesta['status'].' al buscar publicaciones.');
        }

        $opciones = [];
        foreach ((array) ($respuesta['json']['results'] ?? []) as $fila) {
            if (! is_array($fila) || count($opciones) >= 5) {
                break;
            }
            $titulo = mb_substr(trim((string) ($fila['title'] ?? '')), 0, 200);
            $url = trim((string) ($fila['permalink'] ?? ''));
            $precio = (int) round((float) ($fila['price'] ?? 0));
            if ($titulo === '' || $url === '' || $precio <= 0) {
                continue;
            }
            $stock = $fila['available_quantity'] ?? null;
            $opciones[] = [
                'titulo' => $titulo,
                'precio_clp' => $precio,
                'unidades_por_pack' => $this->unidadesPorPack($titulo),
                'stock_disponible' => is_numeric($stock) ? max(0, (int) $stock) : null,
                'url' => mb_substr($url, 0, 1000),
            ];
        }

        return $opciones;
    }

    public function redirectUri(): string
    {
        $uri = trim((string) config('cotiz.mercadolibre.redirect_uri', ''));

        return $uri !== '' ? $uri : url('/admin/mercadolibre/callback');
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     */
    private function consultarBusqueda(string $consulta, string $token): array
    {
        $site = rawurlencode((string) config('cotiz.mercadolibre.site_id', 'MLC'));
        $respuesta = Http::timeout($this->timeout())
            ->withToken($token)
            ->acceptJson()
            ->get("https://api.mercadolibre.com/sites/{$site}/search", [
                'q' => mb_substr($consulta, 0, 180),
                'limit' => 5,
            ]);

        $json = $respuesta->json();

        return [
            'status' => $respuesta->status(),
            'json' => is_array($json) ? $json : [],
        ];
    }

    private function accessToken(): string
    {
        $cacheado = Cache::get(self::CACHE_ACCESS);
        if (is_string($cacheado) && $cacheado !== '') {
            return $cacheado;
        }

        $refresh = $this->refreshTokenGuardado();
        if ($refresh !== '') {
            try {
                return $this->pedirToken([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh,
                ]);
            } catch (RuntimeException) {
                $this->olvidarRefresh();
            }
        }

        return $this->pedirToken(['grant_type' => 'client_credentials']);
    }

    /**
     * @param  array<string, string>  $campos
     */
    private function pedirToken(array $campos): string
    {
        $respuesta = Http::timeout($this->timeout())
            ->asForm()
            ->acceptJson()
            ->post('https://api.mercadolibre.com/oauth/token', $campos + [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);

        $json = $respuesta->json();
        $token = is_array($json) ? trim((string) ($json['access_token'] ?? '')) : '';
        if (! $respuesta->successful() || $token === '') {
            $detalle = is_array($json) ? trim((string) ($json['message'] ?? $json['error'] ?? '')) : '';
            throw new RuntimeException(
                'No se pudo obtener el token de Mercado Libre'.($detalle !== '' ? ': '.$detalle : '.')
            );
        }

        $expira = max(60, (int) (is_array($json) ? ($json['expires_in'] ?? 21600) : 21600) - 120);
        Cache::put(self::CACHE_ACCESS, $token, now()->addSeconds($expira));

        $refresh = is_array($json) ? trim((string) ($json['refresh_token'] ?? '')) : '';
        if ($refresh !== '') {
            $this->guardarRefresh($refresh);
        }

        return $token;
    }

    private function unidadesPorPack(string $titulo): int
    {
        if (preg_match('/\b(?:pack|set|caja|bolsa)\s*(?:de|x|por)?\s*(\d{1,4})\b/iu', $titulo, $coincide) !== 1) {
            return 1;
        }
        $unidades = (int) $coincide[1];

        return $unidades >= 1 && $unidades <= 1000 ? $unidades : 1;
    }

    private function refreshTokenGuardado(): string
    {
        $deEnv = trim((string) config('cotiz.mercadolibre.refresh_token', ''));
        if ($deEnv !== '') {
            return $deEnv;
        }

        $archivo = $this->leerArchivoToken();

        return trim((string) ($archivo['refresh_token'] ?? ''));
    }

    private function guardarRefresh(string $refresh): void
    {
        $ruta = storage_path(self::ARCHIVO_TOKEN);
        $directorio = dirname($ruta);
        if (! is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }
        file_put_contents($ruta, json_encode(['refresh_token' => $refresh], JSON_UNESCAPED_UNICODE));
    }

    private function olvidarRefresh(): void
    {
        $ruta = storage_path(self::ARCHIVO_TOKEN);
        if (is_file($ruta)) {
            unlink($ruta);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function leerArchivoToken(): array
    {
        $ruta = storage_path(self::ARCHIVO_TOKEN);
        if (! is_file($ruta)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($ruta), true);

        return is_array($json) ? $json : [];
    }

    private function exigirConfigurado(): void
    {
        if (! $this->configurado()) {
            throw new RuntimeException('Falta el Client ID o el Client Secret de Mercado Libre.');
        }
    }

    private function clientId(): string
    {
        return trim((string) config('cotiz.mercadolibre.client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) config('cotiz.mercadolibre.client_secret', ''));
    }

    private function timeout(): int
    {
        return (int) config('cotiz.mercadolibre.timeout', 20);
    }
}
