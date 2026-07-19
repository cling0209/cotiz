<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Support\Facades\DB;

class NotaService
{
    private const PAR_VERIFICADO_TTL_SEGUNDOS = 300;

    public function __construct(
        protected OportunidadEncontradaRelayService $oportunidadRelay,
    ) {}

    public function crear(string $usuario, ?string $descripcion = null, ?int $notaOrigen = null, ?string $sistema = null): Nota
    {
        return DB::transaction(function () use ($usuario, $descripcion, $notaOrigen, $sistema) {
            $nronota = $this->siguienteNronota();
            $notaSoftland = $this->siguienteNotaSoftland();

            $nota = Nota::create([
                'nronota' => $nronota,
                'descripcion' => $descripcion ?? 'Cotización '.$nronota,
                'fecha' => now()->toDateString(),
                'usuario' => $usuario,
                'encargado' => '',
                'empresa' => '',
                'celular' => '',
                'contacto' => '',
                'contactocorreo' => '',
                'nota_softland' => $notaSoftland,
                'notaorigen' => $notaOrigen ?? 0,
                'sistema' => $sistema ?? config('app.name'),
                'enviadoapi' => 0,
                'diashabiles' => (int) config('cotiz.diashabiles_rm', 5),
                'factor_precio_venta' => config('cotiz.factor_precio_venta'),
            ]);

            $this->adoptarVerificacionParDesdeBorrador($nronota);

            return $nota;
        });
    }

    /**
     * Nota en memoria (sin grabar) para el formulario de ingreso.
     * El nronota se asigna recién al importar productos o al grabar.
     */
    public function borrador(string $usuario): Nota
    {
        $nota = new Nota([
            'nronota' => 0,
            'descripcion' => 'Nueva cotización',
            'fecha' => now()->toDateString(),
            'usuario' => $usuario,
            'encargado' => '',
            'empresa' => '',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'rutempresa' => '',
            'nota_softland' => 0,
            'notaorigen' => 0,
            'sistema' => config('app.name'),
            'enviadoapi' => 0,
            'diashabiles' => (int) config('cotiz.diashabiles_rm', 5),
            'factor_precio_venta' => config('cotiz.factor_precio_venta'),
            'ocompra' => '',
        ]);
        $nota->exists = false;

        return $nota;
    }

    public function esBorrador(Nota $nota): bool
    {
        return ! $nota->exists || (int) $nota->nronota === 0;
    }

    /**
     * Tras validar en borrador (nronota 0), reutiliza la verificación par en la nota real.
     */
    public function adoptarVerificacionParDesdeBorrador(int $nronotaNuevo): void
    {
        if ($nronotaNuevo <= 0) {
            return;
        }

        $data = session()->get($this->claveParVerificado(0));
        if (! is_array($data)) {
            return;
        }

        session()->put($this->claveParVerificado($nronotaNuevo), $data);
        session()->forget($this->claveParVerificado(0));
    }

    public function obtenerUltima(string $usuario): ?Nota
    {
        return Nota::query()
            ->where('usuario', $usuario)
            ->orderByDesc('nronota')
            ->first();
    }

    public function pendienteSinNumeroCotizacion(string $usuario): ?Nota
    {
        return Nota::query()
            ->where('usuario', $usuario)
            ->whereRaw("trim(coalesce(encargado, '')) = ''")
            ->orderByDesc('nronota')
            ->first();
    }

    /**
     * Última nota del usuario sin líneas de detalle (sin productos).
     */
    public function ultimaSinProductos(string $usuario): ?Nota
    {
        $ultima = $this->obtenerUltima($usuario);
        if ($ultima === null) {
            return null;
        }

        if ($ultima->detalle()->exists()) {
            return null;
        }

        return $ultima;
    }

    public function modificarCabecera(Nota $nota, array $datos): Nota
    {
        $encargadoAnterior = strtoupper(trim((string) $nota->encargado));
        $encargadoNuevo = strtoupper(trim((string) ($datos['encargado'] ?? $nota->encargado)));

        if (
            $encargadoNuevo !== $encargadoAnterior
            && preg_match('/^\d+-\d+-COT\d+$/', $encargadoNuevo) === 1
        ) {
            // Reserva atómica local + par ANTES de grabar la nota.
            $this->oportunidadRelay->reservarExclusivo(
                $encargadoNuevo,
                trim((string) $nota->usuario),
            );
        }

        $factorParsed = array_key_exists('factor_precio_venta', $datos)
            ? $this->parseFactorPrecioVenta($datos['factor_precio_venta'])
            : null;

        $factor = $factorParsed ?? round((float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta')), 2);

        $payload = [
            'descripcion' => $datos['descripcion'] ?? $nota->descripcion,
            'empresa' => $datos['empresa'] ?? $nota->empresa,
            'encargado' => $datos['encargado'] ?? $nota->encargado,
            'celular' => $datos['celular'] ?? $nota->celular,
            'contacto' => $datos['contacto'] ?? $nota->contacto,
            'contactocorreo' => $datos['contactocorreo'] ?? $nota->contactocorreo,
            'rutempresa' => $datos['rutempresa'] ?? $nota->rutempresa,
            'diashabiles' => (int) ($datos['diashabiles'] ?? $nota->diashabiles ?? config('cotiz.diashabiles_rm', 5)),
            'ocompra' => $datos['ocompra'] ?? $nota->ocompra,
            'fechaentrega' => $datos['fechaentrega'] ?? $nota->fechaentrega,
            'factor_precio_venta' => $factor,
        ];

        if (array_key_exists('direccion_entrega', $datos)) {
            $payload['direccion_entrega'] = mb_substr(trim((string) $datos['direccion_entrega']), 0, 255);
        }
        if (array_key_exists('region', $datos)) {
            $region = $datos['region'];
            $payload['region'] = ($region !== null && $region !== '' && is_numeric($region))
                ? (int) $region
                : null;
        }
        if (array_key_exists('nombre_region', $datos)) {
            $payload['nombre_region'] = mb_substr(trim((string) $datos['nombre_region']), 0, 100) ?: null;
        }
        if (array_key_exists('comuna', $datos)) {
            $payload['comuna'] = mb_substr(trim((string) $datos['comuna']), 0, 120) ?: null;
        }

        $nota->update($payload);

        return $nota->fresh();
    }

    public function validarNumeroCotizacion(Nota $nota, ?string $encargado = null): ?string
    {
        $numero = trim($encargado ?? (string) $nota->encargado);

        if ($numero === '') {
            return 'Debe ingresar el número de cotización antes de continuar.';
        }

        $existente = Nota::query()
            ->where('nronota', '!=', $nota->nronota)
            ->whereRaw('lower(trim(encargado)) = lower(?)', [$numero])
            ->first(['nronota', 'encargado']);

        if ($existente) {
            return sprintf(
                'La cotización «%s» ya existe (nota #%d). No se puede duplicar.',
                trim((string) $existente->encargado),
                $existente->nronota,
            );
        }

        return null;
    }

    public function marcarEncargadoVerificadoEnPar(int $nronota, string $encargado): void
    {
        $numero = trim($encargado);
        if ($numero === '') {
            return;
        }

        session()->put($this->claveParVerificado($nronota), [
            'encargado' => $numero,
            'verificado_at' => time(),
        ]);
    }

    public function encargadoVerificadoRecientementeEnPar(int $nronota, string $encargado): bool
    {
        $numero = trim($encargado);
        if ($numero === '') {
            return false;
        }

        $data = session()->get($this->claveParVerificado($nronota));
        if (! is_array($data)) {
            return false;
        }

        $guardado = trim((string) ($data['encargado'] ?? ''));
        $verificadoAt = (int) ($data['verificado_at'] ?? 0);

        return $guardado !== ''
            && strcasecmp($guardado, $numero) === 0
            && $verificadoAt > 0
            && (time() - $verificadoAt) < self::PAR_VERIFICADO_TTL_SEGUNDOS;
    }

    /**
     * Valida número de cotización en esta instancia y en el sitio par (Reicol/Romulo).
     */
    public function validarNumeroCotizacionDisponible(
        Nota $nota,
        ?string $encargado = null,
        bool $forzarConsultaPar = false,
        bool $omitirConsultaParSiVerificado = false,
    ): ?string {
        $error = $this->validarNumeroCotizacion($nota, $encargado);
        if ($error !== null) {
            return $error;
        }

        $numero = trim($encargado ?? (string) $nota->encargado);
        $actual = trim((string) $nota->encargado);

        if (! $forzarConsultaPar && $actual !== '' && strcasecmp($actual, $numero) === 0) {
            return null;
        }

        if ($omitirConsultaParSiVerificado && $this->encargadoVerificadoRecientementeEnPar($nota->nronota, $numero)) {
            return null;
        }

        $remoto = app(NotaConsultaRemotaService::class)->errorSiEncargadoExisteEnPar(
            $numero,
            sprintf(
                'La cotización «%s» ya existe registrada en el otro sitio, favor verificar.',
                $numero,
            ),
        );

        if ($remoto === '' || str_contains($remoto, 'ya existe')) {
            $this->marcarEncargadoVerificadoEnPar($nota->nronota, $numero);
        }

        return $remoto !== '' ? $remoto : null;
    }

    /**
     * @return array{error: string|null, origen: string|null, cold_start?: bool, consulta_par: array<string, mixed>|null}
     */
    public function validarNumeroCotizacionDisponibleConDetalle(
        Nota $nota,
        ?string $encargado = null,
        bool $forzarConsultaPar = false,
        bool $omitirConsultaParSiVerificado = false,
    ): array {
        $numero = trim($encargado ?? (string) $nota->encargado);

        $local = $this->validarNumeroCotizacion($nota, $encargado);
        if ($local !== null) {
            return [
                'error' => $local,
                'origen' => 'local',
                'consulta_par' => null,
            ];
        }

        $actual = trim((string) $nota->encargado);

        if (! $forzarConsultaPar && $actual !== '' && strcasecmp($actual, $numero) === 0) {
            return [
                'error' => null,
                'origen' => null,
                'consulta_par' => null,
            ];
        }

        if ($omitirConsultaParSiVerificado && $this->encargadoVerificadoRecientementeEnPar($nota->nronota, $numero)) {
            return [
                'error' => null,
                'origen' => null,
                'consulta_par' => null,
                'par_ya_verificado' => true,
            ];
        }

        if ($numero === '') {
            return [
                'error' => null,
                'origen' => null,
                'consulta_par' => null,
            ];
        }

        $consultaPar = app(NotaConsultaRemotaService::class)->consultarEncargadoEnPar($numero);

        if (($consultaPar['cold_start'] ?? false) === true) {
            return [
                'error' => null,
                'cold_start' => true,
                'origen' => 'par',
                'consulta_par' => $consultaPar,
            ];
        }

        if ($consultaPar['error'] !== null && $consultaPar['error'] !== '') {
            return [
                'error' => $consultaPar['error'],
                'origen' => 'par',
                'consulta_par' => $consultaPar,
            ];
        }

        if ($consultaPar['existe'] === true) {
            $this->marcarEncargadoVerificadoEnPar($nota->nronota, $numero);

            return [
                'error' => $consultaPar['mensaje'] ?? sprintf(
                    'La cotización «%s» ya existe registrada en el otro sitio, favor verificar.',
                    $numero,
                ),
                'origen' => 'par',
                'consulta_par' => $consultaPar,
            ];
        }

        if ($consultaPar !== null && $consultaPar['existe'] === false) {
            $this->marcarEncargadoVerificadoEnPar($nota->nronota, $numero);
        }

        return [
            'error' => null,
            'origen' => null,
            'consulta_par' => $consultaPar,
        ];
    }

    private function claveParVerificado(int $nronota): string
    {
        return "cotiz.par_verificado.{$nronota}";
    }

    /**
     * Factor de precio venta: positivo, máximo 2 decimales (acepta coma o punto).
     */
    public function aceptar(Nota $nota, string $usuario): Nota
    {
        $nota->update([
            'estado' => 'aceptada',
            'estadofecha' => now(),
            'estadousuario' => $usuario,
        ]);

        return $nota->fresh();
    }

    public function noAceptar(Nota $nota, string $usuario): Nota
    {
        $nota->update([
            'estado' => '',
            'estadofecha' => now(),
            'estadousuario' => $usuario,
        ]);

        return $nota->fresh();
    }

    public function asignarUsuario(Nota $nota, string $usuario): Nota
    {
        $nota->update(['usuario' => $usuario]);

        return $nota->fresh();
    }

    public function marcarEnviadoApi(Nota $nota, int $enviado): Nota
    {
        $nota->update(['enviadoapi' => $enviado]);

        return $nota->fresh();
    }

    public function estaAceptada(Nota $nota): bool
    {
        return strtolower(trim((string) $nota->estado)) === 'aceptada';
    }

    public function parseFactorPrecioVenta(mixed $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        if (is_int($valor) || is_float($valor)) {
            $factor = round((float) $valor, 2);

            return $factor > 0 ? $factor : null;
        }

        $texto = trim(str_replace([' ', "\xc2\xa0"], '', (string) $valor));
        if ($texto === '') {
            return null;
        }

        if (str_ends_with($texto, ',') || str_ends_with($texto, '.')) {
            $texto = substr($texto, 0, -1);
        }

        if ($texto === '') {
            return null;
        }

        if (str_contains($texto, ',')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $texto)) {
            return null;
        }

        $factor = (float) $texto;

        return $factor > 0 ? round($factor, 2) : null;
    }

    private function siguienteNronota(): int
    {
        return DB::transaction(function () {
            $row = DB::table('nronota_seq')->lockForUpdate()->first();
            $maxExistente = (int) Nota::query()->max('nronota');

            if (! $row) {
                $next = max($maxExistente, 0) + 1;
                DB::table('nronota_seq')->insert(['ultimo' => $next]);

                return $next;
            }

            $next = max((int) $row->ultimo, $maxExistente) + 1;
            DB::table('nronota_seq')->update(['ultimo' => $next]);

            return $next;
        });
    }

    private function siguienteNotaSoftland(): int
    {
        $max = Nota::query()->where('nota_softland', '>', 0)->max('nota_softland');

        return $max ? ((int) $max + 1) : 10000;
    }
}
