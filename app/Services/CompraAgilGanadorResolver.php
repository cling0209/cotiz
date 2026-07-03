<?php

namespace App\Services;

/**
 * Resuelve proveedor ganador desde payload detalle Compra Ágil v2.
 */
class CompraAgilGanadorResolver
{
    public function __construct(
        protected CompraAgilTextoParserService $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function proveedoresAdjudicados(array $payload): array
    {
        $proveedores = is_array($payload['proveedores_cotizando'] ?? null)
            ? $payload['proveedores_cotizando']
            : [];
        $idOrdenCompra = isset($payload['id_orden_compra']) ? (int) $payload['id_orden_compra'] : null;

        $adjudicados = [];
        foreach ($proveedores as $prov) {
            if (! is_array($prov)) {
                continue;
            }
            if ($this->esProveedorGanador($prov, $idOrdenCompra)) {
                $adjudicados[] = $prov;
            }
        }

        if ($adjudicados !== []) {
            return $adjudicados;
        }

        if (count($proveedores) === 1 && is_array($proveedores[0])) {
            return [$proveedores[0]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function tieneProveedorAdjudicado(array $payload): bool
    {
        return $this->proveedoresAdjudicados($payload) !== [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ganadorPrincipal(array $payload): ?array
    {
        $adjudicados = $this->proveedoresAdjudicados($payload);

        return $adjudicados[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function rutGanador(array $payload): ?string
    {
        $ganador = $this->ganadorPrincipal($payload);
        if ($ganador === null) {
            return null;
        }
        $rut = trim((string) ($ganador['rut_proveedor'] ?? ''));
        if ($rut === '') {
            return null;
        }

        return $this->parser->normalizarRut($rut);
    }

    /**
     * @param  array<string, mixed>  $prov
     */
    public function esProveedorGanador(array $prov, ?int $idOrdenCompra = null): bool
    {
        if (! empty($prov['seleccion']['proveedor_seleccionado'])) {
            return true;
        }

        if ((int) ($prov['proveedor_seleccionado'] ?? 0) === 1) {
            return true;
        }

        $activo = (int) ($prov['activo'] ?? 0) === 1;
        $idOc = isset($prov['id_oc']) ? (int) $prov['id_oc'] : null;
        if ($activo && $idOrdenCompra !== null && $idOc === $idOrdenCompra) {
            return true;
        }

        return false;
    }

    public function rutEmpresaPropia(): string
    {
        $rut = trim((string) config('cotiz.empresa_rut', ''));

        return $rut !== '' ? $this->parser->normalizarRut($rut) : '';
    }

    public function rutsCoinciden(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return false;
        }

        $na = preg_replace('/[^0-9kK]/', '', $this->parser->normalizarRut($a)) ?? '';
        $nb = preg_replace('/[^0-9kK]/', '', $this->parser->normalizarRut($b)) ?? '';

        return $na !== '' && $na === $nb;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, float>
     */
    public function preciosGanadorPorProducto(array $payload): array
    {
        $out = [];
        foreach ($this->proveedoresAdjudicados($payload) as $prov) {
            $productos = is_array($prov['productos_cotizados'] ?? null) ? $prov['productos_cotizados'] : [];
            foreach ($productos as $linea) {
                if (! is_array($linea)) {
                    continue;
                }
                $cod = trim((string) ($linea['codigo_producto'] ?? ''));
                $precio = $linea['precio_unitario'] ?? null;
                if ($cod !== '' && $precio !== null && ! isset($out[$cod])) {
                    $out[$cod] = (float) $precio;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function codigoEstadoMp(array $payload): string
    {
        $estado = is_array($payload['estado'] ?? null) ? $payload['estado'] : [];

        return trim((string) ($estado['codigo'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function glosaEstadoMp(array $payload): string
    {
        $estado = is_array($payload['estado'] ?? null) ? $payload['estado'] : [];

        return trim((string) ($estado['glosa'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function esEstadoFinal(array $payload): bool
    {
        $codigo = $this->codigoEstadoMp($payload);

        return in_array($codigo, [
            'proveedor_seleccionado',
            'oc_emitida',
            'desierta',
            'cancelada',
        ], true) || ($codigo === 'cerrada' && $this->tieneProveedorAdjudicado($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resultadoPropio(array $payload): string
    {
        $codigo = $this->codigoEstadoMp($payload);

        if ($codigo === 'desierta') {
            return 'desierta';
        }
        if ($codigo === 'cancelada') {
            return 'cancelada';
        }

        $ganador = $this->ganadorPrincipal($payload);
        if ($ganador === null) {
            return 'pendiente';
        }

        $rutGanador = $this->rutGanador($payload);
        $rutPropio = $this->rutEmpresaPropia();
        if ($rutPropio !== '' && $this->rutsCoinciden($rutGanador, $rutPropio)) {
            return 'ganada';
        }

        $proveedores = is_array($payload['proveedores_cotizando'] ?? null) ? $payload['proveedores_cotizando'] : [];
        foreach ($proveedores as $prov) {
            if (! is_array($prov)) {
                continue;
            }
            $rut = trim((string) ($prov['rut_proveedor'] ?? ''));
            if ($rut !== '' && $this->rutsCoinciden($this->parser->normalizarRut($rut), $rutPropio)) {
                return 'perdida';
            }
        }

        return 'no_participo';
    }
}
