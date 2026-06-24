<?php

namespace App\Services;

use App\Support\CotizInstanciaPar;
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
     *     http_status: int|null,
     *     existe: bool|null,
     *     resultado: string|null,
     *     mensaje: string|null,
     *     nronota: int|null,
     *     encargado: string|null,
     *     error: string|null
     * }
     */
    public function consultarEncargadoEnPar(string $encargado): array
    {
        $codigo = trim($encargado);
        $urlInfo = CotizInstanciaPar::resolucionUrlConsulta();
        $vacio = [
            'sitio' => CotizInstanciaPar::nombrePar(),
            'url' => $urlInfo['url_utilizada'],
            'url_env' => $urlInfo['url_env'],
            'url_utilizada' => $urlInfo['url_utilizada'],
            'nota_url' => $urlInfo['nota_url'],
            'http_status' => null,
            'existe' => null,
            'resultado' => null,
            'mensaje' => null,
            'nronota' => null,
            'encargado' => $codigo !== '' ? $codigo : null,
            'error' => null,
        ];

        if ($codigo === '') {
            return $vacio;
        }

        if (! CotizInstanciaPar::debeConsultarPar()) {
            if (CotizInstanciaPar::debeExigirConsultaPar()) {
                $vacio['error'] = 'No se pudo verificar duplicados en el otro sitio: configure COTIZ_API_CONSULTA_NRO_COTIZACION con la URL del sitio par (Reicol ↔ Romulo).';

                return $vacio;
            }

            return $vacio;
        }

        $url = CotizInstanciaPar::urlConsultaEncargado();
        $user = trim((string) config('cotiz.api_nota.user', ''));
        $password = (string) config('cotiz.api_nota.password', '');

        $vacio['sitio'] = CotizInstanciaPar::nombrePar();
        $vacio['url'] = $url;
        $vacio['url_utilizada'] = $url;

        if ($user === '' || $password === '') {
            $vacio['error'] = 'No se pudo verificar la cotización en el otro sitio: configure COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD en el servidor.';

            return $vacio;
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($user, $password)
                ->post($url, [
                    'accion' => 'cotizacion',
                    'encargado' => $codigo,
                ]);

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

            if (! $response->successful()) {
                Log::warning('Consulta encargado en sitio par falló', [
                    'url' => $url,
                    'status' => $response->status(),
                    'encargado' => $codigo,
                ]);
                $base['error'] = 'Error al consultar cotización en el otro sitio.';

                return $base;
            }

            return $base;
        } catch (\Throwable $e) {
            Log::warning('Consulta encargado en sitio par: excepción', [
                'url' => $url,
                'encargado' => $codigo,
                'message' => $e->getMessage(),
            ]);

            $vacio['url'] = $url;
            $vacio['error'] = 'Error al consultar cotización en el otro sitio.';

            return $vacio;
        }
    }

    public function errorSiEncargadoExisteEnPar(string $encargado, string $mensajeDuplicado): string
    {
        $consulta = $this->consultarEncargadoEnPar($encargado);

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
}
