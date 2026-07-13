<?php

namespace App\Services;

use App\Enums\VinculoOrigen;
use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Support\ProdValorFechaUi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotaDetalleService
{
    public function __construct(
        protected MaeprodBusquedaSimilitudService $busquedaSimilitud,
        protected AgileMaeprodService $agileMaeprodService,
    ) {}

    public function lineasDeNota(Nota $nota): Collection
    {
        $lineas = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->orderBy('orden')
            ->get();

        $codigosProducto = $lineas
            ->map(fn (NotaDetalle $linea) => $linea->codigoProducto())
            ->filter()
            ->unique()
            ->values()
            ->all();

        $maeprods = $codigosProducto === []
            ? collect()
            : Maeprod::query()
                ->whereIn('prod_item', $codigosProducto)
                ->get()
                ->keyBy('prod_item');

        $agileIds = $lineas
            ->map(fn (NotaDetalle $linea) => trim((string) ($linea->prod_item_agile ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $descripcionesAgile = $agileIds === []
            ? collect()
            : AgileMaeprod::query()
                ->whereIn('prod_item_agile', $agileIds)
                ->pluck('prod_descripcion_agile', 'prod_item_agile');

        $repetidosPorProd = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->selectRaw('prod_item, COUNT(*) as total')
            ->groupBy('prod_item')
            ->pluck('total', 'prod_item');

        return $lineas
            ->map(function (NotaDetalle $linea) use ($descripcionesAgile, $repetidosPorProd, $maeprods) {
                return $this->mapearFilaLinea(
                    $linea,
                    $descripcionesAgile,
                    (int) ($repetidosPorProd[$linea->prod_item] ?? 0),
                    $maeprods->get($linea->codigoProducto()),
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function filaLineaParaFormulario(Nota $nota, NotaDetalle $linea): array
    {
        $agileId = trim((string) ($linea->prod_item_agile ?? ''));
        $descripcionesAgile = $agileId === ''
            ? collect()
            : AgileMaeprod::query()
                ->where('prod_item_agile', $agileId)
                ->pluck('prod_descripcion_agile', 'prod_item_agile');

        $repetidos = (int) NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('prod_item', $linea->prod_item)
            ->count();

        return $this->mapearFilaLinea($linea, $descripcionesAgile, $repetidos, $linea->resolveProducto());
    }

    /**
     * @return array<string, mixed>
     */
    private function mapearFilaLinea(
        NotaDetalle $linea,
        Collection $descripcionesAgile,
        int $repetidos,
        ?Maeprod $producto = null,
    ): array {
        $producto ??= $linea->resolveProducto();
        $codigoProducto = $linea->codigoProducto();

        [$fechaFmt, $fechaAntigua] = ProdValorFechaUi::textoYAntigua(
            $producto?->prod_valor_fecha
        );

        $agileId = trim((string) ($linea->prod_item_agile ?? ''));
        $descripcionAgile = trim((string) ($linea->prod_descripcion_agile ?? ''));
        if ($descripcionAgile === '' && $agileId !== '') {
            $descripcionAgile = trim((string) ($descripcionesAgile[$agileId] ?? ''));
        }

        return [
            'linea' => $linea,
            'prod_nombre' => $producto?->prod_nombre
                ?? ($linea->prod_descripcion_agile ?: $codigoProducto),
            'prod_familia' => $producto?->prod_familia,
            'prod_imagen' => $producto?->imageUrl(),
            'image_url' => $producto?->imageUrl(),
            'prod_item_softland' => $producto?->prod_item_softland ?? '',
            'prod_item_agile' => $agileId,
            'prod_descripcion_agile' => $descripcionAgile,
            'pendiente_vinculo' => self::lineaPendienteVinculo($linea),
            'prod_valor_fecha' => $fechaFmt,
            'prod_valor_fecha_antigua' => $fechaAntigua,
            'total' => $linea->lineTotal(),
            'repetidos' => $repetidos,
        ];
    }

    public function actualizarLinea(
        Nota $nota,
        string $prodItem,
        int $orden,
        array $datos,
        ?string $usuarioUpd = null,
    ): void {
        DB::transaction(function () use ($nota, $prodItem, $orden, $datos, $usuarioUpd) {
            $linea = $this->resolverLineaParaGuardar($nota, $prodItem, $orden);
            $prodItem = $linea->prod_item;
            $orden = (int) $linea->orden;

            $producto = Maeprod::query()->find(trim((string) $prodItem));
            $prodValor = (int) ($datos['prod_valor'] ?? $linea->prod_valor);
            $cantidad = (int) ($datos['cantidad'] ?? $linea->cantidad);
            $costo = (int) ($datos['prod_valor_costo'] ?? $linea->prod_valor_costo);

            if ($producto) {
                $updates = [];
                if ($producto->prod_valor !== $prodValor) {
                    $updates['prod_valor'] = $prodValor;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if ((int) ($producto->prod_valor_costo ?? 0) !== $costo) {
                    $updates['prod_valor_costo'] = $costo;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if (array_key_exists('prod_item_softland', $datos)) {
                    $softland = trim((string) $datos['prod_item_softland']);
                    if ($softland !== (string) ($producto->prod_item_softland ?? '')) {
                        $updates['prod_item_softland'] = $softland;
                        $updates['prod_item_softland_fecha'] = now();
                    }
                }
                if ($updates !== []) {
                    $producto->update($updates);
                }
            }

            NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('prod_item', $prodItem)
                ->where('orden', $orden)
                ->update([
                    'prod_valor' => $prodValor,
                    'cantidad' => $cantidad,
                    'prod_valor_costo' => $costo,
                ]);

            $actualizada = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('prod_item', $prodItem)
                ->where('orden', $orden)
                ->first();

            if ($actualizada) {
                $this->sincronizarVinculoAgileMaeprod($actualizada, $usuarioUpd, VinculoOrigen::MANUAL);
            }
        });
    }

    /**
     * Persiste agilemaeprod aprendiendo descripción → prod_item (no por codigo_producto MP).
     */
    public function sincronizarVinculoAgileMaeprod(
        NotaDetalle $linea,
        ?string $usuario = null,
        VinculoOrigen $origen = VinculoOrigen::SISTEMA,
    ): void {
        $descripcionAgile = trim((string) ($linea->prod_descripcion_agile ?? ''));
        $agileId = trim((string) ($linea->prod_item_agile ?? ''));

        if ($descripcionAgile === '' && $agileId === '') {
            return;
        }

        if ($descripcionAgile !== '') {
            $this->agileMaeprodService->registrarSiNoExiste($agileId, $descripcionAgile);
        }

        $codigoInterno = $linea->codigoProducto();
        if ($codigoInterno === '' || $codigoInterno === '0' || $codigoInterno === $agileId) {
            return;
        }

        if (! Maeprod::query()->where('prod_item', $codigoInterno)->exists()) {
            return;
        }

        $this->agileMaeprodService->vincularCodigoInternoConDescripcion(
            $agileId,
            $codigoInterno,
            $descripcionAgile !== '' ? $descripcionAgile : null,
            $agileId !== '' ? $agileId : null,
            $usuario,
            $origen,
        );
    }

    public function guardarLineas(Nota $nota, array $lineas, ?string $usuarioUpd = null): void
    {
        foreach ($lineas as $data) {
            if (empty($data['prod_item']) || ! isset($data['orden'])) {
                continue;
            }
            $this->actualizarLinea(
                $nota,
                (string) $data['prod_item'],
                (int) $data['orden'],
                $data,
                $usuarioUpd,
            );
        }
    }

    /**
     * @return array{ok: int, total: int, factor: float, lineas: array<int, array{orden: int, prod_item: string, prod_valor: int, prod_valor_costo: int, subtotal: int}>}
     */
    public function aplicarFactorPrecioVenta(Nota $nota, float $factor, ?string $usuarioUpd = null): array
    {
        return DB::transaction(function () use ($nota, $factor, $usuarioUpd) {
            $factor = round($factor, 2);
            $nota->update(['factor_precio_venta' => $factor]);

            $lineas = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->orderBy('orden')
                ->get();

            $okCount = 0;
            $actualizadas = [];

            foreach ($lineas as $linea) {
                $costo = (int) ($linea->prod_valor_costo ?? 0);
                $valorActual = (int) $linea->prod_valor;
                $nuevoValor = $costo > 0 ? (int) round($costo * $factor) : $valorActual;

                $this->actualizarLinea($nota, $linea->prod_item, (int) $linea->orden, [
                    'prod_valor' => $nuevoValor,
                    'cantidad' => $linea->cantidad,
                    'prod_valor_costo' => $costo,
                    'prod_item_softland' => $linea->resolveProducto()?->prod_item_softland ?? '',
                ], $usuarioUpd);

                $actualizadas[] = [
                    'orden' => (int) $linea->orden,
                    'prod_item' => (string) $linea->prod_item,
                    'prod_valor' => $nuevoValor,
                    'prod_valor_costo' => $costo,
                    'subtotal' => $nuevoValor * (int) $linea->cantidad,
                ];

                $okCount++;
            }

            return [
                'ok' => $okCount,
                'total' => $lineas->count(),
                'factor' => $factor,
                'lineas' => $actualizadas,
            ];
        });
    }

    public function agregarLinea(
        Nota $nota,
        string $prodItem,
        int $cantidad,
        int $prodValor,
        ?int $prodValorCosto = null,
        ?string $usuarioUpd = null,
        ?string $prodItemAgile = null,
        ?string $prodDescripcionAgile = null,
    ): NotaDetalle {
        return DB::transaction(function () use ($nota, $prodItem, $cantidad, $prodValor, $prodValorCosto, $usuarioUpd, $prodItemAgile, $prodDescripcionAgile) {
            $producto = Maeprod::query()->find(trim($prodItem));
            $costo = $prodValorCosto ?? $producto?->prod_valor_costo ?? 0;

            if ($producto) {
                $updates = [];
                if ($producto->prod_valor !== $prodValor) {
                    $updates['prod_valor'] = $prodValor;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if ((int) ($producto->prod_valor_costo ?? 0) !== (int) $costo) {
                    $updates['prod_valor_costo'] = $costo;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if ($updates !== []) {
                    $producto->update($updates);
                }
            }

            $orden = ((int) NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->max('orden')) + 1;

            $agileId = trim((string) $prodItemAgile);
            $agileDesc = trim((string) $prodDescripcionAgile);

            $linea = NotaDetalle::create([
                'nronota' => $nota->nronota,
                'prod_item' => trim($prodItem),
                'prod_valor' => $prodValor,
                'cantidad' => $cantidad,
                'fechahora' => now(),
                'orden' => $orden ?: 1,
                'prod_valor_costo' => $costo,
                'prod_item_agile' => $agileId !== '' ? $agileId : null,
                'prod_descripcion_agile' => $agileDesc !== '' ? $agileDesc : null,
            ]);

            $this->sincronizarVinculoAgileMaeprod($linea, $usuarioUpd, VinculoOrigen::MANUAL);

            return $linea;
        });
    }

    public static function lineaPendienteVinculo(NotaDetalle $linea): bool
    {
        $agile = trim((string) ($linea->prod_item_agile ?? ''));
        if ($agile === '') {
            return false;
        }

        $codigo = trim((string) $linea->prod_item);
        if ($codigo === '' || $codigo === '0' || $codigo === $agile) {
            return true;
        }

        return $linea->resolveProducto() === null;
    }

    public function agregarLineaAgilePendiente(
        Nota $nota,
        string $idAgile,
        string $descripcionAgile,
        int $cantidad,
    ): NotaDetalle {
        $id = trim($idAgile);

        return $this->agregarLinea(
            $nota,
            $id,
            max(1, $cantidad),
            0,
            0,
            null,
            $id,
            $descripcionAgile,
        );
    }

    /**
     * @return array{
     *   prod_item: string,
     *   prod_nombre: string,
     *   prod_valor: int,
     *   prod_valor_costo: int,
     *   prod_valor_fecha_fmt: string,
     *   prod_valor_fecha_antigua: bool,
     *   subtotal: int
     * }
     */
    public function vincularLineaAgile(
        Nota $nota,
        int $orden,
        string $prodItemAgile,
        string $prodItemInterno,
        ?string $usuarioUpd = null,
        ?int $prodValor = null,
    ): array {
        $agileId = trim($prodItemAgile);
        $codigo = trim($prodItemInterno);

        if ($codigo === '' || $codigo === '0') {
            throw new \InvalidArgumentException('Debe seleccionar un producto del maestro.');
        }

        $linea = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('orden', $orden)
            ->where('prod_item_agile', $agileId)
            ->firstOrFail();

        $producto = Maeprod::query()->find($codigo);
        if (! $producto) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }

        $costo = (int) ($producto->prod_valor_costo ?? 0);
        if ($costo <= 0) {
            $costo = (int) ($producto->prod_valor ?? 0);
        }

        $factor = (float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta', 1.22));
        $valor = $prodValor ?? ($costo > 0 ? (int) round($costo * $factor) : (int) ($producto->prod_valor ?? 0));

        return DB::transaction(function () use ($nota, $linea, $agileId, $codigo, $producto, $costo, $valor, $usuarioUpd) {
            $payload = [
                'nronota' => $nota->nronota,
                'prod_item' => $codigo,
                'prod_valor' => $valor,
                'cantidad' => (int) $linea->cantidad,
                'fechahora' => $linea->fechahora ?? now(),
                'orden' => (int) $linea->orden,
                'prod_valor_costo' => $costo,
                'prod_item_agile' => $linea->prod_item_agile,
                'prod_descripcion_agile' => $linea->prod_descripcion_agile,
            ];

            if ($linea->prod_item !== $codigo) {
                NotaDetalle::query()
                    ->where('nronota', $nota->nronota)
                    ->where('prod_item', $linea->prod_item)
                    ->where('orden', $linea->orden)
                    ->delete();

                NotaDetalle::query()->create($payload);
            } else {
                NotaDetalle::query()
                    ->where('nronota', $nota->nronota)
                    ->where('prod_item', $codigo)
                    ->where('orden', $linea->orden)
                    ->update([
                        'prod_valor' => $valor,
                        'prod_valor_costo' => $costo,
                    ]);
            }

            $actualizada = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('orden', (int) $linea->orden)
                ->where('prod_item_agile', $agileId)
                ->firstOrFail();

            $this->sincronizarVinculoAgileMaeprod($actualizada, $usuarioUpd, VinculoOrigen::MANUAL);

            $updates = [];
            if ((int) ($producto->prod_valor ?? 0) !== $valor) {
                $updates['prod_valor'] = $valor;
                $updates['prod_valor_fecha'] = now();
                $updates['prod_user_upd'] = $usuarioUpd;
            }
            if ((int) ($producto->prod_valor_costo ?? 0) !== $costo) {
                $updates['prod_valor_costo'] = $costo;
                $updates['prod_valor_fecha'] = now();
                $updates['prod_user_upd'] = $usuarioUpd;
            }
            if ($updates !== []) {
                $producto->update($updates);
            }

            [$fechaFmt, $fechaAntigua] = ProdValorFechaUi::textoYAntigua($producto->fresh()->prod_valor_fecha);

            return [
                'prod_item' => $codigo,
                'prod_nombre' => (string) $producto->prod_nombre,
                'prod_valor' => $valor,
                'prod_valor_costo' => $costo,
                'prod_valor_fecha_fmt' => $fechaFmt,
                'prod_valor_fecha_antigua' => $fechaAntigua,
                'prod_item_agile' => $linea->prod_item_agile,
                'prod_descripcion_agile' => $linea->prod_descripcion_agile,
                'image_url' => $producto->imageUrl(),
                'subtotal' => $valor * (int) $linea->cantidad,
            ];
        });
    }

    public function eliminarLinea(Nota $nota, int $orden, ?string $prodItem = null): void
    {
        DB::transaction(function () use ($nota, $orden, $prodItem) {
            $linea = $this->resolverLineaPorOrden($nota, $orden, $prodItem);

            $eliminadas = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('prod_item', $linea->prod_item)
                ->where('orden', (int) $linea->orden)
                ->delete();

            if ($eliminadas === 0) {
                throw new \InvalidArgumentException('Línea no encontrada.');
            }

            $quedan = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->orderBy('orden')
                ->get();

            $this->persistirOrdenLineas($nota->nronota, $quedan);
        });
    }

    /**
     * @return list<array{prod_item: string, orden: int, prod_item_agile: string|null}>
     */
    public function lineasOrdenJson(Nota $nota): array
    {
        return NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->orderBy('orden')
            ->get(['prod_item', 'orden', 'prod_item_agile'])
            ->map(fn (NotaDetalle $linea) => [
                'prod_item' => $linea->prod_item,
                'orden' => (int) $linea->orden,
                'prod_item_agile' => $linea->prod_item_agile,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{total: int, con_agile: int, sin_agile: int}
     */
    public function resumenLineasNota(Nota $nota): array
    {
        $lineas = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->get(['prod_item_agile']);

        $conAgile = $lineas->filter(
            fn (NotaDetalle $linea) => trim((string) ($linea->prod_item_agile ?? '')) !== ''
        )->count();
        $total = $lineas->count();

        return [
            'total' => $total,
            'con_agile' => $conAgile,
            'sin_agile' => $total - $conAgile,
        ];
    }

    public function eliminarTodasLineasAgile(Nota $nota): int
    {
        $lineas = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->orderBy('orden')
            ->get();

        $aEliminar = $lineas->filter(function (NotaDetalle $linea) {
            return trim((string) ($linea->prod_item_agile ?? '')) !== '';
        });

        $eliminadas = $aEliminar->count();
        if ($eliminadas === 0) {
            return 0;
        }

        $quedan = $lineas->reject(
            fn (NotaDetalle $linea) => trim((string) ($linea->prod_item_agile ?? '')) !== ''
        )->values();

        DB::transaction(function () use ($nota, $aEliminar, $quedan) {
            foreach ($aEliminar as $linea) {
                NotaDetalle::query()
                    ->where('nronota', $nota->nronota)
                    ->where('prod_item', $linea->prod_item)
                    ->where('orden', (int) $linea->orden)
                    ->delete();
            }

            if ($quedan->isNotEmpty()) {
                $this->persistirOrdenLineas($nota->nronota, $quedan);
            }
        });

        return $eliminadas;
    }

    /**
     * @param  array<int, string>  $idsAgile
     * @return array<int, string>
     */
    public function idsAgileExistentesEnNota(Nota $nota, array $idsAgile): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            $idsAgile,
        ))));

        if ($ids === []) {
            return [];
        }

        return NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->whereNotNull('prod_item_agile')
            ->get()
            ->map(fn (NotaDetalle $linea) => trim((string) $linea->prod_item_agile))
            ->filter(fn (string $agile) => $agile !== '' && in_array($agile, $ids, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $idsAgile
     */
    public function eliminarLineasPorAgileIds(Nota $nota, array $idsAgile): int
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            $idsAgile,
        ))));

        if ($ids === []) {
            return 0;
        }

        $lineas = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->orderBy('orden')
            ->get();

        $quedan = $lineas->filter(function (NotaDetalle $linea) use ($ids) {
            $agile = trim((string) ($linea->prod_item_agile ?? ''));

            return $agile === '' || ! in_array($agile, $ids, true);
        })->values();

        $eliminadas = $lineas->count() - $quedan->count();
        if ($eliminadas === 0) {
            return 0;
        }

        $aEliminar = $lineas->reject(function (NotaDetalle $linea) use ($ids) {
            $agile = trim((string) ($linea->prod_item_agile ?? ''));

            return $agile === '' || ! in_array($agile, $ids, true);
        });

        DB::transaction(function () use ($nota, $aEliminar, $quedan) {
            foreach ($aEliminar as $linea) {
                NotaDetalle::query()
                    ->where('nronota', $nota->nronota)
                    ->where('prod_item', $linea->prod_item)
                    ->where('orden', (int) $linea->orden)
                    ->delete();
            }

            if ($quedan->isNotEmpty()) {
                $this->persistirOrdenLineas($nota->nronota, $quedan);
            }
        });

        return $eliminadas;
    }

    public function cambiarOrden(Nota $nota, string $prodItem, int $ordenAnterior, int $ordenNuevo): void
    {
        if ($ordenNuevo < 1) {
            throw new \InvalidArgumentException('El valor debe ser mayor a 0.');
        }

        $lineas = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->orderBy('orden')
            ->get()
            ->values();

        $totalLineas = $lineas->count();

        if ($ordenNuevo > $totalLineas) {
            throw new \InvalidArgumentException('El valor debe ser menor o igual al último número de orden.');
        }

        if ($ordenAnterior === $ordenNuevo) {
            return;
        }

        $lineaObjetivo = $this->resolverLineaPorOrden($nota, $ordenAnterior, $prodItem);

        $fromIndex = $lineas->search(
            fn (NotaDetalle $linea) => $linea->prod_item === $lineaObjetivo->prod_item
                && (int) $linea->orden === (int) $lineaObjetivo->orden
        );

        if ($fromIndex === false) {
            throw new \InvalidArgumentException('Línea no encontrada.');
        }

        $moving = $lineas->splice($fromIndex, 1)->first();
        $toIndex = max(0, min($ordenNuevo - 1, $lineas->count()));
        $lineas->splice($toIndex, 0, [$moving]);

        DB::transaction(fn () => $this->persistirOrdenLineas($nota->nronota, $lineas));
    }

    public function moverLineaRelativo(Nota $nota, string $prodItem, int $orden, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $this->cambiarOrden($nota, $prodItem, $orden, $orden + $delta);
    }

    /**
     * Reasigna orden sin borrar filas (la PK incluye orden; dos fases con valores negativos).
     *
     * @param  Collection<int, NotaDetalle>  $lineasOrdenadas
     */
    private function persistirOrdenLineas(int $nronota, Collection $lineasOrdenadas): void
    {
        $lineas = $lineasOrdenadas->values();

        if ($lineas->isEmpty()) {
            NotaDetalle::query()->where('nronota', $nronota)->delete();

            return;
        }

        foreach ($lineas as $index => $linea) {
            $tempOrden = -($index + 1);
            $actualizado = NotaDetalle::query()
                ->where('nronota', $nronota)
                ->where('prod_item', $linea->prod_item)
                ->where('orden', (int) $linea->orden)
                ->update(['orden' => $tempOrden]);

            if ($actualizado === 0) {
                throw new \InvalidArgumentException('Línea no encontrada al reordenar.');
            }
        }

        foreach ($lineas as $index => $linea) {
            $finalOrden = $index + 1;
            $tempOrden = -($index + 1);

            NotaDetalle::query()
                ->where('nronota', $nronota)
                ->where('prod_item', $linea->prod_item)
                ->where('orden', $tempOrden)
                ->update(['orden' => $finalOrden]);
        }
    }

    /**
     * Localiza una línea del detalle por posición (orden) dentro de la cotización.
     * prod_item es opcional y solo se usa si hubiera más de una fila con el mismo orden.
     */
    private function resolverLineaPorOrden(Nota $nota, int $orden, ?string $prodItem = null): NotaDetalle
    {
        $candidatas = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('orden', $orden)
            ->get();

        if ($candidatas->isEmpty()) {
            throw new \InvalidArgumentException('Línea no encontrada.');
        }

        if ($candidatas->count() === 1) {
            return $candidatas->first();
        }

        $prodItem = trim((string) $prodItem);
        if ($prodItem !== '') {
            $porProd = $candidatas->first(fn (NotaDetalle $linea) => $linea->prod_item === $prodItem);
            if ($porProd) {
                return $porProd;
            }
        }

        throw new \InvalidArgumentException('Línea no encontrada.');
    }

    /**
     * Resuelve la fila a actualizar tolerando desajustes prod_item/orden tras vincular Agile o reordenar.
     */
    private function resolverLineaParaGuardar(Nota $nota, string $prodItem, int $orden): NotaDetalle
    {
        $prodItem = trim($prodItem);

        $porClave = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('prod_item', $prodItem)
            ->where('orden', $orden)
            ->first();

        if ($porClave) {
            return $porClave;
        }

        $porOrden = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('orden', $orden)
            ->get();

        if ($porOrden->count() === 1) {
            return $porOrden->first();
        }

        if ($prodItem !== '') {
            $porProd = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('prod_item', $prodItem)
                ->get();

            if ($porProd->count() === 1) {
                return $porProd->first();
            }
        }

        throw new \InvalidArgumentException(
            'No se encontró la línea (producto «'.$prodItem.'», orden '.$orden.'). Recargue la página e intente de nuevo.',
        );
    }

    public function buscarProductos(?string $term, ?string $familia = null, ?int $limit = null): Collection
    {
        $term = trim((string) $term);
        $minChars = max(1, (int) config('cotiz.buscar_productos_min_chars', 2));

        if (mb_strlen($term) < $minChars) {
            return collect();
        }

        $limit = $limit ?? (int) config('cotiz.buscar_productos_limite', 15);
        $maxLimite = max(1, (int) config('cotiz.buscar_productos_max_limite', 50));
        $limit = min(max(1, $limit), $maxLimite);

        return $this->busquedaSimilitud->buscar($term, $familia, $limit);
    }
}
