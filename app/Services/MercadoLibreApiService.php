<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Precios de referencia desde el catálogo de productos de Mercado Libre Chile.
 * El access token sale de client credentials o, si la API lo rechaza, de un refresh token
 * obtenido una vez con Authorization Code.
 */
class MercadoLibreApiService
{
    private const CACHE_ACCESS = 'mercadolibre:access_token';

    /** En la base de datos: el contenedor se recrea en cada deploy y storage/ no persiste. */
    private const TABLA_TOKEN = 'integracion_tokens';

    private const PROVEEDOR = 'mercadolibre';

    /** Muchos productos del catálogo no tienen publicaciones activas; se piden más para encontrar precio. */
    private const PRODUCTOS_POR_BUSQUEDA = 10;

    /** Tipo de publicación «Premium» en /products/{id}/items (las Clásicas son gold_special). */
    private const LISTING_PREMIUM = 'gold_pro';

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
     * Hasta 5 productos del catálogo con el precio vigente más bajo de sus publicaciones.
     * La API no permite a esta app buscar publicaciones (/sites/{site}/search) ni leer /items/{id};
     * el catálogo (/products/search + /products/{id}/items) sí. El catálogo no informa stock.
     *
     * @return list<array{titulo: string, precio_clp: int, unidades_por_pack: int, stock_disponible: null, url: string, imagen_url: string}>
     */
    public function buscar(string $consulta): array
    {
        $consulta = trim($consulta);
        if ($consulta === '' || ! $this->configurado()) {
            return [];
        }

        $token = $this->accessToken();
        $respuesta = $this->consultarCatalogo($consulta, $token);
        if ($respuesta['status'] === 401) {
            Cache::forget(self::CACHE_ACCESS);
            $token = $this->accessToken();
            $respuesta = $this->consultarCatalogo($consulta, $token);
        }
        if ($respuesta['status'] === 403) {
            throw new RuntimeException(
                'Mercado Libre rechazó la búsqueda. Entra una vez a '.url('/admin/mercadolibre/conectar').' con la cuenta y vuelve a cotizar.'
            );
        }
        if ($respuesta['status'] < 200 || $respuesta['status'] >= 300) {
            throw new RuntimeException('Mercado Libre respondió HTTP '.$respuesta['status'].' al buscar productos.');
        }

        $productos = [];
        $fotos = [];
        foreach ((array) ($respuesta['json']['results'] ?? []) as $fila) {
            $id = is_array($fila) ? trim((string) ($fila['id'] ?? '')) : '';
            $nombre = is_array($fila) ? mb_substr(trim((string) ($fila['name'] ?? '')), 0, 200) : '';
            if ($id !== '' && $nombre !== '' && preg_match('/^[A-Z]{3}\d+$/', $id) === 1) {
                $productos[$id] = $nombre;
                $fotos[$id] = $this->primeraFoto($fila);
            }
        }

        $opciones = [];
        foreach ($this->preciosMinimos(array_keys($productos), $token) as $id => $precio) {
            if (count($opciones) >= 5) {
                break;
            }
            $opciones[] = [
                'titulo' => $productos[$id],
                'precio_clp' => $precio,
                'unidades_por_pack' => $this->unidadesPorPack($productos[$id]),
                'stock_disponible' => null,
                'url' => 'https://www.mercadolibre.cl/p/'.$id,
                'imagen_url' => $fotos[$id],
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
    private function consultarCatalogo(string $consulta, string $token): array
    {
        $respuesta = Http::timeout($this->timeout())
            ->withToken($token)
            ->acceptJson()
            ->get('https://api.mercadolibre.com/products/search', [
                'status' => 'active',
                'site_id' => (string) config('cotiz.mercadolibre.site_id', 'MLC'),
                'q' => mb_substr($consulta, 0, 180),
                'limit' => self::PRODUCTOS_POR_BUSQUEDA,
            ]);

        $json = $respuesta->json();

        return [
            'status' => $respuesta->status(),
            'json' => is_array($json) ? $json : [],
        ];
    }

    /**
     * Precio más bajo de las publicaciones activas de cada producto, en el orden recibido.
     * Con publicaciones Premium se toma el más bajo entre ellas: es el precio que muestra la página
     * del producto (las Clásicas más baratas no aparecen ahí). Sin Premium, el más bajo de todas.
     * Los productos sin publicaciones (404) o con error se omiten.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function preciosMinimos(array $ids, string $token): array
    {
        if ($ids === []) {
            return [];
        }

        $respuestas = Http::pool(fn (Pool $pool) => array_map(
            fn (string $id) => $pool->timeout($this->timeout())
                ->withToken($token)
                ->acceptJson()
                ->get('https://api.mercadolibre.com/products/'.rawurlencode($id).'/items'),
            $ids,
        ));

        $precios = [];
        foreach ($ids as $n => $id) {
            $respuesta = $respuestas[$n] ?? null;
            if (! $respuesta instanceof Response || ! $respuesta->successful()) {
                continue;
            }
            $minimo = null;
            $minimoPremium = null;
            foreach ((array) ($respuesta->json('results') ?? []) as $item) {
                $precio = is_array($item) ? (int) round((float) ($item['price'] ?? 0)) : 0;
                if ($precio <= 0) {
                    continue;
                }
                $minimo = $minimo === null ? $precio : min($minimo, $precio);
                if (($item['listing_type_id'] ?? '') === self::LISTING_PREMIUM) {
                    $minimoPremium = $minimoPremium === null ? $precio : min($minimoPremium, $precio);
                }
            }
            if (($minimoPremium ?? $minimo) !== null) {
                $precios[$id] = $minimoPremium ?? $minimo;
            }
        }

        return $precios;
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

    /**
     * @param  array<string, mixed>  $producto
     */
    private function primeraFoto(array $producto): string
    {
        foreach ((array) ($producto['pictures'] ?? []) as $foto) {
            $url = is_array($foto) ? trim((string) ($foto['secure_url'] ?? $foto['url'] ?? '')) : '';
            if (ImagenReferenciaWebService::urlPermitida($url)) {
                return mb_substr($url, 0, 500);
            }
        }

        return '';
    }

    /** Unidades que trae un pack/caja según el texto («Caja 12 Unidades», «pack x 6», «1000un»); 1 si no lo indica. */
    public function unidadesPorPack(string $titulo): int
    {
        if (preg_match('/\b(?:pack|set|caja|bolsa)\s*(?:de|x|por)?\s*(\d{1,4})\b/iu', $titulo, $coincide) !== 1
            && preg_match('/\b(\d{1,4})\s*(?:u|un|und|unds|unid|unidades)\b\.?/iu', $titulo, $coincide) !== 1) {
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

        return trim((string) DB::table(self::TABLA_TOKEN)->where('proveedor', self::PROVEEDOR)->value('refresh_token'));
    }

    private function guardarRefresh(string $refresh): void
    {
        $ahora = now();
        DB::table(self::TABLA_TOKEN)->updateOrInsert(
            ['proveedor' => self::PROVEEDOR],
            ['refresh_token' => $refresh, 'created_at' => $ahora, 'updated_at' => $ahora],
        );
    }

    private function olvidarRefresh(): void
    {
        DB::table(self::TABLA_TOKEN)->where('proveedor', self::PROVEEDOR)->delete();
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
