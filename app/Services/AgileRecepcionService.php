<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Support\ProdValorFechaUi;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AgileRecepcionService
{
    public function __construct(
        protected NotaService $notaService,
        protected AgileMaeprodService $agileMaeprodService,
        protected NotaDetalleService $detalleService,
        protected NotaConsultaRemotaService $consultaRemotaService,
    ) {}

    public function esNotaAgile(Nota $nota): bool
    {
        $sistema = trim((string) $nota->sistema);

        return strcasecmp($sistema, (string) config('cotiz.agile.sistema', 'API')) === 0;
    }

    public function listar(User $user, array $filtros): LengthAwarePaginator
    {
        $query = Nota::query()
            ->where('sistema', config('cotiz.agile.sistema', 'API'));

        $this->aplicarReglasUsuario($query, $user);

        $campo = $filtros['campo'] ?? 'encargado';
        $valor = trim((string) ($filtros['valor'] ?? ''));

        if ($valor !== '') {
            match ($campo) {
                'rutempresa' => $query->where('rutempresa', 'ilike', '%'.$valor.'%'),
                'empresa' => $query->where('empresa', 'ilike', '%'.$valor.'%'),
                default => $query->where('encargado', 'ilike', '%'.$valor.'%'),
            };
        }

        return $query
            ->orderByDesc('nronota')
            ->paginate(10)
            ->withQueryString();
    }

    public function puedeVer(User $user, Nota $nota): bool
    {
        if (! $this->esNotaAgile($nota)) {
            return false;
        }

        if ($user->isSuperAdmin() && $user->username === 'admin') {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return strcasecmp((string) $nota->usuario, 'admin') !== 0;
        }

        return strcasecmp((string) $nota->usuario, $user->username) === 0;
    }

    /**
     * @return array{nota: Nota, lineas: Collection<int, array>}
     */
    public function detalle(Nota $nota): array
    {
        $nota->load('detalle.producto');

        $lineas = $nota->detalle->map(function (NotaDetalle $linea) {
            [$fechaFmt, $fechaAntigua] = ProdValorFechaUi::textoYAntigua(
                $linea->producto?->prod_valor_fecha
            );

            $codigoInterno = trim((string) $linea->prod_item);
            if ($codigoInterno === '' || $codigoInterno === '0') {
                $codigoInterno = (string) ($this->agileMaeprodService->codigoInternoParaAgile(
                    (string) $linea->prod_item_agile
                ) ?? '');
            }

            return [
                'linea' => $linea,
                'prod_item' => $codigoInterno,
                'prod_nombre' => $linea->producto?->prod_nombre ?? $linea->prod_descripcion_agile ?? $linea->prod_item_agile,
                'prod_valor_fecha' => $fechaFmt,
                'prod_valor_fecha_antigua' => $fechaAntigua,
                'subtotal' => $linea->lineTotal(),
            ];
        });

        return ['nota' => $nota, 'lineas' => $lineas];
    }

    /**
     * Recibe cotización desde API Agile (apiagile.php).
     *
     * @param  array{usuario: string, codigo_cotizacion: string, rut_empresa: string, nombre_empresa: string, productos: array}  $payload
     */
    public function recibirDesdeApi(array $payload): int
    {
        $usuario = trim((string) $payload['usuario']);
        $codigo = trim((string) $payload['codigo_cotizacion']);

        if ($usuario === '' || $codigo === '') {
            throw new RuntimeException('Usuario o código de cotización inválido.');
        }

        if (! User::query()->where('username', $usuario)->exists()) {
            throw new RuntimeException('Usuario no existe: '.$usuario);
        }

        if (Nota::query()->whereRaw('trim(encargado) = ?', [$codigo])->exists()) {
            throw new RuntimeException('La cotización ya existe en notas: '.$codigo);
        }

        $errRemoto = $this->consultaRemotaService->errorSiEncargadoExisteEnPar(
            $codigo,
            'La cotización ya existe registrada en el sitio central, favor verificar.',
        );
        if ($errRemoto !== '') {
            throw new RuntimeException($errRemoto);
        }

        $existenteAgile = Nota::query()
            ->where('sistema', config('cotiz.agile.sistema', 'API'))
            ->whereRaw('trim(encargado) = ?', [$codigo])
            ->first();

        if ($existenteAgile) {
            if (strcasecmp((string) $existenteAgile->usuario, $usuario) !== 0) {
                throw new RuntimeException('La cotización ya está creada en el sistema.');
            }

            return (int) $existenteAgile->nronota;
        }

        return DB::transaction(function () use ($payload, $usuario, $codigo) {
            $nronota = $this->siguienteNronota();

            Nota::query()->create([
                'nronota' => $nronota,
                'descripcion' => 'Recepción Agile '.$codigo,
                'fecha' => now()->toDateString(),
                'usuario' => $usuario,
                'empresa' => trim((string) ($payload['nombre_empresa'] ?? '')),
                'encargado' => $codigo,
                'celular' => '',
                'contacto' => '',
                'contactocorreo' => '',
                'rutempresa' => trim((string) ($payload['rut_empresa'] ?? '')),
                'nota_softland' => 0,
                'diashabiles' => 0,
                'notaorigen' => 0,
                'sistema' => config('cotiz.agile.sistema', 'API'),
                'enviadoapi' => 0,
                'estado' => 'Pendiente',
                'estadofecha' => now(),
                'estadousuario' => 'API',
                'ocompra' => '',
                'fechaentrega' => null,
                'factor_precio_venta' => config('cotiz.factor_precio_venta'),
            ]);

            $orden = 1;
            foreach ($payload['productos'] as $producto) {
                $idAgile = trim((string) ($producto['id'] ?? ''));
                $descripcion = str_replace("'", '´', trim((string) ($producto['descripcion'] ?? '')));
                $cantidad = max(1, (int) ($producto['cantidad'] ?? 1));

                if ($idAgile === '') {
                    continue;
                }

                $this->agileMaeprodService->registrarSiNoExiste($idAgile, $descripcion);

                NotaDetalle::query()->create([
                    'nronota' => $nronota,
                    'prod_item' => $idAgile,
                    'prod_valor' => 0,
                    'cantidad' => $cantidad,
                    'fechahora' => now(),
                    'orden' => $orden,
                    'prod_valor_costo' => 0,
                    'prod_item_agile' => $idAgile,
                    'prod_descripcion_agile' => $descripcion,
                ]);

                $orden++;
            }

            return $nronota;
        });
    }

    /**
     * @return array{factor: float, lineas: array<int, array{orden: int, prod_item_agile: string, prod_valor: int, prod_valor_costo: int, subtotal: int}>}
     */
    public function aplicarFactorPrecioVenta(Nota $nota, float $factor, ?string $usuarioUpd = null): array
    {
        $factor = round($factor, 2);
        if ($factor <= 0) {
            throw new RuntimeException('Factor inválido.');
        }

        return DB::transaction(function () use ($nota, $factor, $usuarioUpd) {
            $nota->update(['factor_precio_venta' => $factor]);

            $lineas = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->orderBy('orden')
                ->get();

            $actualizadas = [];

            foreach ($lineas as $linea) {
                $costo = (int) $linea->prod_valor_costo;
                $nuevoValor = $costo > 0
                    ? (int) round($costo * $factor)
                    : (int) $linea->prod_valor;

                NotaDetalle::query()
                    ->where('nronota', $nota->nronota)
                    ->where('orden', $linea->orden)
                    ->where('prod_item_agile', $linea->prod_item_agile)
                    ->update(['prod_valor' => $nuevoValor]);

                $codigoInterno = trim((string) $linea->prod_item);
                if ($codigoInterno === '' || $codigoInterno === '0') {
                    $codigoInterno = $this->agileMaeprodService->codigoInternoParaAgile((string) $linea->prod_item_agile) ?? '';
                }

                if ($codigoInterno !== '' && $codigoInterno !== '0') {
                    $producto = Maeprod::query()->find($codigoInterno);
                    if ($producto) {
                        $producto->update([
                            'prod_valor' => $nuevoValor,
                            'prod_valor_fecha' => now(),
                            'prod_user_upd' => $usuarioUpd,
                        ]);
                    }
                }

                $actualizadas[] = [
                    'orden' => (int) $linea->orden,
                    'prod_item_agile' => (string) $linea->prod_item_agile,
                    'prod_valor' => $nuevoValor,
                    'prod_valor_costo' => $costo,
                    'subtotal' => $nuevoValor * (int) $linea->cantidad,
                ];
            }

            return ['factor' => $factor, 'lineas' => $actualizadas];
        });
    }

    public function actualizarPrecioLinea(Nota $nota, array $datos, ?string $usuarioUpd = null): NotaDetalle
    {
        $linea = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('orden', (int) $datos['orden'])
            ->where('prod_item_agile', trim((string) $datos['prod_item_agile']))
            ->firstOrFail();

        $codigoInterno = trim((string) ($datos['prod_item'] ?? ''));
        if ($codigoInterno === '' || $codigoInterno === '0') {
            $codigoInterno = $this->agileMaeprodService->codigoInternoParaAgile((string) $linea->prod_item_agile) ?? '';
        }

        $producto = ($codigoInterno !== '' && $codigoInterno !== '0')
            ? Maeprod::query()->find($codigoInterno)
            : null;

        $costo = (int) $linea->prod_valor_costo;
        if ($producto) {
            $costoCatalogo = (int) ($producto->prod_valor_costo ?? 0);
            if ($costoCatalogo <= 0) {
                $costoCatalogo = (int) ($producto->prod_valor ?? 0);
            }
            if ($costoCatalogo > 0) {
                $costo = $costoCatalogo;
            }
        }

        if (isset($datos['prod_valor'])) {
            $prodValorDet = max(0, (int) $datos['prod_valor']);
            if ($prodValorDet === 0 && $costo > 0) {
                $prodValorDet = (int) round($costo * $this->factorDetalle($nota, $datos));
            }
        } else {
            $prodValorDet = (int) round($costo * $this->factorDetalle($nota, $datos));
        }

        $nuevoProdItem = $codigoInterno !== '' ? $codigoInterno : $linea->prod_item;
        $descripcionAgile = trim((string) ($datos['prod_descripcion_agile'] ?? $linea->prod_descripcion_agile ?? ''));

        NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('orden', (int) $datos['orden'])
            ->where('prod_item_agile', trim((string) $datos['prod_item_agile']))
            ->update([
                'prod_valor_costo' => $costo,
                'prod_valor' => $prodValorDet,
                'prod_item' => $nuevoProdItem,
                'prod_descripcion_agile' => $descripcionAgile,
            ]);

        if ($codigoInterno !== '' && $codigoInterno !== '0') {
            $this->agileMaeprodService->vincularCodigoInterno((string) $linea->prod_item_agile, $codigoInterno);

            if ($producto) {
                $producto->update([
                    'prod_valor' => $prodValorDet,
                    'prod_valor_fecha' => now(),
                    'prod_user_upd' => $usuarioUpd,
                ]);
            }
        }

        return NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('orden', (int) $datos['orden'])
            ->where('prod_item_agile', trim((string) $datos['prod_item_agile']))
            ->with('producto')
            ->firstOrFail();
    }

    public function aprobar(Nota $nota, ?string $estadousuario = null): Nota
    {
        $nota->load('detalle');

        $faltantes = [];
        $sinPrecio = [];

        foreach ($nota->detalle as $linea) {
            $codigo = trim((string) $linea->prod_item);
            if ($codigo === '' || $codigo === '0') {
                $codigo = $this->agileMaeprodService->codigoInternoParaAgile((string) $linea->prod_item_agile) ?? '';
            }
            if ($codigo === '' || $codigo === '0') {
                $faltantes[] = $linea->prod_item_agile ?: 'fila '.$linea->orden;
            }
            if ((int) $linea->prod_valor <= 0) {
                $sinPrecio[] = $linea->prod_descripcion_agile ?: $linea->prod_item_agile ?: 'fila '.$linea->orden;
            }
        }

        if ($faltantes !== []) {
            throw new RuntimeException('Falta código interno en productos: '.implode(', ', $faltantes));
        }

        if ($sinPrecio !== []) {
            throw new RuntimeException('Productos con precio venta en cero: '.implode(', ', $sinPrecio));
        }

        $nota->update([
            'estado' => 'Aprobada',
            'estadofecha' => now(),
            'estadousuario' => $estadousuario ?: $nota->usuario,
        ]);

        return $nota->fresh();
    }

    public function eliminar(Nota $nota): void
    {
        DB::transaction(function () use ($nota) {
            NotaDetalle::query()->where('nronota', $nota->nronota)->delete();
            $nota->delete();
        });
    }

    public function buscarProductosParaPopup(string $texto): array
    {
        $productos = $this->detalleService->buscarProductos($texto, null, 20);

        return $productos->map(function (Maeprod $p) {
            $costo = (int) ($p->prod_valor_costo ?? 0);
            if ($costo <= 0) {
                $costo = (int) ($p->prod_valor ?? 0);
            }

            return [
                'codigo' => $p->prod_item,
                'nombre' => $p->prod_nombre,
                'precio_costo' => $costo,
                'precio_venta' => (int) ($p->prod_valor ?? 0),
                'stock_real' => $p->prod_stock_real,
                'imagen_url' => $p->imageUrl(),
            ];
        })->all();
    }

    private function factorDetalle(Nota $nota, array $datos): float
    {
        if (isset($datos['factor_precio_venta'])) {
            $parsed = $this->notaService->parseFactorPrecioVenta($datos['factor_precio_venta']);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $f = (float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta'));

        return $f > 0 ? $f : (float) config('cotiz.agile.maeprod_factor_precio_venta', 1.22);
    }

    private function aplicarReglasUsuario($query, User $user): void
    {
        if ($user->isSuperAdmin()) {
            if ($user->username !== 'admin') {
                $query->where('usuario', '<>', 'admin');
            }

            return;
        }

        $query->where('usuario', $user->username);
    }

    private function siguienteNronota(): int
    {
        $row = DB::table('nronota_seq')->lockForUpdate()->first();
        if (! $row) {
            DB::table('nronota_seq')->insert(['ultimo' => 1]);

            return 1;
        }

        $next = ((int) $row->ultimo) + 1;
        DB::table('nronota_seq')->update(['ultimo' => $next]);

        return $next;
    }
}
