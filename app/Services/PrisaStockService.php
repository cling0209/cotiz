<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Estado de stock en Prisa (prisa.cl) por código de producto, que es el mismo prod_item del maestro.
 * Usa la búsqueda pública (sin sesión: el login exige reCAPTCHA); cada resultado trae el
 * «Estado de Producto» de Prisa. El precio público no es el del cliente, así que no se usa.
 */
class PrisaStockService
{
    public const ESTADO_DISPONIBLE = 'disponible';

    public const ESTADO_ULTIMAS_UNIDADES = 'ultimas_unidades';

    public const ESTADO_SIN_STOCK = 'sin_stock';

    /** Estado que Prisa no tenía al programar esto: no se decide nada con él. */
    public const ESTADO_DESCONOCIDO = 'desconocido';

    /** «Estado de Producto» de Prisa (filtro availability del buscador). */
    private const ETIQUETAS = [
        9101 => 'OUTLET',
        9102 => 'AGOTADO',
        9103 => 'DISPONIBLE',
        9104 => 'ÚLTIMAS UNIDADES',
        9105 => 'A PEDIDO',
        9106 => 'SAMPLING',
        9107 => 'CONSUMO INTERNO',
        9108 => 'DESCONTINUADO',
        9109 => 'REGALO',
        9110 => 'OFERTA',
        9111 => 'AGOTADOS SIN WEB',
    ];

    private const CON_STOCK = [9101, 9103, 9110];

    private const ULTIMAS_UNIDADES = [9104];

    private const CACHE_COOKIE = 'prisa_stock:cookie';

    public function habilitado(): bool
    {
        return (bool) config('cotiz.prisa.habilitado', true);
    }

    /**
     * @param  list<string>  $codigos
     * @return array<string, array{estado: string, etiqueta: string, url: string}|null>
     *                                                                                 null = Prisa no tiene ese código; los códigos ausentes no se pudieron consultar
     */
    public function consultar(array $codigos): array
    {
        $codigos = array_slice(array_values(array_unique(array_filter(
            array_map(static fn ($c) => trim((string) $c), $codigos),
            static fn (string $c) => $c !== '',
        ))), 0, (int) config('cotiz.prisa.max_codigos', 200));

        $out = [];
        $faltan = [];
        foreach ($codigos as $codigo) {
            $guardado = Cache::get($this->cacheKey($codigo));
            if (is_array($guardado) && array_key_exists('r', $guardado)) {
                $out[$codigo] = $guardado['r'];
            } else {
                $faltan[] = $codigo;
            }
        }
        if ($faltan === []) {
            return $out;
        }

        // La primera consulta va sola: si Prisa pide el desafío anti-robots, calcula la cookie para el resto.
        $primero = array_shift($faltan);
        $this->guardar($out, $primero, $this->consultarUno($primero));

        foreach (array_chunk($faltan, (int) config('cotiz.prisa.concurrencia', 5)) as $lote) {
            try {
                $respuestas = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $codigo) => $this->peticion($pool->as($codigo))->get($this->urlBusqueda(), ['search' => $codigo]),
                    $lote,
                ));
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            foreach ($lote as $codigo) {
                $resp = $respuestas[$codigo] ?? null;
                if ($resp instanceof Response && $this->sinResultados($resp)) {
                    $this->guardar($out, $codigo, null);

                    continue;
                }
                $cuerpo = $resp instanceof Response && $resp->successful() ? $resp->body() : null;
                if ($cuerpo !== null && $this->esDesafio($cuerpo)) {
                    $this->guardar($out, $codigo, $this->consultarUno($codigo, $cuerpo));

                    continue;
                }
                $this->guardar($out, $codigo, $cuerpo === null ? false : $this->estadoDesdeBusqueda($cuerpo, $codigo));
            }
        }

        return $out;
    }

    /**
     * @return array{estado: string, etiqueta: string, url: string}|null|false false = no se pudo consultar
     */
    private function consultarUno(string $codigo, ?string $desafio = null): array|null|false
    {
        for ($intento = 0; $intento < 2; $intento++) {
            if ($desafio !== null && ! $this->resolverDesafio($desafio)) {
                return false;
            }
            try {
                $resp = $this->peticion(Http::getFacadeRoot())->get($this->urlBusqueda(), ['search' => $codigo]);
            } catch (Throwable $e) {
                report($e);

                return false;
            }
            if ($this->sinResultados($resp)) {
                return null;
            }
            if (! $resp->successful()) {
                return false;
            }
            $cuerpo = $resp->body();
            if (! $this->esDesafio($cuerpo)) {
                return $this->estadoDesdeBusqueda($cuerpo, $codigo);
            }
            $desafio = $cuerpo;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array{estado: string, etiqueta: string, url: string}|null|false  $resultado
     */
    private function guardar(array &$out, string $codigo, array|null|false $resultado): void
    {
        if ($resultado === false) {
            return;
        }
        $out[$codigo] = $resultado;
        $horas = (int) config('cotiz.prisa.cache_horas', 3);
        if ($horas > 0) {
            Cache::put($this->cacheKey($codigo), ['r' => $resultado], now()->addHours($horas));
        }
    }

    /**
     * @return array{estado: string, etiqueta: string, url: string}|null|false
     */
    public function estadoDesdeBusqueda(string $html, string $codigo): array|null|false
    {
        $filas = $this->extraerJson(html_entity_decode($html, ENT_QUOTES | ENT_HTML5), '"data":{"data":');
        if ($filas === null) {
            return false;
        }
        foreach ($filas as $fila) {
            if (! is_array($fila) || strcasecmp(trim((string) ($fila['sku'] ?? '')), $codigo) !== 0) {
                continue;
            }
            $id = (int) ($fila['availability'] ?? 0);
            $link = (string) ($fila['view_link'] ?? '');

            return [
                'estado' => match (true) {
                    in_array($id, self::CON_STOCK, true) => self::ESTADO_DISPONIBLE,
                    in_array($id, self::ULTIMAS_UNIDADES, true) => self::ESTADO_ULTIMAS_UNIDADES,
                    isset(self::ETIQUETAS[$id]) => self::ESTADO_SIN_STOCK,
                    default => self::ESTADO_DESCONOCIDO,
                },
                'etiqueta' => self::ETIQUETAS[$id] ?? 'ESTADO '.$id,
                'url' => str_starts_with($link, '/') ? config('cotiz.prisa.base_url').$link : '',
            ];
        }

        return null;
    }

    private function peticion(mixed $cliente): mixed
    {
        $cookie = Cache::get(self::CACHE_COOKIE);

        return $cliente
            ->timeout((int) config('cotiz.prisa.timeout', 15))
            ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36')
            ->withHeaders(is_string($cookie) && $cookie !== '' ? ['Cookie' => 'OCXS='.$cookie] : []);
    }

    /** Sin coincidencias Prisa redirige a /product/no-result (que además vuelve a mostrar el desafío). */
    private function sinResultados(Response $resp): bool
    {
        return str_contains((string) $resp->effectiveUri(), '/product/no-result');
    }

    private function esDesafio(string $cuerpo): bool
    {
        return str_contains($cuerpo, 'slowAES') && str_contains($cuerpo, 'toNumbers(');
    }

    /** Desafío anti-robots de Prisa: cookie OCXS = AES-128-CBC(clave, iv, dato) con valores de la propia página. */
    private function resolverDesafio(string $cuerpo): bool
    {
        if (! preg_match_all('/toNumbers\("([0-9a-f]{32})"\)/', $cuerpo, $m) || count($m[1]) < 3) {
            return false;
        }
        [$clave, $iv, $dato] = array_map('hex2bin', array_slice($m[1], 0, 3));
        $plano = openssl_decrypt($dato, 'aes-128-cbc', $clave, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($plano === false) {
            return false;
        }
        Cache::put(self::CACHE_COOKIE, bin2hex($plano), now()->addHours(12));

        return true;
    }

    /**
     * Extrae el JSON (arreglo u objeto) que empieza justo después de $marca, respetando cadenas.
     *
     * @return array<int|string, mixed>|null
     */
    private function extraerJson(string $texto, string $marca): ?array
    {
        $inicio = strpos($texto, $marca);
        if ($inicio === false) {
            return null;
        }
        $inicio += strlen($marca);
        $nivel = 0;
        $enCadena = false;
        for ($j = $inicio, $n = strlen($texto); $j < $n; $j++) {
            $ch = $texto[$j];
            if ($enCadena) {
                if ($ch === '\\') {
                    $j++;
                } elseif ($ch === '"') {
                    $enCadena = false;
                }

                continue;
            }
            if ($ch === '"') {
                $enCadena = true;
            } elseif ($ch === '[' || $ch === '{') {
                $nivel++;
            } elseif ($ch === ']' || $ch === '}') {
                $nivel--;
                if ($nivel === 0) {
                    $json = json_decode(substr($texto, $inicio, $j - $inicio + 1), true);

                    return is_array($json) ? $json : null;
                }
            }
        }

        return null;
    }

    private function urlBusqueda(): string
    {
        return config('cotiz.prisa.base_url').'/product/search';
    }

    private function cacheKey(string $codigo): string
    {
        return 'prisa_stock:'.md5(mb_strtoupper($codigo));
    }
}
