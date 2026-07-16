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
                'diashabiles' => 2,
                'factor_precio_venta' => config('cotiz.factor_precio_venta'),
            ]);

            return $nota;
        });
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

    public function modificarCabecera(Nota $nota, array $datos): Nota
    {
        $encargadoAnterior = strtoupper(trim((string) $nota->encargado));
        $factorParsed = array_key_exists('factor_precio_venta', $datos)
            ? $this->parseFactorPrecioVenta($datos['factor_precio_venta'])
            : null;

        $factor = $factorParsed ?? round((float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta')), 2);

        $nota->update([
            'descripcion' => $datos['descripcion'] ?? $nota->descripcion,
            'empresa' => $datos['empresa'] ?? $nota->empresa,
            'encargado' => $datos['encargado'] ?? $nota->encargado,
            'celular' => $datos['celular'] ?? $nota->celular,
            'contacto' => $datos['contacto'] ?? $nota->contacto,
            'contactocorreo' => $datos['contactocorreo'] ?? $nota->contactocorreo,
            'rutempresa' => $datos['rutempresa'] ?? $nota->rutempresa,
            'diashabiles' => (int) ($datos['diashabiles'] ?? $nota->diashabiles ?? 2),
            'ocompra' => $datos['ocompra'] ?? $nota->ocompra,
            'fechaentrega' => $datos['fechaentrega'] ?? $nota->fechaentrega,
            'factor_precio_venta' => $factor,
        ]);

        $encargadoNuevo = strtoupper(trim((string) $nota->encargado));
        if (
            $encargadoNuevo !== $encargadoAnterior
            && preg_match('/^\d+-\d+-COT\d+$/', $encargadoNuevo) === 1
        ) {
            $usuario = trim((string) $nota->usuario);
            $this->oportunidadRelay->registrarTomadaLocal($encargadoNuevo, $usuario);

            $replicar = function () use ($encargadoNuevo, $usuario): void {
                $this->oportunidadRelay->replicarTomada($encargadoNuevo, $usuario);
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($replicar);
            } else {
                $replicar();
            }
        }

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
