<?php

namespace App\Services;

use App\Support\CotizInstanciaPar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente satélite → instancia par (apiconsulta legacy).
 * Si la cotización no existe en el par, la API responde resultado ERROR (HTTP 400): eso es válido.
 */
class NotaConsultaRemotaService
{
    /**
     * @return array{
     *     sitio: string,
     *     url: string,
     *     url_env: string|null,
     *     url_utilizada: string,
     *     nota_url: string|null,
     *     http_status: int|null,
     *     existe: bool|null,
     *     resultado: string|null,
     *     mensaje: string|null,
     *     nronota: int|null,
     *     encargado: string|null,
     *     error: string|null,
     *     cold_start: bool,
     *     intento: int|null,
     *     max_intentos: int|null
     * }
     */
    public function consultarEncargadoEnPar(string $encargado): array
    {
        return $this->consultarEncargadoEnParUnIntento($encargado, 1);
    }

    /**
     * Reintentos en servidor (POST clásico, API Agile, carga archivo).
     *
     * @return array<string, mixed>
     */
    public function consultarEncargadoEnParConEspera(string $encargado): array
    {
        $codigo = trim($encargado);
        if ($codigo === '') {
            return $this->plantillaConsultaVacia($codigo);
        }

        $maxIntentos = $this->maxIntentos();
        $despertado = false;
        $ultima = $this->plantillaConsultaVacia($codigo);

        for ($intento = 1; $intento <= $maxIntentos; $intento++) {
            $ultima = $this->consultarEncargadoEnParUnIntento($codigo, $intento);

            if (! $this->esColdStart($ultima)) {
                return $ultima;
            }

            if (! $despertado) {
                $this->despertarSitioPar();
                $despertado = true;
            }

            if ($intento < $maxIntentos) {
                sleep($this->esperaEntreIntentosSegundos());
            }
        }

        $ultima['error'] = 'No se pudo conectar con el servicio de consulta en el otro sitio. Intente nuevamente en unos momentos.';
        $ultima['cold_start'] = false;

        return $ultima;
    }

    public function errorSiEncargadoExisteEnPar(string $encargado, string $mensajeDuplicado): string
    {
        $consulta = $this->consultarEncargadoEnParConEspera($encargado);

        if ($consulta['error'] !== null && $consulta['error'] !== '') {
            return $consulta['error'];
        }

        if ($consulta['existe'] === true) {
            return $consulta['mensaje'] !== null && $consulta['mensaje'] !== ''
                ? $consulta['mensaje']
                : $mensajeDuplicado;
        }

        return '';
    }

    public static function mensajeIniciandoConsulta(): string
    {
        return trim((string) config(
            'cotiz.api_nota.consulta_par_mensaje_iniciando',
            'Iniciando servicio de consulta, espere unos segundos.',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function consultarEncargadoEnParUnIntento(string $encargado, int $intento): array
    {
        $codigo = trim($encargado);
        $urlInfo = CotizInstanciaPar::resolucionUrlConsulta();
        $base = $this->plantillaConsultaVacia($codigo, $urlInfo);
        $base['intento'] = $intento;
        $base['max_intentos'] = $this->maxIntentos();

        if ($codigo === '') {
            return $base;
        }

        if (! CotizInstanciaPar::debeConsultarPar()) {
            if (CotizInstanciaPar::debeExigirConsultaPar()) {
                $base['error'] = 'No se pudo verificar duplicados en el otro sitio: configure COTIZ_API_CONSULTA_NRO_COTIZACION con la URL del sitio par (Reicol ↔ Romulo).';

                return $base;
            }

            return $base;
        }

        $url = CotizInstanciaPar::urlConsultaEncargado();
        $user = trim((string) config('cotiz.api_nota.user', ''));
        $password = (string) config('cotiz.api_nota.password', '');

        $base['sitio'] = CotizInstanciaPar::nombrePar();
        $base['url'] = $url;
        $base['url_utilizada'] = $url;

        if ($user === '' || $password === '') {
            $base['error'] = 'No se pudo verificar la cotización en el otro sitio: configure COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD en el servidor.';

            return $base;
        }

        try {
            $response = Http::timeout($this->timeoutSegundos())
                ->withBasicAuth($user, $password)
                ->post($url, [
                    'accion' => 'cotizacion',
                    'encargado' => $codigo,
                ]);

            return $this->interpretarRespuestaConsulta($response, $codigo, $url, $urlInfo, $intento);
        } catch (\Throwable $e) {
            Log::warning('Consulta encargado en sitio par: excepción', [
                'url' => $url,
                'encargado' => $codigo,
                'intento' => $intento,
                'message' => $e->getMessage(),
            ]);

            $this->despertarSitioPar();

            return $this->marcarColdStart(array_merge($urlInfo, [
                'sitio' => CotizInstanciaPar::nombrePar(),
                'url' => $url,
                'url_utilizada' => $url,
                'encargado' => $codigo,
                'intento' => $intento,
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $urlInfo
     * @return array<string, mixed>
     */
    private function interpretarRespuestaConsulta(
        Response $response,
        string $codigo,
        string $url,
        array $urlInfo,
        int $intento,
    ): array {
        $data = $response->json();
        $resultado = is_array($data) ? (string) ($data['resultado'] ?? '') : '';

        $base = array_merge($urlInfo, [
            'sitio' => CotizInstanciaPar::nombrePar(),
            'url' => $url,
            'http_status' => $response->status(),
            'existe' => null,
            'resultado' => $resultado !== '' ? $resultado : null,
            'mensaje' => is_array($data) ? trim((string) ($data['mensaje'] ?? '')) ?: null : null,
            'nronota' => is_array($data) && isset($data['nronota']) ? (int) $data['nronota'] : null,
            'encargado' => is_array($data) ? trim((string) ($data['encargado'] ?? $codigo)) ?: $codigo : $codigo,
            'error' => null,
            'cold_start' => false,
            'intento' => $intento,
            'max_intentos' => $this->maxIntentos(),
        ]);

        if ($resultado === 'OK') {
            $base['existe'] = true;
            if ($base['mensaje'] === null) {
                $base['mensaje'] = sprintf(
                    'La cotización «%s» ya existe (nota #%d).',
                    $codigo,
                    $base['nronota'] ?? 0,
                );
            }

            return $base;
        }

        if ($resultado === 'ERROR') {
            $base['existe'] = false;
            if ($base['mensaje'] === null) {
                $base['mensaje'] = 'La cotización no existe en notas.';
            }

            return $base;
        }

        if ($response->status() === 401) {
            $base['error'] = 'Error de autenticación al consultar cotización en el otro sitio. Verifique COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD.';

            return $base;
        }

        if ($this->esHttpRecuperable($response->status())) {
            Log::warning('Consulta encargado en sitio par: sitio no disponible', [
                'url' => $url,
                'status' => $response->status(),
                'encargado' => $codigo,
                'intento' => $intento,
            ]);
            $this->despertarSitioPar();

            return $this->marcarColdStart($base);
        }

        if (! $response->successful()) {
            Log::warning('Consulta encargado en sitio par falló', [
                'url' => $url,
                'status' => $response->status(),
                'encargado' => $codigo,
                'intento' => $intento,
            ]);
            $base['error'] = 'Error al consultar cotización en el otro sitio.';

            return $base;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $consulta
     */
    private function esColdStart(array $consulta): bool
    {
        return ($consulta['cold_start'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function marcarColdStart(array $base): array
    {
        $base['cold_start'] = true;
        $base['error'] = null;
        $base['existe'] = null;
        $base['mensaje'] = self::mensajeIniciandoConsulta();
        $base['max_intentos'] = $this->maxIntentos();

        return $base;
    }

    private function despertarSitioPar(): void
    {
        $url = CotizInstanciaPar::urlDespertarSitioPar();
        if ($url === '') {
            return;
        }

        try {
            Http::timeout(5)->get($url);
        } catch (\Throwable $e) {
            Log::info('Wake sitio par (/up): sin respuesta aún', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function esHttpRecuperable(int $status): bool
    {
        return $status === 0 || $status === 502 || $status === 503 || $status === 504;
    }

    private function timeoutSegundos(): int
    {
        return max(1, (int) config('cotiz.api_nota.consulta_par_timeout', 15));
    }

    private function maxIntentos(): int
    {
        return max(1, (int) config('cotiz.api_nota.consulta_par_max_intentos', 8));
    }

    private function esperaEntreIntentosSegundos(): int
    {
        return max(1, (int) config('cotiz.api_nota.consulta_par_espera_segundos', 3));
    }

    /**
     * @param  array<string, mixed>|null  $urlInfo
     * @return array<string, mixed>
     */
    private function plantillaConsultaVacia(string $codigo, ?array $urlInfo = null): array
    {
        $urlInfo ??= CotizInstanciaPar::resolucionUrlConsulta();

        return array_merge($urlInfo, [
            'sitio' => CotizInstanciaPar::nombrePar(),
            'url' => $urlInfo['url_utilizada'],
            'http_status' => null,
            'existe' => null,
            'resultado' => null,
            'mensaje' => null,
            'nronota' => null,
            'encargado' => $codigo !== '' ? $codigo : null,
            'error' => null,
            'cold_start' => false,
            'intento' => null,
            'max_intentos' => $this->maxIntentos(),
        ]);
    }
}
