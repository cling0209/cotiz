<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadEncontradaSyncPendiente;
use App\Models\OportunidadTomada;
use App\Support\CotizInstanciaPar;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OportunidadEncontradaRelayService
{
    public const ACCION_GRABA = 'graba';

    public const ACCION_VINCULO = 'vinculo';

    public const ACCION_TOMADA = 'tomada';

    private const CACHE_ULTIMO_OK_PREFIX = 'oportunidad_sync_par.ultimo_ok.';

    /**
     * Replica oportunidades encontradas al sitio par (Romulo ↔ Reicol).
     * No lanza: encola si el par no responde.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  self::ACCION_GRABA|self::ACCION_VINCULO  $colaAccion  Tipo de sync en cola local (el POST al par sigue siendo graba).
     */
    public function replicarItems(array $items, string $colaAccion = self::ACCION_GRABA): void
    {
        $items = $this->normalizarItems($items);
        if ($items === [] || $this->urlDestino() === '') {
            return;
        }

        $colaAccion = $this->normalizarColaAccion($colaAccion);

        try {
            $this->enviar($items, true, $colaAccion);
            $this->registrarUltimoOk($colaAccion, count($items));
        } catch (\Throwable $e) {
            Log::warning('Sync oportunidad encontrada al par falló (queda pendiente)', [
                'error' => $e->getMessage(),
                'count' => count($items),
                'cola_accion' => $colaAccion,
            ]);
        }
    }

    /**
     * Despierta el par y reintenta pendientes.
     *
     * @return array{ok: bool, pendientes_ok: int, pendientes_fail: int, mensaje: string}
     */
    public function sincronizarPendientes(bool $despertar = true): array
    {
        if ($this->urlDestino() === '') {
            return [
                'ok' => false,
                'pendientes_ok' => 0,
                'pendientes_fail' => 0,
                'mensaje' => 'Sin URL del sitio par configurada.',
            ];
        }

        if ($despertar) {
            $this->despertarSitioPar();
        }

        $pendientesOk = 0;
        $pendientesFail = 0;

        $pendientes = OportunidadEncontradaSyncPendiente::query()
            ->orderBy('id')
            ->get();

        foreach ($pendientes as $pendiente) {
            $payload = is_array($pendiente->payload) ? $pendiente->payload : [];
            try {
                if ($pendiente->accion === self::ACCION_TOMADA || $pendiente->accion === 'tomada') {
                    $this->enviarTomada(
                        (string) ($payload['codigo'] ?? ''),
                        (string) ($payload['usuario'] ?? ''),
                        (string) ($payload['sistema'] ?? ''),
                        false,
                    );
                } else {
                    $items = $this->normalizarItems($payload);
                    $colaAccion = $this->colaAccionDesdePendiente($pendiente);
                    $this->enviar($items, false, $colaAccion);
                    $this->registrarUltimoOk($colaAccion, count($items));
                }
                $pendiente->delete();
                $pendientesOk++;
            } catch (\Throwable $e) {
                $pendientesFail++;
                $pendiente->intentos = (int) $pendiente->intentos + 1;
                $pendiente->ultimo_error = mb_substr($e->getMessage(), 0, 1000);
                $pendiente->save();
                Log::warning('Sync oportunidad encontrada pendiente falló', [
                    'id' => $pendiente->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ok = $pendientesFail === 0;
        $peer = $this->nombreInstanciaPar($this->urlDestino());

        return [
            'ok' => $ok,
            'pendientes_ok' => $pendientesOk,
            'pendientes_fail' => $pendientesFail,
            'mensaje' => $ok
                ? 'Sincronización de oportunidades con '.$peer.' OK (pendientes: '.$pendientesOk.').'
                : 'Sincronización parcial con '.$peer.' (fallos: '.$pendientesFail.').',
        ];
    }

    /**
     * Tras terminar búsqueda o vinculación: si hay pendientes, despierta el par,
     * espera el cold start y reenvía (encontradas + resultados de vinculación).
     *
     * @return array{ok: bool, pendientes_ok: int, pendientes_fail: int, mensaje: string}
     */
    public function sincronizarPendientesTrasProceso(string $origen = ''): array
    {
        if ($this->urlDestino() === '') {
            return [
                'ok' => true,
                'pendientes_ok' => 0,
                'pendientes_fail' => 0,
                'mensaje' => 'Sin URL del sitio par; sync omitido.',
            ];
        }

        if (! OportunidadEncontradaSyncPendiente::query()->exists()) {
            return [
                'ok' => true,
                'pendientes_ok' => 0,
                'pendientes_fail' => 0,
                'mensaje' => 'Sin pendientes de sync al par.',
            ];
        }

        $etiqueta = trim($origen) !== '' ? trim($origen) : 'proceso';
        Log::info('Sync oportunidades al par tras '.$etiqueta.': despertando sitio par…');

        $this->despertarSitioPar();

        $espera = max(0, (int) config('cotiz.api_oportunidad_encontrada.sync_wake_espera_seg', 25));
        if ($espera > 0) {
            sleep($espera);
        }

        $resultado = $this->sincronizarPendientes(despertar: false);
        Log::info('Sync oportunidades al par tras '.$etiqueta, [
            'ok' => $resultado['ok'],
            'pendientes_ok' => $resultado['pendientes_ok'],
            'pendientes_fail' => $resultado['pendientes_fail'],
        ]);

        return $resultado;
    }

    /**
     * Aplica recepción remota. Idempotente. No vuelve a relay.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{ok: bool, recibidos: int}
     */
    public function recibir(array $items): array
    {
        $items = $this->normalizarItems($items);
        $recibidos = 0;

        foreach ($items as $item) {
            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }

            // Zona fuera del área de operación (ej. Isla de Pascua): no registrar.
            if (CompraAgilRegionScope::debeExcluirResumen($item)) {
                continue;
            }

            if (OportunidadTomada::query()->where('codigo', $codigo)->exists()) {
                continue;
            }

            $dia = $this->fechaBusquedaItem($item);
            $palabras = array_values(array_unique(array_filter(array_map(
                static fn ($p) => trim((string) $p),
                is_array($item['palabras_coinciden'] ?? null) ? $item['palabras_coinciden'] : [],
            ))));

            $existente = OportunidadEncontrada::query()
                ->where('codigo', $codigo)
                ->whereDate('fecha_busqueda', $dia)
                ->first();

            $attrs = $this->atributosDesdeItem($item, $dia, $palabras);

            if ($existente !== null) {
                $prev = is_array($existente->palabras_coinciden) ? $existente->palabras_coinciden : [];
                $attrs['palabras_coinciden'] = array_values(array_unique(array_merge($prev, $palabras)));
                if (($attrs['cantidad_productos'] ?? null) === null && $existente->cantidad_productos !== null) {
                    unset($attrs['cantidad_productos']);
                }
                $existente->fill($attrs);
                $existente->save();
            } else {
                OportunidadEncontrada::query()->create($attrs);
            }

            $recibidos++;
        }

        return ['ok' => true, 'recibidos' => $recibidos];
    }

    /**
     * Reserva exclusiva del código CA: candado local + peer.
     * Si el par no responde o ya está tomado, lanza y no deja continuar.
     */
    public function reservarExclusivo(string $codigo, ?string $usuario = null): void
    {
        $codigo = $this->normalizarCodigo($codigo);
        if ($codigo === '') {
            return;
        }

        $sistema = (string) config('cotiz.sistema', config('app.name'));
        $usuarioNorm = $usuario !== null ? trim($usuario) : '';

        if ($this->codigoTomadoPorNotaLocal($codigo)) {
            throw new RuntimeException(
                'La cotización «'.$codigo.'» ya está tomada en este sitio.'
            );
        }

        $creadaLocal = false;
        $existente = OportunidadTomada::query()->where('codigo', $codigo)->first();

        if ($existente !== null) {
            $sistemaExistente = trim((string) ($existente->sistema ?? ''));
            if ($sistemaExistente !== '' && strcasecmp($sistemaExistente, $sistema) !== 0) {
                throw new RuntimeException(
                    'La cotización «'.$codigo.'» ya fue tomada'
                    .($sistemaExistente !== '' ? ' en '.$sistemaExistente : '').'.'
                );
            }

            // Ya reservado por este sitio: reconfirmar en el par (idempotente).
            $existente->fill([
                'sistema' => $sistema,
                'usuario' => $usuarioNorm !== '' ? $usuarioNorm : $existente->usuario,
                'tomada_at' => now(),
            ]);
            $existente->save();
        } else {
            try {
                OportunidadTomada::query()->create([
                    'codigo' => $codigo,
                    'sistema' => $sistema,
                    'usuario' => $usuarioNorm !== '' ? $usuarioNorm : null,
                    'tomada_at' => now(),
                ]);
                $creadaLocal = true;
            } catch (QueryException) {
                throw new RuntimeException(
                    'La cotización «'.$codigo.'» ya está tomada.'
                );
            }
        }

        if ($this->urlDestino() === '') {
            return;
        }

        try {
            // Bloqueante: no encolar. Si el par falla, no se permite tomar.
            $this->enviarTomada($codigo, $usuarioNorm, $sistema, false);
        } catch (\Throwable $e) {
            if ($creadaLocal) {
                OportunidadTomada::query()->where('codigo', $codigo)->delete();
            }

            throw new RuntimeException(
                'No se pudo reservar «'.$codigo.'» en el sitio par. '.$e->getMessage()
            );
        }
    }

    /**
     * Reserva remota atómica. Idempotente solo si el origen es el mismo sistema.
     *
     * @return array{ok: bool, codigo: string, created: bool}
     */
    public function recibirTomada(string $codigo, ?string $usuario = null, ?string $sistema = null): array
    {
        $codigo = $this->normalizarCodigo($codigo);
        if ($codigo === '') {
            throw new RuntimeException('codigo inválido');
        }

        $origen = trim((string) ($sistema ?? ''));
        $usuarioNorm = $usuario !== null ? trim($usuario) : '';

        if ($this->codigoTomadoPorNotaLocal($codigo)) {
            throw new RuntimeException(
                'La cotización «'.$codigo.'» ya existe en este sitio.'
            );
        }

        $existente = OportunidadTomada::query()->where('codigo', $codigo)->first();
        if ($existente !== null) {
            $sistemaExistente = trim((string) ($existente->sistema ?? ''));
            if (
                $origen !== ''
                && $sistemaExistente !== ''
                && strcasecmp($sistemaExistente, $origen) === 0
            ) {
                return ['ok' => true, 'codigo' => $codigo, 'created' => false];
            }

            throw new RuntimeException(
                'La cotización «'.$codigo.'» ya fue tomada'
                .($sistemaExistente !== '' ? ' en '.$sistemaExistente : '').'.'
            );
        }

        try {
            OportunidadTomada::query()->create([
                'codigo' => $codigo,
                'sistema' => $origen !== '' ? $origen : null,
                'usuario' => $usuarioNorm !== '' ? $usuarioNorm : null,
                'tomada_at' => now(),
            ]);
        } catch (QueryException) {
            throw new RuntimeException(
                'La cotización «'.$codigo.'» ya está tomada.'
            );
        }

        return ['ok' => true, 'codigo' => $codigo, 'created' => true];
    }

    /**
     * Resumen de sync al par (solo útil en sitio ANALISIS_ADMIN).
     *
     * @return array{
     *   habilitado: bool,
     *   peer: string,
     *   url_configurada: bool,
     *   cotizaciones: array<string, mixed>,
     *   vinculaciones: array<string, mixed>
     * }
     */
    public function resumenSyncPar(): array
    {
        $habilitado = (bool) config('cotiz.mercadopublico.analisis_admin_habilitado', false);
        $destino = $this->urlDestino();
        $peer = $destino !== '' ? $this->nombreInstanciaPar($destino) : CotizInstanciaPar::nombrePar();

        return [
            'habilitado' => $habilitado,
            'peer' => $peer,
            'url_configurada' => $destino !== '',
            'cotizaciones' => $this->resumenColaSync(self::ACCION_GRABA),
            'vinculaciones' => $this->resumenColaSync(self::ACCION_VINCULO),
        ];
    }

    /**
     * Reintenta solo pendientes de cotizaciones (graba) o de vinculaciones (vinculo).
     *
     * @param  self::ACCION_GRABA|self::ACCION_VINCULO|'all'  $tipo
     * @return array{ok: bool, pendientes_ok: int, pendientes_fail: int, mensaje: string, sync_par: array<string, mixed>}
     */
    public function sincronizarPendientesPorTipo(string $tipo = 'all', bool $despertar = true): array
    {
        $tipo = strtolower(trim($tipo));
        if (! in_array($tipo, ['all', self::ACCION_GRABA, 'cotizaciones', self::ACCION_VINCULO, 'vinculaciones'], true)) {
            throw new RuntimeException('Tipo de sync inválido. Use cotizaciones, vinculaciones o all.');
        }

        if ($tipo === 'cotizaciones') {
            $tipo = self::ACCION_GRABA;
        } elseif ($tipo === 'vinculaciones') {
            $tipo = self::ACCION_VINCULO;
        }

        if ($this->urlDestino() === '') {
            return [
                'ok' => false,
                'pendientes_ok' => 0,
                'pendientes_fail' => 0,
                'mensaje' => 'Sin URL del sitio par configurada.',
                'sync_par' => $this->resumenSyncPar(),
            ];
        }

        if ($despertar) {
            $this->despertarSitioPar();
        }

        $pendientesOk = 0;
        $pendientesFail = 0;

        $pendientes = OportunidadEncontradaSyncPendiente::query()
            ->orderBy('id')
            ->get()
            ->filter(function (OportunidadEncontradaSyncPendiente $pendiente) use ($tipo) {
                if ($tipo === 'all') {
                    return in_array($this->colaAccionDesdePendiente($pendiente), [self::ACCION_GRABA, self::ACCION_VINCULO], true)
                        || $pendiente->accion === self::ACCION_TOMADA;
                }

                return $this->colaAccionDesdePendiente($pendiente) === $tipo;
            });

        foreach ($pendientes as $pendiente) {
            $payload = is_array($pendiente->payload) ? $pendiente->payload : [];
            try {
                if ($pendiente->accion === self::ACCION_TOMADA) {
                    $this->enviarTomada(
                        (string) ($payload['codigo'] ?? ''),
                        (string) ($payload['usuario'] ?? ''),
                        (string) ($payload['sistema'] ?? ''),
                        false,
                    );
                } else {
                    $items = $this->normalizarItems($payload);
                    $colaAccion = $this->colaAccionDesdePendiente($pendiente);
                    $this->enviar($items, false, $colaAccion);
                    $this->registrarUltimoOk($colaAccion, count($items));
                }
                $pendiente->delete();
                $pendientesOk++;
            } catch (\Throwable $e) {
                $pendiente->intentos = (int) $pendiente->intentos + 1;
                $pendiente->ultimo_error = mb_substr($e->getMessage(), 0, 1000);
                $pendiente->save();
                $pendientesFail++;
            }
        }

        $peer = $this->nombreInstanciaPar($this->urlDestino());
        $ok = $pendientesFail === 0;
        $etiqueta = $tipo === self::ACCION_VINCULO
            ? 'vinculaciones'
            : ($tipo === self::ACCION_GRABA ? 'cotizaciones' : 'sync');

        return [
            'ok' => $ok,
            'pendientes_ok' => $pendientesOk,
            'pendientes_fail' => $pendientesFail,
            'mensaje' => $ok
                ? 'Sincronización de '.$etiqueta.' con '.$peer.' OK (enviados: '.$pendientesOk.').'
                : 'Sincronización de '.$etiqueta.' parcial con '.$peer.' (fallos: '.$pendientesFail.').',
            'sync_par' => $this->resumenSyncPar(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  bool  $encolarSiFalla
     * @param  self::ACCION_GRABA|self::ACCION_VINCULO  $colaAccion
     */
    private function enviar(array $items, bool $encolarSiFalla, string $colaAccion = self::ACCION_GRABA): void
    {
        $destino = $this->urlDestino();
        if ($destino === '') {
            throw new RuntimeException(
                'No hay URL del sitio par para oportunidades. Configure COTIZ_API_USUARIO_URL '
                .'(o COTIZ_API_OPORTUNIDAD_ENCONTRADA_URL) apuntando a la otra instancia.'
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
        $colaAccion = $this->normalizarColaAccion($colaAccion);

        $payload = [
            'accion' => self::ACCION_GRABA,
            'replicacion' => true,
            'origen_sistema' => (string) config('cotiz.sistema', config('app.name')),
            'items' => $items,
        ];

        try {
            $response = Http::timeout(45)
                ->asJson()
                ->withBasicAuth($userAuth, $passwordAuth)
                ->post($destino, $payload);
        } catch (\Throwable $e) {
            $mensaje = 'No se pudo conectar con '.$peer.' ('.$destino.'): '.$e->getMessage();
            if ($encolarSiFalla) {
                $this->encolarPendiente($items, $mensaje, $colaAccion);
            }
            throw new RuntimeException($mensaje);
        }

        if (! $response->successful()) {
            $mensaje = $this->mensajeErrorHttp($response, $destino, $peer);
            if ($encolarSiFalla) {
                $this->encolarPendiente($items, $mensaje, $colaAccion);
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
                $this->encolarPendiente($items, $mensaje, $colaAccion);
            }
            throw new RuntimeException($mensaje);
        }
    }

    private function enviarTomada(
        string $codigo,
        string $usuario,
        string $sistema,
        bool $encolarSiFalla,
    ): void {
        $codigo = $this->normalizarCodigo($codigo);
        $destino = $this->urlDestino();
        if ($codigo === '' || $destino === '') {
            return;
        }

        $userAuth = (string) config('cotiz.api_nota.user', '');
        $passwordAuth = (string) config('cotiz.api_nota.password', '');
        if ($userAuth === '' || $passwordAuth === '') {
            throw new RuntimeException(
                'Faltan COTIZ_API_NOTA_USER o COTIZ_API_NOTA_PASSWORD (Basic Auth compartida con la otra instancia).'
            );
        }

        $payload = [
            'accion' => 'tomada',
            'replicacion' => true,
            'origen_sistema' => $sistema,
            'codigo' => $codigo,
            'usuario' => $usuario,
        ];

        try {
            $response = Http::timeout(30)
                ->asJson()
                ->withBasicAuth($userAuth, $passwordAuth)
                ->post($destino, $payload);
        } catch (\Throwable $e) {
            $mensaje = 'No se pudo reservar en el sitio par ('.$codigo.'): '.$e->getMessage();
            if ($encolarSiFalla) {
                $this->encolarTomada($payload, $mensaje);
            }
            throw new RuntimeException($mensaje);
        }

        if (! $response->successful()) {
            $data = $response->json();
            $detalle = is_array($data) ? trim((string) ($data['mensaje'] ?? '')) : '';
            $mensaje = $detalle !== ''
                ? $detalle
                : $this->mensajeErrorHttp(
                    $response,
                    $destino,
                    $this->nombreInstanciaPar($destino),
                );
            if ($encolarSiFalla) {
                $this->encolarTomada($payload, $mensaje);
            }
            throw new RuntimeException($mensaje);
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data)
                ? trim((string) ($data['mensaje'] ?? 'Respuesta inválida del sitio par.'))
                : 'Respuesta inválida del sitio par.';
            if ($encolarSiFalla) {
                $this->encolarTomada($payload, $mensaje);
            }
            throw new RuntimeException($mensaje);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  self::ACCION_GRABA|self::ACCION_VINCULO  $colaAccion
     */
    public function encolarPendiente(array $items, ?string $error = null, string $colaAccion = self::ACCION_GRABA): void
    {
        $items = $this->normalizarItems($items);
        if ($items === []) {
            return;
        }

        OportunidadEncontradaSyncPendiente::query()->create([
            'accion' => $this->normalizarColaAccion($colaAccion),
            'payload' => $items,
            'intentos' => 0,
            'ultimo_error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encolarTomada(array $payload, ?string $error = null): void
    {
        $codigo = $this->normalizarCodigo((string) ($payload['codigo'] ?? ''));
        if ($codigo === '') {
            return;
        }

        $payload['codigo'] = $codigo;
        OportunidadEncontradaSyncPendiente::query()->create([
            'accion' => self::ACCION_TOMADA,
            'payload' => $payload,
            'intentos' => 0,
            'ultimo_error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);
    }

    /**
     * @return array{
     *   pendientes: int,
     *   codigos: list<string>,
     *   ultimo_error: string|null,
     *   ultimo_ok_at: string|null,
     *   ultimo_ok_count: int|null,
     *   lotes: list<array{
     *     id: int,
     *     intentos: int,
     *     ultimo_error: string|null,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     codigos: list<string>,
     *     items: int
     *   }>
     * }
     */
    private function resumenColaSync(string $colaAccion): array
    {
        $colaAccion = $this->normalizarColaAccion($colaAccion);
        $pendientes = OportunidadEncontradaSyncPendiente::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (OportunidadEncontradaSyncPendiente $p) => $this->colaAccionDesdePendiente($p) === $colaAccion)
            ->values();

        $codigos = [];
        $ultimoError = null;
        $lotes = [];
        foreach ($pendientes as $pendiente) {
            $payload = is_array($pendiente->payload) ? $pendiente->payload : [];
            $items = $this->normalizarItems($payload);
            $codigosLote = [];
            foreach ($items as $item) {
                $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                if ($codigo === '') {
                    continue;
                }
                if (! in_array($codigo, $codigos, true)) {
                    $codigos[] = $codigo;
                }
                if (! in_array($codigo, $codigosLote, true)) {
                    $codigosLote[] = $codigo;
                }
            }
            $err = trim((string) ($pendiente->ultimo_error ?? ''));
            if ($err !== '') {
                $ultimoError = $err;
            }
            $lotes[] = [
                'id' => (int) $pendiente->id,
                'intentos' => (int) $pendiente->intentos,
                'ultimo_error' => $err !== '' ? $err : null,
                'created_at' => $pendiente->created_at?->toIso8601String(),
                'updated_at' => $pendiente->updated_at?->toIso8601String(),
                'codigos' => $codigosLote,
                'items' => count($items),
            ];
        }

        $ultimoOk = Cache::get(self::CACHE_ULTIMO_OK_PREFIX.$colaAccion);

        return [
            'pendientes' => $pendientes->count(),
            'codigos' => array_slice($codigos, 0, 40),
            'ultimo_error' => $ultimoError,
            'ultimo_ok_at' => is_array($ultimoOk) ? ($ultimoOk['at'] ?? null) : null,
            'ultimo_ok_count' => is_array($ultimoOk) && isset($ultimoOk['count'])
                ? (int) $ultimoOk['count']
                : null,
            'lotes' => $lotes,
        ];
    }

    private function colaAccionDesdePendiente(OportunidadEncontradaSyncPendiente $pendiente): string
    {
        $accion = strtolower(trim((string) ($pendiente->accion ?? '')));
        if ($accion === self::ACCION_VINCULO) {
            return self::ACCION_VINCULO;
        }
        if ($accion === self::ACCION_TOMADA || $accion === 'tomada') {
            return self::ACCION_TOMADA;
        }

        // Compat: pendientes viejos con accion=graba pero payload ya vinculado.
        $payload = is_array($pendiente->payload) ? $pendiente->payload : [];
        $items = $this->normalizarItems($payload);
        if ($items !== [] && $this->itemsSonVinculo($items)) {
            return self::ACCION_VINCULO;
        }

        return self::ACCION_GRABA;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemsSonVinculo(array $items): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (! (bool) ($item['vinculo_completo'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function normalizarColaAccion(string $colaAccion): string
    {
        $colaAccion = strtolower(trim($colaAccion));

        return $colaAccion === self::ACCION_VINCULO
            ? self::ACCION_VINCULO
            : self::ACCION_GRABA;
    }

    private function registrarUltimoOk(string $colaAccion, int $count): void
    {
        $colaAccion = $this->normalizarColaAccion($colaAccion);
        Cache::put(self::CACHE_ULTIMO_OK_PREFIX.$colaAccion, [
            'at' => now()->toIso8601String(),
            'count' => max(0, $count),
        ], now()->addDays(7));
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
            Log::info('Wake sitio par (/up) para sync oportunidades: sin respuesta aún', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function urlDestino(): string
    {
        $explicit = trim((string) config('cotiz.api_oportunidad_encontrada.url', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $usuarioUrl = trim((string) config('cotiz.api_usuario.url', ''));
        if ($usuarioUrl === '') {
            return '';
        }

        $derived = preg_replace('#/usuario/?$#i', '/oportunidad-encontrada', $usuarioUrl);

        return is_string($derived) ? $derived : '';
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizarItems(array $items): array
    {
        $out = [];
        $vistos = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }
            $dia = $this->fechaBusquedaItem($item);
            $key = $codigo.'|'.$dia;
            if (isset($vistos[$key])) {
                continue;
            }
            $vistos[$key] = true;

            $palabras = array_values(array_unique(array_filter(array_map(
                static fn ($p) => trim((string) $p),
                is_array($item['palabras_coinciden'] ?? null) ? $item['palabras_coinciden'] : [],
            ))));

            $out[] = [
                'codigo' => $codigo,
                'nombre' => (string) ($item['nombre'] ?? ''),
                'organismo' => (string) ($item['organismo'] ?? ''),
                'rut_organismo' => (string) ($item['rut_organismo'] ?? ''),
                'region' => isset($item['region']) ? (int) $item['region'] : null,
                'nombre_region' => (string) ($item['nombre_region'] ?? ''),
                'comuna' => (string) ($item['comuna'] ?? ''),
                'direccion' => (string) ($item['direccion'] ?? $item['direccion_entrega'] ?? ''),
                'monto_presupuesto_clp' => isset($item['monto_presupuesto_clp'])
                    ? (int) $item['monto_presupuesto_clp']
                    : null,
                'moneda' => (string) ($item['moneda'] ?? 'CLP'),
                'fecha_publicacion' => $item['fecha_publicacion'] ?? null,
                'fecha_cierre' => $item['fecha_cierre'] ?? null,
                'estado_codigo' => (string) ($item['estado_codigo'] ?? ''),
                'estado_glosa' => (string) ($item['estado_glosa'] ?? ''),
                'palabras_coinciden' => $palabras,
                'cantidad_productos' => isset($item['cantidad_productos'])
                    ? (int) $item['cantidad_productos']
                    : null,
                'vinculo_completo' => (bool) ($item['vinculo_completo'] ?? false),
                'productos_vinculados' => isset($item['productos_vinculados'])
                    ? (int) $item['productos_vinculados']
                    : null,
                'porcentaje_vinculo' => isset($item['porcentaje_vinculo'])
                    ? (int) $item['porcentaje_vinculo']
                    : null,
                'fecha_busqueda' => $dia,
                'indice_region_config' => (int) ($item['indice_region_config'] ?? 999),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function fechaBusquedaItem(array $item): string
    {
        $raw = trim((string) ($item['fecha_busqueda'] ?? ''));
        if ($raw !== '') {
            try {
                return Carbon::parse($raw)->toDateString();
            } catch (\Throwable) {
                // fallback hoy
            }
        }

        return now()->timezone(config('app.timezone'))->toDateString();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $palabras
     * @return array<string, mixed>
     */
    private function atributosDesdeItem(array $item, string $dia, array $palabras): array
    {
        $region = isset($item['region']) ? (int) $item['region'] : null;

        $attrs = [
            'codigo' => strtoupper(trim((string) ($item['codigo'] ?? ''))),
            'nombre' => mb_substr(trim((string) ($item['nombre'] ?? '')), 0, 500) ?: null,
            'organismo' => mb_substr(trim((string) ($item['organismo'] ?? '')), 0, 500) ?: null,
            'rut_organismo' => mb_substr(trim((string) ($item['rut_organismo'] ?? '')), 0, 20) ?: null,
            'region' => $region,
            'nombre_region' => mb_substr(trim((string) ($item['nombre_region'] ?? '')), 0, 100) ?: null,
            'comuna' => mb_substr(trim((string) ($item['comuna'] ?? '')), 0, 120) ?: null,
            'direccion' => mb_substr(trim((string) ($item['direccion'] ?? $item['direccion_entrega'] ?? '')), 0, 255) ?: null,
            'monto_presupuesto_clp' => isset($item['monto_presupuesto_clp'])
                ? (int) $item['monto_presupuesto_clp']
                : null,
            'moneda' => mb_substr(trim((string) ($item['moneda'] ?? 'CLP')), 0, 10) ?: 'CLP',
            'fecha_publicacion' => $this->parseFechaNullable($item['fecha_publicacion'] ?? null),
            'fecha_cierre' => $this->parseFechaNullable($item['fecha_cierre'] ?? null),
            'estado_codigo' => mb_substr(trim((string) ($item['estado_codigo'] ?? '')), 0, 40) ?: null,
            'estado_glosa' => mb_substr(trim((string) ($item['estado_glosa'] ?? '')), 0, 120) ?: null,
            'palabras_coinciden' => $palabras,
            'cantidad_productos' => isset($item['cantidad_productos'])
                ? (int) $item['cantidad_productos']
                : null,
            'fecha_busqueda' => $dia,
            'indice_region_config' => (int) ($item['indice_region_config'] ?? 999),
            'found_by' => null,
        ];

        if (array_key_exists('vinculo_completo', $item)) {
            $attrs['vinculo_completo'] = (bool) $item['vinculo_completo'];
            $attrs['productos_vinculados'] = isset($item['productos_vinculados'])
                ? (int) $item['productos_vinculados']
                : null;
            $attrs['porcentaje_vinculo'] = isset($item['porcentaje_vinculo'])
                ? (int) $item['porcentaje_vinculo']
                : null;
        }

        return $attrs;
    }

    private function parseFechaNullable(mixed $valor): ?Carbon
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizarCodigo(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));

        return preg_match('/^\d+-\d+-COT\d+$/', $codigo) === 1 ? $codigo : '';
    }

    private function codigoTomadoPorNotaLocal(string $codigo): bool
    {
        return Nota::query()
            ->whereRaw('upper(trim(encargado)) = ?', [$codigo])
            ->exists();
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
