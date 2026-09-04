<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Support\Facades\DB;

class NotaService
{
    private const PAR_VERIFICADO_TTL_SEGUNDOS = 300;

    public function __construct(
        protected OportunidadEncontradaRelayService $oportunidadRelay,
        protected NotaAuditoriaService $auditoria,
    ) {}

    public function crear(
        string $usuario,
        ?string $descripcion = null,
        ?int $notaOrigen = null,
        ?string $sistema = null,
        bool $interna = false,
    ): Nota {
        return DB::transaction(function () use ($usuario, $descripcion, $notaOrigen, $sistema, $interna) {
            $nronota = $this->siguienteNronota();
            $notaSoftland = $this->siguienteNotaSoftland();

            $nota = Nota::create([
                'nronota' => $nronota,
                'descripcion' => $descripcion ?? ($interna ? 'Cotización interna '.$nronota : 'Cotización '.$nronota),
                'fecha' => now()->toDateString(),
                'usuario' => $usuario,
                'encargado' => $interna ? $this->codigoInternoParaNota($nronota) : '',
                'correlativo' => 1,
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
                'es_compra_agil' => ! $interna,
            ]);

            $this->adoptarVerificacionParDesdeBorrador($nronota);
            $this->auditoria->registrarAgregar(
                $nota,
                $usuario,
                $interna ? 'Alta de cotización interna' : 'Alta de cotización',
            );

            return $nota;
        });
    }

    public function codigoInternoParaNota(int $nronota): string
    {
        $prefijo = (string) config('cotiz.cotizacion_interna_prefijo', 'CM-');

        return $prefijo.$nronota;
    }

    /**
     * Nota en memoria (sin grabar) para el formulario de ingreso.
     * El nronota se asigna recién al importar productos o al grabar.
     */
    public function borrador(string $usuario, bool $interna = false): Nota
    {
        $nota = new Nota([
            'nronota' => 0,
            'descripcion' => $interna ? 'Nueva cotización interna' : 'Nueva cotización',
            'fecha' => now()->toDateString(),
            'usuario' => $usuario,
            'encargado' => '',
            'correlativo' => 1,
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
            'es_compra_agil' => ! $interna,
        ]);
        $nota->exists = false;

        return $nota;
    }

    public function esBorrador(Nota $nota): bool
    {
        return ! $nota->exists || (int) $nota->nronota === 0;
    }

    /**
     * Copia la cabecera (y opcionalmente los productos) en una nota nueva que
     * conserva el código de Mercado Público y avanza el correlativo.
     *
     * La copia nace sin estado (no aceptada), sin enviar a la API y como nota local.
     */
    public function duplicar(Nota $origen, ?string $usuarioAccion = null, bool $copiarDetalle = true): Nota
    {
        return DB::transaction(function () use ($origen, $usuarioAccion, $copiarDetalle) {
            $nronota = $this->siguienteNronota();

            // La copia queda con el mismo ejecutivo dueño, aunque la cree un superadmin.
            $esInterna = $origen->esCotizacionInterna();
            $copia = Nota::create([
                'nronota' => $nronota,
                'correlativo' => $esInterna ? 1 : $this->siguienteCorrelativo((string) $origen->encargado),
                'descripcion' => $origen->descripcion,
                'fecha' => now()->toDateString(),
                'usuario' => $origen->usuario,
                'encargado' => $esInterna ? $this->codigoInternoParaNota($nronota) : $origen->encargado,
                'empresa' => $origen->empresa,
                'celular' => $origen->celular,
                'contacto' => $origen->contacto,
                'contactocorreo' => $origen->contactocorreo,
                'rutempresa' => $origen->rutempresa,
                'nota_softland' => $this->siguienteNotaSoftland(),
                'notaorigen' => 0,
                'sistema' => $origen->sistema ?? config('app.name'),
                'enviadoapi' => 0,
                'diashabiles' => $origen->diashabiles ?? (int) config('cotiz.diashabiles_rm', 5),
                'ocompra' => $origen->ocompra,
                'fechaentrega' => $origen->fechaentrega,
                'factor_precio_venta' => $origen->factor_precio_venta,
                'direccion_entrega' => $origen->direccion_entrega,
                'region' => $origen->region,
                'nombre_region' => $origen->nombre_region,
                'comuna' => $origen->comuna,
                'es_compra_agil' => ! $esInterna,
            ]);

            if ($copiarDetalle) {
                // replicate() copia los atributos ya normalizados, sin volver a pasar
                // las descripciones Agile por sus mutadores.
                foreach ($origen->detalle()->get() as $linea) {
                    $lineaCopia = $linea->replicate();
                    $lineaCopia->nronota = $nronota;
                    $lineaCopia->fechahora = now();
                    $lineaCopia->save();
                }
            }

            $this->auditoria->registrarAgregar(
                $copia,
                $usuarioAccion ?: $origen->usuario,
                'Duplicación desde nota #'.$origen->nronota.($copiarDetalle ? '' : ' (solo encabezado)'),
            );

            return $copia;
        });
    }

    public function siguienteCorrelativo(string $encargado): int
    {
        $numero = trim($encargado);
        if ($numero === '') {
            return 1;
        }

        $maximo = (int) Nota::query()
            ->whereRaw('lower(trim(encargado)) = lower(?)', [$numero])
            ->max('correlativo');

        return max(1, $maximo) + 1;
    }

    /**
     * Otra cotización del mismo código de Mercado Público con idéntico detalle
     * (mismos productos, cantidades y precios). Ofertar dos veces lo mismo al
     * proceso no aporta nada, así que sirve para bloquear el PDF.
     */
    public function cotizacionGemela(Nota $nota): ?Nota
    {
        $codigo = trim((string) $nota->encargado);
        if ($codigo === '') {
            return null;
        }

        $firma = $this->firmaDetalle($nota);
        if ($firma === '') {
            return null;
        }

        $hermanas = Nota::query()
            ->where('nronota', '!=', $nota->nronota)
            ->whereRaw('lower(trim(encargado)) = lower(?)', [$codigo])
            ->orderBy('nronota')
            ->get();

        foreach ($hermanas as $hermana) {
            if ($this->firmaDetalle($hermana) === $firma) {
                return $hermana;
            }
        }

        return null;
    }

    /**
     * Primer par de cotizaciones del mismo código MP con detalle idéntico.
     * Si existe, no tiene sentido crear otra copia hasta diferenciarlas.
     *
     * @return array{0: Nota, 1: Nota}|null  [la original, la que quedó sin cambios]
     */
    public function parIdenticoDelCodigo(string $encargado): ?array
    {
        $codigo = trim($encargado);
        if ($codigo === '') {
            return null;
        }

        $notas = Nota::query()
            ->whereRaw('lower(trim(encargado)) = lower(?)', [$codigo])
            ->orderBy('nronota')
            ->get();

        $porFirma = [];

        foreach ($notas as $nota) {
            $firma = $this->firmaDetalle($nota);
            if ($firma === '') {
                continue;
            }

            if (isset($porFirma[$firma])) {
                return [$porFirma[$firma], $nota];
            }

            $porFirma[$firma] = $nota;
        }

        return null;
    }

    /**
     * Huella del detalle, independiente del orden de las líneas.
     */
    private function firmaDetalle(Nota $nota): string
    {
        $lineas = $nota->detalle()
            ->get(['prod_item', 'cantidad', 'prod_valor'])
            ->map(fn ($linea) => sprintf(
                '%s|%s|%s',
                strtoupper(trim((string) $linea->prod_item)),
                (float) $linea->cantidad,
                (float) $linea->prod_valor,
            ))
            ->sort()
            ->values()
            ->implode(';');

        return $lineas;
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
            ->where('es_compra_agil', true)
            ->whereRaw("trim(coalesce(encargado, '')) = ''")
            ->orderByDesc('nronota')
            ->first();
    }

    /**
     * Última nota del usuario sin líneas de detalle (sin productos).
     * Por defecto solo reutiliza cotizaciones de Mercado Público; las internas
     * se piden con $interna = true para no mezclar ambos flujos.
     */
    public function ultimaSinProductos(string $usuario, bool $interna = false): ?Nota
    {
        $ultima = Nota::query()
            ->where('usuario', $usuario)
            ->where('es_compra_agil', ! $interna)
            ->orderByDesc('nronota')
            ->first();
        if ($ultima === null) {
            return null;
        }

        if ($ultima->detalle()->exists()) {
            return null;
        }

        return $ultima;
    }

    public function modificarCabecera(Nota $nota, array $datos, ?string $usuarioModifica = null): Nota
    {
        $encargadoAnterior = strtoupper(trim((string) $nota->encargado));
        $encargadoNuevo = strtoupper(trim((string) ($datos['encargado'] ?? $nota->encargado)));

        if (
            $encargadoNuevo !== $encargadoAnterior
            && preg_match('/^\d+-\d+-COT\d+$/', $encargadoNuevo) === 1
        ) {
            // Reserva local (oportunidad_tomadas); no replica tomada al sitio par.
            $this->oportunidadRelay->reservarExclusivo(
                $encargadoNuevo,
                trim((string) $nota->usuario),
            );
        }

        $factorParsed = array_key_exists('factor_precio_venta', $datos)
            ? $this->parseFactorPrecioVenta($datos['factor_precio_venta'])
            : null;

        $factor = $factorParsed ?? round((float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta')), 2);

        $encargado = $datos['encargado'] ?? $nota->encargado;
        if ($nota->esCotizacionInterna()) {
            $encargado = trim((string) $nota->encargado);
            if ($encargado === '' && (int) $nota->nronota > 0) {
                $encargado = $this->codigoInternoParaNota((int) $nota->nronota);
            }
        }

        $payload = [
            'descripcion' => $datos['descripcion'] ?? $nota->descripcion,
            'empresa' => $datos['empresa'] ?? $nota->empresa,
            'encargado' => $encargado,
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
        if (array_key_exists('observacion_ejecutivo', $datos)) {
            $obs = trim((string) $datos['observacion_ejecutivo']);
            $payload['observacion_ejecutivo'] = $obs !== '' ? $obs : null;
        }

        $nota->update($payload);

        $this->auditoria->registrarModificar(
            $nota,
            $usuarioModifica,
            'Modificación de cabecera',
        );

        return $nota->fresh();
    }

    public function validarNumeroCotizacion(Nota $nota, ?string $encargado = null): ?string
    {
        $numero = trim($encargado ?? (string) $nota->encargado);

        if ($numero === '') {
            return 'Debe ingresar el número de cotización antes de continuar.';
        }

        // La nota ya tiene este código: sus copias con otro correlativo son válidas.
        // Solo se bloquea tomar un código que pertenece a otro proceso.
        if (strcasecmp(trim((string) $nota->encargado), $numero) === 0) {
            return null;
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
     * Valida número de cotización solo en esta instancia (sin consultar el sitio par).
     */
    public function validarNumeroCotizacionDisponible(
        Nota $nota,
        ?string $encargado = null,
        bool $forzarConsultaPar = false,
        bool $omitirConsultaParSiVerificado = false,
    ): ?string {
        return $this->validarNumeroCotizacion($nota, $encargado);
    }

    /**
     * @return array{error: string|null, origen: string|null, consulta_par: null}
     */
    public function validarNumeroCotizacionDisponibleConDetalle(
        Nota $nota,
        ?string $encargado = null,
        bool $forzarConsultaPar = false,
        bool $omitirConsultaParSiVerificado = false,
    ): array {
        $local = $this->validarNumeroCotizacion($nota, $encargado);
        if ($local !== null) {
            return [
                'error' => $local,
                'origen' => 'local',
                'consulta_par' => null,
            ];
        }

        return [
            'error' => null,
            'origen' => null,
            'consulta_par' => null,
        ];
    }

    /**
     * Valida duplicado en el otro sitio antes de enviar la cotización.
     */
    public function errorSiEncargadoExisteEnParParaEnvio(Nota $nota): ?string
    {
        $numero = trim((string) $nota->encargado);
        if ($numero === '') {
            return 'La cotización no tiene número de cotización.';
        }

        $remoto = app(NotaConsultaRemotaService::class)->errorSiEncargadoExisteEnPar(
            $numero,
            sprintf('La cotización «%s» ya existe en el otro sitio.', $numero),
        );

        return $remoto !== '' ? $remoto : null;
    }

    /**
     * Consulta el sitio par para ocultar «Enviar» en el listado cuando el código ya existe allí.
     *
     * @param  iterable<int, Nota>  $notas
     * @return array<int, array{existe: bool, mensaje: string}>
     */
    public function consultaParEnvioPorNotas(iterable $notas): array
    {
        if (! \App\Support\CotizInstanciaPar::debeConsultarPar()) {
            return [];
        }

        $consulta = app(NotaConsultaRemotaService::class);
        $estados = [];

        foreach ($notas as $nota) {
            if ((int) $nota->enviadoapi !== 0 || $nota->fueRecibidaPorApi()) {
                continue;
            }

            $numero = trim((string) $nota->encargado);
            if ($numero === '') {
                continue;
            }

            $resultado = $consulta->consultarEncargadoEnPar($numero);
            if (($resultado['existe'] ?? null) === true) {
                $estados[(int) $nota->nronota] = [
                    'existe' => true,
                    'mensaje' => $resultado['mensaje'] ?? sprintf(
                        'La cotización «%s» ya existe en el otro sitio.',
                        $numero,
                    ),
                ];
            }
        }

        return $estados;
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
