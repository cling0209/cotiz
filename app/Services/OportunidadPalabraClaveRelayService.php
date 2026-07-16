<?php

namespace App\Services;

use App\Models\OportunidadPalabraClave;
use App\Models\OportunidadPalabraClaveSyncPendiente;
use App\Support\CotizInstanciaPar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Replica el orden de prioridad de búsqueda al sitio par.
     *
     * @param  list<string>  $frases  frases en orden de prioridad (1.º = más prioritaria)
     */
    public function replicarOrden(array $frases): string
    {
        return $this->enviarReordenar($this->normalizarListaFrases($frases));
    }

    /**
     * Guarda operación para reintentar cuando el sitio par despierte.
     *
     * @param  list<string>|null  $frasesOrden  solo para accion reordenar
     */
    public function encolarPendiente(string $accion, string $frase, ?string $error = null, ?array $frasesOrden = null): void
    {
        if (! in_array($accion, ['graba', 'elimina', 'reordenar'], true)) {
            return;
        }

        if ($accion === 'reordenar') {
            $frases = $this->normalizarListaFrases($frasesOrden ?? []);
            if ($frases === []) {
                return;
            }

            OportunidadPalabraClaveSyncPendiente::query()
                ->where(function ($q) {
                    $q->where('accion', 'reordenar')
                        ->orWhere('frase', OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN);
                })
                ->delete();

            OportunidadPalabraClaveSyncPendiente::query()->create([
                'accion' => 'reordenar',
                'frase' => OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN,
                'payload' => json_encode($frases, JSON_UNESCAPED_UNICODE),
                'intentos' => 0,
                'ultimo_error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            ]);

            return;
        }

        $frase = $this->normalizarFrase($frase);
        if ($frase === '') {
            return;
        }

        // graba y elimina de la misma frase se anulan: deja solo la última intención.
        OportunidadPalabraClaveSyncPendiente::query()
            ->where('frase', $frase)
            ->delete();

        OportunidadPalabraClaveSyncPendiente::query()->create([
            'accion' => $accion,
            'frase' => $frase,
            'payload' => null,
            'intentos' => 0,
            'ultimo_error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);
    }

    /**
     * Despierta el par (/up), reintenta pendientes y empuja frases + orden locales.
     *
     * @return array{ok: bool, pendientes_ok: int, pendientes_fail: int, push_ok: int, push_fail: int, mensaje: string}
     */
    public function sincronizarConPar(bool $despertar = true, bool $pushTodas = true): array
    {
        if ($this->urlDestino() === '') {
            return [
                'ok' => false,
                'pendientes_ok' => 0,
                'pendientes_fail' => 0,
                'push_ok' => 0,
                'push_fail' => 0,
                'mensaje' => 'Sin URL del sitio par configurada.',
            ];
        }

        if ($despertar) {
            $this->despertarSitioPar();
        }

        $pendientesOk = 0;
        $pendientesFail = 0;

        $pendientes = OportunidadPalabraClaveSyncPendiente::query()
            ->orderBy('id')
            ->get();

        foreach ($pendientes as $pendiente) {
            try {
                if ($pendiente->accion === 'reordenar') {
                    $frases = json_decode((string) ($pendiente->payload ?? '[]'), true);
                    $this->enviarReordenar(is_array($frases) ? $frases : [], false);
                } else {
                    $this->enviar($pendiente->accion, $pendiente->frase, false);
                }
                $pendiente->delete();
                $pendientesOk++;
            } catch (\Throwable $e) {
                $pendientesFail++;
                $pendiente->intentos = (int) $pendiente->intentos + 1;
                $pendiente->ultimo_error = mb_substr($e->getMessage(), 0, 1000);
                $pendiente->save();
                Log::warning('Sync palabra clave pendiente falló', [
                    'accion' => $pendiente->accion,
                    'frase' => $pendiente->frase,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $pushOk = 0;
        $pushFail = 0;

        if ($pushTodas) {
            $frases = OportunidadPalabraClave::query()
                ->orderBy('orden')
                ->orderBy('id')
                ->pluck('frase')
                ->map(fn ($f) => (string) $f)
                ->all();

            foreach ($frases as $frase) {
                try {
                    $this->enviar('graba', $frase, false);
                    $pushOk++;
                } catch (\Throwable $e) {
                    $pushFail++;
                    $this->encolarPendiente('graba', $frase, $e->getMessage());
                }
            }

            if ($frases !== []) {
                try {
                    $this->enviarReordenar($frases, false);
                    $pushOk++;
                } catch (\Throwable $e) {
                    $pushFail++;
                    $this->encolarPendiente('reordenar', OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN, $e->getMessage(), $frases);
                }
            }
        }

        $ok = $pendientesFail === 0 && $pushFail === 0;
        $peer = $this->nombreInstanciaPar($this->urlDestino());

        return [
            'ok' => $ok,
            'pendientes_ok' => $pendientesOk,
            'pendientes_fail' => $pendientesFail,
            'push_ok' => $pushOk,
            'push_fail' => $pushFail,
            'mensaje' => $ok
                ? 'Sincronización con '.$peer.' OK (pendientes: '.$pendientesOk.', push: '.$pushOk.').'
                : 'Sincronización parcial con '.$peer.' (fallos pendientes: '.$pendientesFail.', push: '.$pushFail.').',
        ];
    }

    /**
     * Aplica recepción remota. Idempotente. No vuelve a relay (evita bucle).
     *
     * @param  list<string>|null  $frases
     * @return array{ok: bool, created?: bool, deleted?: bool, actualizados?: int, frase: string}
     */
    public function recibir(string $accion, string $frase = '', ?array $frases = null): array
    {
        if ($accion === 'reordenar') {
            return $this->recibirOrden($frases ?? []);
        }

        $frase = $this->normalizarFrase($frase);
        if ($frase === '') {
            throw new RuntimeException('frase inválida');
        }

        if ($accion === 'graba') {
            $existe = OportunidadPalabraClave::query()->where('frase', $frase)->exists();
            if ($existe) {
                return ['ok' => true, 'created' => false, 'frase' => $frase];
            }

            $maxOrden = (int) OportunidadPalabraClave::query()->max('orden');

            OportunidadPalabraClave::query()->create([
                'frase' => $frase,
                'orden' => $maxOrden + 1,
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

    /**
     * @param  list<string>  $frases
     * @return array{ok: bool, actualizados: int, frase: string}
     */
    public function recibirOrden(array $frases): array
    {
        $frases = $this->normalizarListaFrases($frases);
        if ($frases === []) {
            throw new RuntimeException('frases inválidas para reordenar');
        }

        $n = 1;
        $actualizados = 0;

        foreach ($frases as $frase) {
            $row = OportunidadPalabraClave::query()->where('frase', $frase)->first();
            if ($row === null) {
                continue;
            }
            if ((int) $row->orden !== $n) {
                $row->orden = $n;
                $row->save();
            }
            $actualizados++;
            $n++;
        }

        $resto = OportunidadPalabraClave::query()
            ->whereNotIn('frase', $frases)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        foreach ($resto as $row) {
            if ((int) $row->orden !== $n) {
                $row->orden = $n;
                $row->save();
            }
            $n++;
        }

        return [
            'ok' => true,
            'actualizados' => $actualizados,
            'frase' => OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN,
        ];
    }

    /**
     * @param  list<string>  $frases
     * @param  bool  $encolarSiFalla  Si true, encola al fallar (uso admin). Si false, solo lanza (uso sync).
     */
    private function enviarReordenar(array $frases, bool $encolarSiFalla = true): string
    {
        $frases = $this->normalizarListaFrases($frases);
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
            'accion' => 'reordenar',
            'replicacion' => true,
            'origen_sistema' => (string) config('cotiz.sistema', config('app.name')),
            'frases' => $frases,
            'frase' => OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN,
        ];

        try {
            $response = Http::timeout(30)
                ->asJson()
                ->withBasicAuth($userAuth, $passwordAuth)
                ->post($destino, $payload);
        } catch (\Throwable $e) {
            $mensaje = 'No se pudo conectar con '.$peer.' ('.$destino.'): '.$e->getMessage();
            if ($encolarSiFalla) {
                $this->encolarPendiente('reordenar', OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN, $mensaje, $frases);
            }
            throw new RuntimeException($mensaje);
        }

        if (! $response->successful()) {
            $mensaje = $this->mensajeErrorHttp($response, $destino, $peer);
            if ($encolarSiFalla) {
                $this->encolarPendiente('reordenar', OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN, $mensaje, $frases);
            }
            throw new RuntimeException($mensaje);
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $detalle = is_array($data) ? trim((string) ($data['mensaje'] ?? '')) : '';
            $mensaje = $detalle !== ''
                ? $peer.': '.$detalle
                : 'Respuesta inválida de '.$peer.'.';
            if ($encolarSiFalla) {
                $this->encolarPendiente('reordenar', OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN, $mensaje, $frases);
            }
            throw new RuntimeException($mensaje);
        }

        OportunidadPalabraClaveSyncPendiente::query()
            ->where(function ($q) {
                $q->where('accion', 'reordenar')
                    ->orWhere('frase', OportunidadPalabraClaveSyncPendiente::FRASE_ORDEN);
            })
            ->delete();

        return 'También se actualizó el orden en '.$peer.'.';
    }

    /**
     * @param  bool  $encolarSiFalla  Si true, encola al fallar (uso admin). Si false, solo lanza (uso sync).
     */
    private function enviar(string $accion, string $frase, bool $encolarSiFalla = true): string
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
            $mensaje = 'No se pudo conectar con '.$peer.' ('.$destino.'): '.$e->getMessage();
            if ($encolarSiFalla) {
                $this->encolarPendiente($accion, $frase, $mensaje);
            }
            throw new RuntimeException($mensaje);
        }

        if (! $response->successful()) {
            $mensaje = $this->mensajeErrorHttp($response, $destino, $peer);
            if ($encolarSiFalla) {
                $this->encolarPendiente($accion, $frase, $mensaje);
            }
            throw new RuntimeException($mensaje);
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $detalle = is_array($data) ? trim((string) ($data['mensaje'] ?? '')) : '';
            $mensaje = $detalle !== ''
                ? $peer.': '.$detalle
                : 'Respuesta inválida de '.$peer.'.';
            if ($encolarSiFalla) {
                $this->encolarPendiente($accion, $frase, $mensaje);
            }
            throw new RuntimeException($mensaje);
        }

        // Éxito: limpia pendiente opuesta/igual si existía.
        OportunidadPalabraClaveSyncPendiente::query()
            ->where('frase', $frase)
            ->delete();

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

    private function despertarSitioPar(): void
    {
        $url = CotizInstanciaPar::urlDespertarSitioPar();
        if ($url === '') {
            return;
        }

        try {
            Http::timeout(5)->get($url);
        } catch (\Throwable $e) {
            Log::info('Wake sitio par (/up) para sync palabras clave: sin respuesta aún', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
        }
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

    /**
     * @param  list<mixed>  $frases
     * @return list<string>
     */
    private function normalizarListaFrases(array $frases): array
    {
        $out = [];
        $vistos = [];
        foreach ($frases as $frase) {
            $norm = $this->normalizarFrase((string) $frase);
            if ($norm === '' || isset($vistos[$norm])) {
                continue;
            }
            $vistos[$norm] = true;
            $out[] = $norm;
        }

        return $out;
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
