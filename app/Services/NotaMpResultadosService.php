<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\NotaMpCorrida;
use App\Models\NotaMpCorridaCambio;
use App\Models\NotaMpOferta;
use App\Models\NotaMpOfertaLinea;
use App\Models\NotaMpSeguimiento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NotaMpResultadosService
{
    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilGanadorResolver $ganador,
        protected CompraAgilTextoParserService $parser,
    ) {}

    public function ultimaCorrida(): ?NotaMpCorrida
    {
        return NotaMpCorrida::query()
            ->whereIn('estado', ['ok', 'error', 'cancelled'])
            ->orderByDesc('id')
            ->first();
    }

    public function corridaEnCurso(): ?NotaMpCorrida
    {
        return NotaMpCorrida::query()
            ->where('estado', 'running')
            ->orderByDesc('id')
            ->first();
    }

    public function apiConfigurada(): bool
    {
        return $this->api->isConfigured();
    }

    public function esCodigoCompraAgil(string $codigo): bool
    {
        $codigo = strtoupper(trim($codigo));

        return $codigo !== '' && (bool) preg_match('/^\d+-\d+-COT\d+$/', $codigo);
    }

    /**
     * @return Collection<int, array{nronota: int, codigo: string, fecha: ?string, empresa: string}>
     */
    public function notasPendientesConsulta(?int $limite = null): Collection
    {
        $limite = $limite ?? max(1, (int) config('cotiz.mercadopublico.resultados_max_por_corrida', 50));

        $query = Nota::query()
            ->select(['notas.nronota', 'notas.encargado', 'notas.fecha', 'notas.empresa'])
            ->leftJoin('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota')
            ->whereRaw("trim(coalesce(notas.encargado, '')) <> ''")
            ->where(function ($q) {
                $q->whereNull('seg.nronota')
                    ->orWhereRaw('seg.finalizado IS FALSE');
            })
            ->orderBy('notas.fecha')
            ->orderBy('notas.nronota')
            ->limit($limite * 3);

        return $query->get()
            ->filter(fn (Nota $nota) => $this->esCodigoCompraAgil((string) $nota->encargado))
            ->take($limite)
            ->values()
            ->map(fn (Nota $nota) => [
                'nronota' => (int) $nota->nronota,
                'codigo' => strtoupper(trim((string) $nota->encargado)),
                'fecha' => $nota->fecha?->format('Y-m-d'),
                'empresa' => trim((string) ($nota->empresa ?? '')),
            ]);
    }

    public function iniciarCorrida(string $usuario): NotaMpCorrida
    {
        $enCurso = $this->corridaEnCurso();
        if ($enCurso !== null) {
            $enCurso->update([
                'fin' => now(),
                'estado' => 'cancelled',
                'mensaje' => 'Reemplazada por nueva corrida.',
            ]);
        }

        return NotaMpCorrida::query()->create([
            'usuario' => trim($usuario),
            'inicio' => now(),
            'estado' => 'running',
        ]);
    }

    public function finalizarCorrida(NotaMpCorrida $corrida, string $estado = 'ok', ?string $mensaje = null): NotaMpCorrida
    {
        $corrida->update([
            'fin' => now(),
            'estado' => $estado,
            'mensaje' => $mensaje,
        ]);

        return $corrida->fresh() ?? $corrida;
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarNota(int $nronota, NotaMpCorrida $corrida, string $usuario): array
    {
        if (! $this->api->isConfigured()) {
            throw new RuntimeException('MERCADOPUBLICO_TICKET no configurado.');
        }

        $nota = Nota::query()->find($nronota);
        if ($nota === null) {
            throw new RuntimeException('Cotización no encontrada.');
        }

        $codigo = strtoupper(trim((string) $nota->encargado));
        if (! $this->esCodigoCompraAgil($codigo)) {
            throw new RuntimeException('La nota no tiene un código Compra Ágil válido en encargado.');
        }

        $anterior = NotaMpSeguimiento::query()->find($nronota);
        $estadoAnterior = $anterior?->estado_mp_codigo;

        $payload = $this->api->detalle($codigo, usarCache: false);

        $ganadorProv = $this->ganador->ganadorPrincipal($payload);
        $institucion = is_array($payload['institucion'] ?? null) ? $payload['institucion'] : [];
        $estadoCodigo = $this->ganador->codigoEstadoMp($payload);
        $estadoGlosa = $this->ganador->glosaEstadoMp($payload);
        $resultadoPropio = $this->ganador->resultadoPropio($payload);
        $finalizado = $this->ganador->esEstadoFinal($payload)
            || in_array($resultadoPropio, ['ganada', 'perdida', 'desierta', 'cancelada', 'no_participo'], true);

        if ($estadoCodigo === 'cerrada' && ! $this->ganador->tieneProveedorAdjudicado($payload)) {
            $finalizado = false;
            if ($resultadoPropio !== 'desierta' && $resultadoPropio !== 'cancelada') {
                $resultadoPropio = 'pendiente';
            }
        }

        $rutGanador = $this->ganador->rutGanador($payload);
        $montoGanador = $ganadorProv !== null ? (int) round((float) ($ganadorProv['monto_total'] ?? 0)) : null;

        DB::transaction(function () use (
            $nronota,
            $codigo,
            $estadoCodigo,
            $estadoGlosa,
            $institucion,
            $rutGanador,
            $ganadorProv,
            $payload,
            $montoGanador,
            $resultadoPropio,
            $finalizado,
            $usuario,
            $corrida,
            $estadoAnterior,
            $anterior,
        ) {
            NotaMpSeguimiento::query()->updateOrCreate(
                ['nronota' => $nronota],
                [
                    'codigo_proceso' => $codigo,
                    'estado_mp_codigo' => $estadoCodigo,
                    'estado_mp_glosa' => $estadoGlosa,
                    'organismo' => mb_substr(trim((string) ($institucion['organismo_comprador'] ?? '')), 0, 200),
                    'rut_ganador' => $rutGanador,
                    'razon_social_ganador' => $ganadorProv !== null
                        ? mb_substr(trim((string) ($ganadorProv['razon_social'] ?? '')), 0, 200)
                        : null,
                    'id_orden_compra' => isset($payload['id_orden_compra']) ? (int) $payload['id_orden_compra'] : null,
                    'monto_total_ganador' => $montoGanador,
                    'resultado_propio' => $resultadoPropio,
                    'finalizado' => $finalizado,
                    'ultimo_usuario' => trim($usuario),
                    'ultimo_consultado_en' => now(),
                    'ultima_corrida_id' => $corrida->id,
                ],
            );

            $this->persistirOfertas($nronota, $payload);

            $cambio = $estadoAnterior !== $estadoCodigo
                || ($anterior?->resultado_propio !== $resultadoPropio)
                || ($anterior?->rut_ganador !== $rutGanador);

            if ($cambio) {
                NotaMpCorridaCambio::query()->create([
                    'corrida_id' => $corrida->id,
                    'nronota' => $nronota,
                    'codigo_proceso' => $codigo,
                    'estado_anterior' => $estadoAnterior,
                    'estado_nuevo' => $estadoCodigo,
                    'resultado_propio' => $resultadoPropio,
                    'rut_ganador' => $rutGanador,
                    'razon_social_ganador' => $ganadorProv !== null
                        ? mb_substr(trim((string) ($ganadorProv['razon_social'] ?? '')), 0, 200)
                        : null,
                ]);

                $corrida->increment('notas_con_cambio');
            }

            $corrida->increment('notas_procesadas');
        });

        $corrida->refresh();

        return [
            'nronota' => $nronota,
            'codigo' => $codigo,
            'empresa' => trim((string) ($nota->empresa ?? '')),
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoCodigo,
            'estado_glosa' => $estadoGlosa,
            'cambio' => $estadoAnterior !== $estadoCodigo
                || ($anterior?->resultado_propio !== $resultadoPropio)
                || ($anterior?->rut_ganador !== $rutGanador),
            'finalizado' => $finalizado,
            'grupo' => $finalizado ? 'cerradas' : 'pendientes',
            'resultado_propio' => $resultadoPropio,
            'rut_ganador' => $rutGanador,
            'razon_social_ganador' => $ganadorProv !== null ? trim((string) ($ganadorProv['razon_social'] ?? '')) : null,
            'monto_total_ganador' => $montoGanador,
            'id_orden_compra' => isset($payload['id_orden_compra']) ? (int) $payload['id_orden_compra'] : null,
            'organismo' => trim((string) ($institucion['organismo_comprador'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistirOfertas(int $nronota, array $payload): void
    {
        NotaMpOferta::query()->where('nronota', $nronota)->each(function (NotaMpOferta $oferta) {
            $oferta->lineas()->delete();
            $oferta->delete();
        });

        $rutPropio = $this->ganador->rutEmpresaPropia();
        $idOrdenCompra = isset($payload['id_orden_compra']) ? (int) $payload['id_orden_compra'] : null;
        $proveedores = is_array($payload['proveedores_cotizando'] ?? null) ? $payload['proveedores_cotizando'] : [];

        foreach ($proveedores as $prov) {
            if (! is_array($prov)) {
                continue;
            }

            $rut = trim((string) ($prov['rut_proveedor'] ?? ''));
            $esGanador = $this->ganador->esProveedorGanador($prov, $idOrdenCompra);

            $oferta = NotaMpOferta::query()->create([
                'nronota' => $nronota,
                'id_cotizacion_mp' => isset($prov['id_cotizacion']) ? (int) $prov['id_cotizacion'] : null,
                'rut_proveedor' => $rut !== '' ? $this->parser->normalizarRut($rut) : null,
                'razon_social' => mb_substr(trim((string) ($prov['razon_social'] ?? '')), 0, 200),
                'proveedor_seleccionado' => $esGanador,
                'monto_total' => isset($prov['monto_total']) ? (int) round((float) $prov['monto_total']) : null,
                'es_propio' => $rutPropio !== '' && $this->ganador->rutsCoinciden(
                    $rut !== '' ? $this->parser->normalizarRut($rut) : null,
                    $rutPropio,
                ),
                'inadmisible' => (int) ($prov['estado'] ?? 0) === 3,
                'id_oc' => isset($prov['id_oc']) ? (int) $prov['id_oc'] : null,
            ]);

            $productos = is_array($prov['productos_cotizados'] ?? null) ? $prov['productos_cotizados'] : [];
            foreach ($productos as $linea) {
                if (! is_array($linea)) {
                    continue;
                }
                NotaMpOfertaLinea::query()->create([
                    'oferta_id' => $oferta->id,
                    'codigo_producto' => trim((string) ($linea['codigo_producto'] ?? '')),
                    'nombre_producto' => mb_substr(trim((string) ($linea['nombre_producto'] ?? $linea['nombre'] ?? '')), 0, 500),
                    'descripcion' => mb_substr(trim((string) ($linea['descripcion'] ?? '')), 0, 500),
                    'cantidad' => (float) ($linea['cantidad'] ?? 0),
                    'precio_unitario' => isset($linea['precio_unitario']) ? (int) round((float) $linea['precio_unitario']) : null,
                    'monto_total' => isset($linea['monto_total_producto']) ? (int) round((float) $linea['monto_total_producto']) : null,
                ]);
            }
        }
    }

    /**
     * @return array{total: int, ganadas: int, perdidas: int, pendientes: int, desiertas: int}
     */
    public function resumenEstadistica(): array
    {
        $rows = NotaMpSeguimiento::query()
            ->whereRaw('finalizado IS TRUE')
            ->selectRaw('resultado_propio, count(*) as total')
            ->groupBy('resultado_propio')
            ->pluck('total', 'resultado_propio');

        $ganadas = (int) ($rows['ganada'] ?? 0);
        $perdidas = (int) ($rows['perdida'] ?? 0);
        $desiertas = (int) ($rows['desierta'] ?? 0) + (int) ($rows['cancelada'] ?? 0);

        return [
            'total' => (int) $rows->sum(),
            'ganadas' => $ganadas,
            'perdidas' => $perdidas,
            'pendientes' => (int) NotaMpSeguimiento::query()->whereRaw('finalizado IS FALSE')->count(),
            'desiertas' => $desiertas,
        ];
    }

    /**
     * @return Collection<int, NotaMpSeguimiento>
     */
    public function listadoCerradas(int $limite = 50): Collection
    {
        return NotaMpSeguimiento::query()
            ->with(['nota', 'ofertas' => fn ($q) => $q->whereRaw('proveedor_seleccionado IS TRUE')->with('lineas')])
            ->whereRaw('finalizado IS TRUE')
            ->orderByDesc('ultimo_consultado_en')
            ->limit($limite)
            ->get();
    }

    /**
     * @return Collection<int, NotaMpCorridaCambio>
     */
    public function novedadesUltimaCorrida(?NotaMpCorrida $corrida = null): Collection
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return collect();
        }

        return NotaMpCorridaCambio::query()
            ->with('nota')
            ->where('corrida_id', $corrida->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function detalleNota(int $nronota): array
    {
        $seg = NotaMpSeguimiento::query()
            ->with(['nota', 'ofertas.lineas'])
            ->find($nronota);

        if ($seg === null) {
            throw new RuntimeException('Sin seguimiento MP para esta nota.');
        }

        return [
            'seguimiento' => $seg,
            'ofertas' => $seg->ofertas,
            'lineas_ganador' => $seg->ofertas
                ->first(fn (NotaMpOferta $o) => $o->proveedor_seleccionado)
                ?->lineas ?? collect(),
        ];
    }
}
