<?php

namespace App\Enums;

/**
 * Qué se muestra en la columna «Código OC» de un seguimiento Compra Ágil.
 * Solo la empresa de esta instancia (cotiz.empresa_rut) es propia; el resto
 * del grupo (Reicol/Romulo) se trata como otra empresa.
 */
enum EstadoOrdenCompraMp: string
{
    /** Ganamos y notas.ocompra ya tiene el código AG. */
    case CODIGO = 'codigo';

    /** MP emitió la OC a una empresa distinta de la propia: no se sigue consultando. */
    case OTRA_EMPRESA = 'otra_empresa';

    /** Ganamos (proveedor seleccionado) pero MP aún no emite la OC. */
    case POR_EMITIR = 'por_emitir';

    /** Ganamos, OC emitida, falta el código AG y sigue dentro del plazo de búsqueda. */
    case BUSCANDO = 'buscando';

    /** Ganamos, OC emitida y venció el plazo sin encontrar el código AG. */
    case NO_ENCONTRADO = 'no_encontrado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::CODIGO => 'Código OC',
            self::OTRA_EMPRESA => 'OC entregada a otra empresa',
            self::POR_EMITIR => 'OC por emitir',
            self::BUSCANDO => 'Buscando código OC',
            self::NO_ENCONTRADO => 'Código OC no encontrado',
        };
    }

    public function claseCss(): string
    {
        return match ($this) {
            self::CODIGO => 'font-monospace',
            self::OTRA_EMPRESA => 'text-secondary',
            self::POR_EMITIR => 'text-info',
            self::BUSCANDO => 'text-warning',
            self::NO_ENCONTRADO => 'text-danger',
        };
    }
}
