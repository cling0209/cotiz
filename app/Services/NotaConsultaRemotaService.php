<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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

        $url = trim((string) config('cotiz.api_nota.consulta_nro_cotizacion', ''));
        if ($url === '') {
            return '';
        }

        $hostRemoto = parse_url($url, PHP_URL_HOST);
        $hostLocal = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($hostRemoto && $hostLocal && strtolower((string) $hostRemoto) === $hostLocal) {
            return '';
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth(
                    (string) config('cotiz.api_nota.user', ''),
                    (string) config('cotiz.api_nota.password', ''),
                )
                ->post($url, [
                    'accion' => 'cotizacion',
                    'encargado' => $codigo,
                ]);

            $data = $response->json();
            if (is_array($data) && ($data['resultado'] ?? '') === 'OK') {
                return $mensajeDuplicado;
            }

            if (is_array($data) && ($data['resultado'] ?? '') === 'ERROR') {
                return '';
            }

            if ($response->status() === 401) {
                return 'Error de autenticación al consultar cotización en sitio central.';
            }

            if (! $response->successful()) {
                return 'Error al consultar cotización en sitio central.';
            }

            return '';
        } catch (\Throwable) {
            return 'Error al consultar cotización en sitio central.';
        }
    }
}
