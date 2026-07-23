<?php

namespace App\Services;

use App\Models\OrganismoObservacion;
use App\Support\CotizInstanciaPar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OrganismoObservacionRelayService
{
    public function __construct(
        protected CompraAgilTextoParserService $parser,
    ) {}

    /**
     * Replica observación de administrador al sitio par.
     */
    public function replicarAdmin(OrganismoObservacion $org): string
    {
        return $this->enviar($org, ['admin']);
    }

    /**
     * Replica perfil automático al sitio par (tras análisis semanal).
     */
    public function replicarAutomatico(OrganismoObservacion $org): string
    {
        return $this->enviar($org, ['auto']);
    }

    /**
     * Empuja todos los registros con datos hacia el par (admin y/o automático).
     *
     * @return array{ok: int, fail: int}
     */
    public function empujarTodos(): array
    {
        $ok = 0;
        $fail = 0;

        OrganismoObservacion::query()
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use (&$ok, &$fail) {
                foreach ($chunk as $org) {
                    $campos = [];
                    if ($org->tieneObservacion()) {
                        $campos[] = 'admin';
                    }
                    if ($org->tieneObservacionAutomatica()) {
                        $campos[] = 'auto';
                    }
                    if ($campos === []) {
                        // Igual sincroniza ficha (rut/nombre) si existe fila.
                        $campos = ['admin'];
                    }
                    try {
                        $this->enviar($org, $campos);
                        $ok++;
                    } catch (\Throwable $e) {
                        $fail++;
                        Log::warning('organismo_observacion.relay_fail', [
                            'rut' => $org->rut_organismo,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['ok' => $ok, 'fail' => $fail];
    }

    /**
     * Aplica payload recibido del par (sin reenviar).
     *
     * @param  array<string, mixed>  $payload
     * @return array{created: bool, rut_organismo: string}
     */
    public function recibir(array $payload): array
    {
        $rut = $this->parser->normalizarRut((string) ($payload['rut_organismo'] ?? ''));
        if ($rut === '') {
            throw new RuntimeException('no viene rut_organismo');
        }

        $campos = $payload['campos'] ?? ['admin'];
        if (! is_array($campos) || $campos === []) {
            $campos = ['admin'];
        }
        $campos = array_values(array_intersect($campos, ['admin', 'auto']));
        if ($campos === []) {
            $campos = ['admin'];
        }

        $org = OrganismoObservacion::query()->firstOrNew(['rut_organismo' => $rut]);
        $created = ! $org->exists;

        $nombre = trim((string) ($payload['nombre'] ?? ''));
        if ($nombre !== '') {
            $org->nombre = mb_substr($nombre, 0, 200);
        }

        if (in_array('admin', $campos, true)) {
            $obs = array_key_exists('observacion', $payload)
                ? trim((string) $payload['observacion'])
                : null;
            $org->observacion = ($obs !== null && $obs !== '') ? $obs : null;
        }

        if (in_array('auto', $campos, true)) {
            $auto = array_key_exists('observacion_automatica', $payload)
                ? trim((string) $payload['observacion_automatica'])
                : null;
            $org->observacion_automatica = ($auto !== null && $auto !== '') ? $auto : null;
            $casos = $payload['observacion_automatica_casos'] ?? null;
            $org->observacion_automatica_casos = is_numeric($casos) ? (int) $casos : null;
            $en = trim((string) ($payload['observacion_automatica_en'] ?? ''));
            $org->observacion_automatica_en = $en !== '' ? $en : null;
        }

        $org->save();

        return [
            'created' => $created,
            'rut_organismo' => $rut,
        ];
    }

    /**
     * @param  list<string>  $campos
     */
    private function enviar(OrganismoObservacion $org, array $campos): string
    {
        $destino = $this->urlPar();
        if ($destino === '') {
            throw new RuntimeException(
                'COTIZ_API_USUARIO_URL no está configurada; no se puede sincronizar organismos con el sitio par.'
            );
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
            'accion' => 'graba',
            'replicacion' => true,
            'origen_sistema' => (string) config('cotiz.sistema', config('app.name')),
            'rut_organismo' => $org->rut_organismo,
            'nombre' => $org->nombre,
            'campos' => $campos,
        ];

        if (in_array('admin', $campos, true)) {
            $payload['observacion'] = $org->observacion;
        }
        if (in_array('auto', $campos, true)) {
            $payload['observacion_automatica'] = $org->observacion_automatica;
            $payload['observacion_automatica_casos'] = $org->observacion_automatica_casos;
            $payload['observacion_automatica_en'] = $org->observacion_automatica_en?->toIso8601String();
        }

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

        return 'También sincronizado en '.$peer.'.';
    }

    private function urlPar(): string
    {
        $explicit = trim((string) config('cotiz.api_organismo_observacion.url', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $usuarioUrl = trim((string) config('cotiz.api_usuario.url', ''));
        if ($usuarioUrl === '') {
            $base = CotizInstanciaPar::basePar();

            return $base ? rtrim($base, '/').'/api/v1/organismo-observacion' : '';
        }

        if (str_ends_with($usuarioUrl, '/usuario')) {
            return substr($usuarioUrl, 0, -strlen('/usuario')).'/organismo-observacion';
        }

        return rtrim($usuarioUrl, '/').'/organismo-observacion';
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
}
