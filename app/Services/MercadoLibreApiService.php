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

    /**
     * Si el Premium más caro supera al más barato en esta proporción, se asume precio de la ficha (buy box),
     * cuando la API no envía buy_box_winner ni tienda oficial en /items.
     */
    private const RATIO_PREMIUM_GANADOR_FICHA = 1.12;

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
     * Hasta 5 productos del catálogo con el precio de la ficha (/p/{id}): buy_box_winner.
     * La API no permite a esta app buscar publicaciones (/sites/{site}/search) ni leer /items/{id};
     * el catálogo (/products/search, /products/{id} y /products/{id}/items) sí. El catálogo no informa stock.
     *
     * @return list<array{titulo: string, precio_clp: int, unidades_por_pack: int, stock_disponible: null, url: string, imagen_url: string}>
     */
    public function buscar(string $consulta): array
    {
        $consultas = $this->consultasBusqueda($consulta);
        if ($consultas === [] || ! $this->configurado()) {
            return [];
        }

        $token = $this->accessToken();
        $opciones = [];
        $urls = [];
        $reintento401 = false;
        foreach ($consultas as $q) {
            $respuesta = $this->consultarCatalogo($q, $token);
            if ($respuesta['status'] === 401 && ! $reintento401) {
                Cache::forget(self::CACHE_ACCESS);
                $token = $this->accessToken();
                $reintento401 = true;
                $respuesta = $this->consultarCatalogo($q, $token);
            }
            if ($respuesta['status'] === 403) {
                throw new RuntimeException(
                    'Mercado Libre rechazó la búsqueda. Entra una vez a '.url('/admin/mercadolibre/conectar').' con la cuenta y vuelve a cotizar.'
                );
            }
            if ($respuesta['status'] < 200 || $respuesta['status'] >= 300) {
                throw new RuntimeException('Mercado Libre respondió HTTP '.$respuesta['status'].' al buscar productos.');
            }

            foreach ($this->opcionesDesdeCatalogo($respuesta['json'], $token) as $opcion) {
                $url = $opcion['url'];
                if (isset($urls[$url])) {
                    continue;
                }
                $urls[$url] = true;
                $opciones[] = $opcion;
                if (count($opciones) >= 5) {
                    return $opciones;
                }
            }
            if ($opciones !== []) {
                return $opciones;
            }
        }

        return $opciones;
    }

    /**
     * Variantes para el catálogo: «AUTO» suelto (no «autoperforante») se busca también como automotriz.
     *
     * @return list<string>
     */
    public function consultasBusqueda(string $consulta): array
    {
        $consulta = trim(preg_replace('/\s+/u', ' ', $consulta) ?? '');
        if ($consulta === '') {
            return [];
        }

        $variantes = [$consulta];
        if (preg_match('/\bAUTOS?\b/iu', $consulta) === 1) {
            $automotriz = trim(preg_replace('/\bAUTOS?\b/iu', 'automotriz', $consulta) ?? '');
            if ($automotriz !== '' && mb_strtolower($automotriz) !== mb_strtolower($consulta)) {
                array_unshift($variantes, $automotriz);
            }
            if (preg_match('/\bpara\s+autos?\b/iu', $consulta) !== 1
                && preg_match('/\bautomotriz\b/iu', $consulta) !== 1) {
                $sinAuto = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/\bAUTOS?\b/iu', '', $consulta)) ?? '');
                if ($sinAuto !== '') {
                    $variantes[] = $sinAuto.' para autos';
                }
            }
        }

        $unicas = [];
        foreach ($variantes as $variante) {
            $clave = mb_strtolower($variante);
            if (! isset($unicas[$clave])) {
                $unicas[$clave] = $variante;
            }
        }

        return array_values($unicas);
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
     * @param  array<string, mixed>  $json
     * @return list<array{titulo: string, precio_clp: int, unidades_por_pack: int, stock_disponible: null, url: string, imagen_url: string}>
     */
    private function opcionesDesdeCatalogo(array $json, string $token): array
    {
        $productos = [];
        $fotos = [];
        $unidades = [];
        foreach ((array) ($json['results'] ?? []) as $fila) {
            $id = is_array($fila) ? trim((string) ($fila['id'] ?? '')) : '';
            $nombre = is_array($fila) ? mb_substr(trim((string) ($fila['name'] ?? '')), 0, 200) : '';
            if ($id !== '' && $nombre !== '' && preg_match('/^[A-Z]{3}\d+$/', $id) === 1) {
                $productos[$id] = $nombre;
                $fotos[$id] = $this->primeraFoto($fila);
                // Los vendedores suelen dejar los atributos en 1 aunque el título diga «4 Pcs»: vale el mayor.
                $unidades[$id] = max($this->unidadesPorPack($nombre), $this->unidadesDesdeAtributos($fila));
            }
        }

        $opciones = [];
        foreach ($this->preciosDeFicha(array_keys($productos), $token) as $id => $precio) {
            if (count($opciones) >= 5) {
                break;
            }
            $opciones[] = [
                'titulo' => $productos[$id],
                'precio_clp' => $precio,
                'unidades_por_pack' => $unidades[$id],
                'stock_disponible' => null,
                'url' => 'https://www.mercadolibre.cl/p/'.$id,
                'imagen_url' => $fotos[$id],
            ];
        }

        return $opciones;
    }

    /**
     * Precio de la ficha /p/{id}: el del ganador del recuadro de compra (buy_box_winner).
     * Ese es el valor que ve el comprador; no el Premium más barato, que puede ser de otro vendedor.
     * Si no hay ganador, se cae a Premium de tienda oficial; luego al Premium más bajo; si no hay Premium, al más bajo de todas.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function preciosDeFicha(array $ids, string $token): array
    {
        $detalle = $this->detalleProductosCatalogo($ids, $token);
        $precios = [];
        $faltan = [];
        foreach ($ids as $id) {
            $precio = $this->precioBuyBoxDesdeProducto($detalle[$id] ?? []);
            if ($precio > 0) {
                $precios[$id] = $precio;
            } else {
                $faltan[] = $id;
            }
        }
        if ($faltan === []) {
            return $precios;
        }

        return $precios + $this->preciosMinimos($faltan, $token, $detalle);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function detalleProductosCatalogo(array $ids, string $token): array
    {
        if ($ids === []) {
            return [];
        }

        $respuestas = Http::pool(fn (Pool $pool) => array_map(
            fn (string $id) => $pool->timeout($this->timeout())
                ->withToken($token)
                ->acceptJson()
                ->get('https://api.mercadolibre.com/products/'.rawurlencode($id)),
            $ids,
        ));

        $detalle = [];
        foreach ($ids as $n => $id) {
            $respuesta = $respuestas[$n] ?? null;
            if (! $respuesta instanceof Response || ! $respuesta->successful()) {
                continue;
            }
            $json = $respuesta->json();
            if (is_array($json)) {
                $detalle[$id] = $json;
            }
        }

        return $detalle;
    }

    /**
     * Precio visible en la ficha según GET /products/{id} (ganador o tope del rango buy box).
     *
     * @param  array<string, mixed>  $producto
     */
    private function precioBuyBoxDesdeProducto(array $producto): int
    {
        $ganador = $producto['buy_box_winner'] ?? null;
        if (is_array($ganador)) {
            $precio = (int) round((float) ($ganador['price'] ?? $ganador['sale_price'] ?? 0));
            if ($precio > 0) {
                return $precio;
            }
        }

        $tope = $producto['buy_box_winner_price_range']['max'] ?? null;
        if (is_array($tope)) {
            $precio = (int) round((float) ($tope['price'] ?? 0));
            if ($precio > 0) {
                return $precio;
            }
        }

        return 0;
    }

    /**
     * Precio más bajo de las publicaciones activas de cada producto, en el orden recibido.
     * Con publicaciones Premium se toma el más bajo entre ellas (las Clásicas más baratas
     * no aparecen en la ficha). Si hay Premium de tienda oficial, ese precio (el de la ficha
     * cuando gana la marca). Sin Premium, el más bajo de todas.
     * Los productos sin publicaciones (404) o con error se omiten.
     *
     * @param  list<string>  $ids
     * @param  array<string, array<string, mixed>>  $detalleProducto
     * @return array<string, int>
     */
    private function preciosMinimos(array $ids, string $token, array $detalleProducto = []): array
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
            $resultados = (array) ($respuesta->json('results') ?? []);
            $precioGanador = $this->precioItemGanadorDesdeListado($detalleProducto[$id] ?? [], $resultados);
            if ($precioGanador > 0) {
                $precios[$id] = $precioGanador;

                continue;
            }

            $minimo = null;
            $minimoPremium = null;
            $maximoPremium = null;
            $minimoPremiumOficial = null;
            foreach ($resultados as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $precio = (int) round((float) ($item['price'] ?? 0));
                if ($precio <= 0) {
                    continue;
                }
                $minimo = $minimo === null ? $precio : min($minimo, $precio);
                if (($item['listing_type_id'] ?? '') !== self::LISTING_PREMIUM) {
                    continue;
                }
                $minimoPremium = $minimoPremium === null ? $precio : min($minimoPremium, $precio);
                $maximoPremium = $maximoPremium === null ? $precio : max($maximoPremium, $precio);
                if ($this->itemEsTiendaOficial($item)) {
                    $minimoPremiumOficial = $minimoPremiumOficial === null ? $precio : min($minimoPremiumOficial, $precio);
                }
            }
            $elegido = $minimoPremiumOficial;
            if ($elegido === null && $minimoPremium !== null && $maximoPremium !== null
                && $maximoPremium > $minimoPremium
                && $maximoPremium / $minimoPremium >= self::RATIO_PREMIUM_GANADOR_FICHA) {
                $elegido = $maximoPremium;
            }
            $elegido ??= $minimoPremium ?? $minimo;
            if ($elegido !== null) {
                $precios[$id] = $elegido;
            }
        }

        return $precios;
    }

    /**
     * Precio de la publicación ganadora cuando buy_box_winner trae item_id pero no price.
     *
     * @param  array<string, mixed>  $producto
     * @param  list<mixed>  $resultados
     */
    private function precioItemGanadorDesdeListado(array $producto, array $resultados): int
    {
        $ganador = $producto['buy_box_winner'] ?? null;
        if (! is_array($ganador)) {
            return 0;
        }
        $itemId = trim((string) ($ganador['item_id'] ?? ''));
        if ($itemId === '') {
            return 0;
        }
        foreach ($resultados as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (trim((string) ($item['item_id'] ?? '')) !== $itemId) {
                continue;
            }
            $precio = (int) round((float) ($item['price'] ?? 0));

            return $precio > 0 ? $precio : 0;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemEsTiendaOficial(array $item): bool
    {
        $oficial = $item['official_store_id'] ?? null;
        if ($oficial === null && is_array($item['official_store'] ?? null)) {
            $oficial = $item['official_store']['id'] ?? $item['official_store']['official_store_id'] ?? null;
        }
        if ($oficial === null || $oficial === '' || $oficial === 0 || $oficial === '0') {
            return false;
        }

        return true;
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
     * Unidades que trae la venta según el catálogo: UNITS_PER_PACKAGE (unidades por envase) ×
     * UNITS_PER_PACK (envases por venta). 1 si no vienen.
     *
     * @param  array<string, mixed>  $producto
     */
    public function unidadesDesdeAtributos(array $producto): int
    {
        $valores = [];
        foreach ((array) ($producto['attributes'] ?? []) as $atributo) {
            if (is_array($atributo) && isset($atributo['id'])) {
                $valores[(string) $atributo['id']] = (int) ($atributo['value_name'] ?? 0);
            }
        }
        $unidades = max(1, $valores['UNITS_PER_PACKAGE'] ?? 1) * max(1, $valores['UNITS_PER_PACK'] ?? 1);

        return $unidades <= 1000 ? $unidades : 1;
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
            && preg_match('/\b(\d{1,4})\s*(?:u|un|und|unds|unid|unidades|ud|uds|pc|pcs|pz|pza|pzas|pzs|piezas?)\b\.?/iu', $titulo, $coincide) !== 1) {
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
