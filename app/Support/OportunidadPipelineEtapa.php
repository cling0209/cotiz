<?php

namespace App\Support;

/**
 * Etapas del pipeline automático (Para cotizar / Rómulo).
 */
final class OportunidadPipelineEtapa
{
    public const BUSQUEDA = 'busqueda';

    public const VINCULO = 'vinculo';

    public const SYNC_COTIZACIONES = 'sync_cotizaciones';

    public const SYNC_VINCULACIONES = 'sync_vinculaciones';

    public const ADJUNTOS = 'adjuntos';

    public const PURGE = 'purge';

    public const CATCHUP = 'catchup';

    public const CAMBIOS_ESTADO = 'cambios_estado';

    /** @var array<string, string> */
    private const LABELS = [
        self::BUSQUEDA => 'Búsqueda de cotizaciones',
        self::VINCULO => 'Vinculaciones internas',
        self::SYNC_COTIZACIONES => 'Sync cotizaciones al par',
        self::SYNC_VINCULACIONES => 'Sync vinculaciones al par',
        self::ADJUNTOS => 'Adjuntos Mercado Público',
        self::PURGE => 'Limpieza de adjuntos cerrados',
        self::CATCHUP => 'Búsqueda catch-up',
        self::CAMBIOS_ESTADO => 'Cambios de estado',
    ];

    public static function label(string $id): string
    {
        return self::LABELS[$id] ?? $id;
    }

    /**
     * @return array{proceso: string, label: string, detalle: string|null}|null
     */
    public static function resolverActivo(
        ?array $busqueda,
        ?array $vinculo,
        ?array $adjuntoCorrida,
    ): ?array {
        if (($busqueda['estado'] ?? null) === 'running') {
            return self::activo(self::BUSQUEDA, self::detalleProgreso($busqueda));
        }
        if (($vinculo['estado'] ?? null) === 'running') {
            return self::activo(self::VINCULO, self::detalleProgreso($vinculo));
        }
        $purge = is_array($adjuntoCorrida['purge'] ?? null) ? $adjuntoCorrida['purge'] : null;
        if ($purge !== null && in_array((string) ($purge['estado'] ?? ''), ['pending', 'running'], true)) {
            return self::activo(self::PURGE, (string) ($purge['mensaje'] ?? ''));
        }
        if (($adjuntoCorrida['estado'] ?? null) === 'running') {
            return self::activo(self::ADJUNTOS, self::detalleProgreso($adjuntoCorrida));
        }

        return null;
    }

    /**
     * @return array{proceso: string, label: string, detalle: string|null}
     */
    private static function activo(string $proceso, ?string $detalle): array
    {
        return [
            'proceso' => $proceso,
            'label' => self::label($proceso),
            'detalle' => $detalle !== '' ? $detalle : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $corrida
     */
    private static function detalleProgreso(array $corrida): ?string
    {
        $total = (int) ($corrida['total_pasos'] ?? 0);
        $hechos = (int) ($corrida['pasos_procesados'] ?? 0);
        if ($total > 0) {
            return $hechos.'/'.$total;
        }
        $msg = trim((string) ($corrida['mensaje'] ?? ''));

        return $msg !== '' ? $msg : null;
    }

    /**
     * @return array{sin_mas_automatico: bool, mensaje: string, fallidas_mp?: int, siguiente_proceso: string, siguiente_proceso_label: string}
     */
    public static function cierreVinculo(int $fallidasMp): array
    {
        $mensaje = $fallidasMp > 0
            ? 'Sin más cotizaciones por vincular ('.$fallidasMp.' sin vinc. MP — reintento manual).'
            : 'Sin más cotizaciones por vincular.';

        return [
            'sin_mas_automatico' => true,
            'mensaje' => $mensaje,
            'fallidas_mp' => $fallidasMp,
            'siguiente_proceso' => self::ADJUNTOS,
            'siguiente_proceso_label' => self::label(self::ADJUNTOS),
        ];
    }

    /**
     * @return array{sin_mas_automatico: bool, mensaje: string, siguiente_proceso: string, siguiente_proceso_label: string}
     */
    public static function cierreAdjuntos(int $fallidos): array
    {
        $mensaje = $fallidos > 0
            ? 'Sin más adjuntos por descargar ('.$fallidos.' fallo'.($fallidos === 1 ? '' : 's').').'
            : 'Sin más adjuntos por descargar.';

        return [
            'sin_mas_automatico' => true,
            'mensaje' => $mensaje,
            'siguiente_proceso' => self::PURGE,
            'siguiente_proceso_label' => self::label(self::PURGE),
        ];
    }

    /**
     * @return array{sin_mas_automatico: bool, mensaje: string, siguiente_proceso: string, siguiente_proceso_label: string}
     */
    public static function cierrePurge(): array
    {
        return [
            'sin_mas_automatico' => true,
            'mensaje' => 'Limpieza de adjuntos cerrados terminada.',
            'siguiente_proceso' => self::CATCHUP,
            'siguiente_proceso_label' => self::label(self::CATCHUP).' o '.self::label(self::CAMBIOS_ESTADO),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     * @return list<array{codigo: string, estado: string, at: string|null}>
     */
    public static function ultimosPasosDelPlan(array $pasos, int $limite = 5): array
    {
        $recientes = [];
        foreach ($pasos as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            $estado = (string) ($paso['estado'] ?? '');
            if (! in_array($estado, ['ok', 'failed'], true)) {
                continue;
            }
            $codigo = strtoupper(trim((string) ($paso['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }
            $recientes[] = [
                'codigo' => $codigo,
                'estado' => $estado,
                'at' => isset($paso['fin']) ? (string) $paso['fin'] : (isset($paso['inicio']) ? (string) $paso['inicio'] : null),
            ];
        }

        if (count($recientes) <= $limite) {
            return $recientes;
        }

        return array_slice($recientes, -$limite);
    }
}
