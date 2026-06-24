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
    public function errorSiEncargadoExisteEnPar(string $encargado, string $mensajeDuplicado): string
    {
        $codigo = trim($encargado);
        if ($codigo === '') {
            return '';
        }

        if (! CotizInstanciaPar::debeConsultarPar()) {
            return '';
        }

        $url = CotizInstanciaPar::urlConsultaEncargado();
        $user = trim((string) config('cotiz.api_nota.user', ''));
        $password = (string) config('cotiz.api_nota.password', '');

        if ($user === '' || $password === '') {
            return 'No se pudo verificar la cotización en el otro sitio: configure COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD en el servidor.';
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($user, $password)
                ->post($url, [
                    'accion' => 'cotizacion',
                    'encargado' => $codigo,
                ]);

            $data = $response->json();
            if (is_array($data) && ($data['resultado'] ?? '') === 'OK') {
                $mensajeApi = trim((string) ($data['mensaje'] ?? ''));

                return $mensajeApi !== '' ? $mensajeApi : $mensajeDuplicado;
            }

            if (is_array($data) && ($data['resultado'] ?? '') === 'ERROR') {
                return '';
            }

            if ($response->status() === 401) {
                return 'Error de autenticación al consultar cotización en el otro sitio. Verifique COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD.';
            }

            if (! $response->successful()) {
                Log::warning('Consulta encargado en sitio par falló', [
                    'url' => $url,
                    'status' => $response->status(),
                    'encargado' => $codigo,
                ]);

                return 'Error al consultar cotización en el otro sitio.';
            }

            return '';
        } catch (\Throwable $e) {
            Log::warning('Consulta encargado en sitio par: excepción', [
                'url' => $url,
                'encargado' => $codigo,
                'message' => $e->getMessage(),
            ]);

            return 'Error al consultar cotización en el otro sitio.';
        }
    }
}
