<?php

namespace App\Services;

use App\Models\OportunidadPalabraClave;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OportunidadPalabraClaveRelayService
{
    /**
     * Replica alta de frase hacia la instancia par (Romulo ↔ Reicol).
     */
    public function replicarAgregar(string $frase): string
    {
        return $this->enviar('graba', $frase);
    }

    /**
     * Replica baja de frase hacia la instancia par (Romulo ↔ Reicol).
     */
    public function replicarEliminar(string $frase): string
    {
        return $this->enviar('elimina', $frase);
    }

    /**
     * Aplica recepción remota. Idempotente. No vuelve a relay (evita bucle).
     *
     * @return array{ok: bool, created?: bool, deleted?: bool, frase: string}
     */
    public function recibir(string $accion, string $frase): array
    {
        $frase = $this->normalizarFrase($frase);
        if ($frase === '') {
            throw new RuntimeException('frase inválida');
        }

        if ($accion === 'graba') {
            $existe = OportunidadPalabraClave::query()->where('frase', $frase)->exists();
            if ($existe) {
                return ['ok' => true, 'created' => false, 'frase' => $frase];
            }

            OportunidadPalabraClave::query()->create([
                'frase' => $frase,
                'created_by' => null,
            ]);

            return ['ok' => true, 'created' => true, 'frase' => $frase];
        }

        if ($accion === 'elimina') {
            $borrados = OportunidadPalabraClave::query()->where('frase', $frase)->delete();

            return ['ok' => true, 'deleted' => $borrados > 0, 'frase' => $frase];
        }

        throw new RuntimeException('Accion no existe: '.$accion);
    }

    private function enviar(string $accion, string $frase): string
    {
        $frase = $this->normalizarFrase($frase);
        $destino = $this->urlDestino();
        if ($destino === '') {
            throw new RuntimeException(
                'No hay URL del sitio par para palabras clave. Configure COTIZ_API_USUARIO_URL '
                .'(o COTIZ_API_PALABRA_CLAVE_URL) apuntando a la otra instancia.'
            );
        }

        $userAuth = (string) config('cotiz.api_nota.user', '');
        $passwordAuth = (string) config('cotiz.api_nota.password', '');
        if ($userAuth === '' || $passwordAuth === '') {
            throw new RuntimeException(
                'Faltan COTIZ_API_NOTA_USER o COTIZ_API_NOTA_PASSWORD (Basic Auth compartida con la otra instancia).'
            );
        }

        $peer = $this->nombreInstanciaPar($destino);

        $payload = [
            'accion' => $accion,
            'replicacion' => true,
            'origen_sistema' => (string) config('cotiz.sistema', config('app.name')),
            'frase' => $frase,
        ];

        try {
            $response = Http::timeout(30)
                ->asJson()
                ->withBasicAuth($userAuth, $passwordAuth)
                ->post($destino, $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo conectar con '.$peer.' ('.$destino.'): '.$e->getMessage()
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->mensajeErrorHttp($response, $destino, $peer));
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? trim((string) ($data['mensaje'] ?? '')) : '';

            throw new RuntimeException(
                $mensaje !== ''
                    ? $peer.': '.$mensaje
                    : 'Respuesta inválida de '.$peer.'.'
            );
        }

        if ($accion === 'graba') {
            $created = (bool) ($data['created'] ?? true);

            return $created
                ? 'También se agregó en '.$peer.'.'
                : 'En '.$peer.' la frase ya existía.';
        }

        $deleted = (bool) ($data['deleted'] ?? true);

        return $deleted
            ? 'También se eliminó en '.$peer.'.'
            : 'En '.$peer.' la frase no existía (nada que borrar).';
    }

    public function urlDestino(): string
    {
        $explicit = trim((string) config('cotiz.api_palabra_clave.url', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $usuarioUrl = trim((string) config('cotiz.api_usuario.url', ''));
        if ($usuarioUrl === '') {
            return '';
        }

        $derived = preg_replace('#/usuario/?$#i', '/palabra-clave', $usuarioUrl);

        return is_string($derived) ? $derived : '';
    }

    private function normalizarFrase(string $frase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $frase) ?? $frase);
    }

    private function mensajeErrorHttp(Response $response, string $destino, string $peer): string
    {
        $data = $response->json();
        if (is_array($data)) {
            $mensaje = trim((string) ($data['mensaje'] ?? ''));
            if ($mensaje !== '') {
                return $peer.': '.$mensaje;
            }
        }

        return match ($response->status()) {
            401 => $peer.': autorización rechazada (401). Verifique Basic Auth compartida.',
            404 => $peer.': ruta no encontrada (404). URL: '.$destino,
            default => $peer.': error HTTP '.$response->status().' al llamar '.$destino.'.',
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

        return $host !== '' ? $host : 'la otra instancia';
    }
}
