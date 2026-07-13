<?php

namespace App\Enums;

/**
 * Origen de la vinculación descripción Agile → producto maeprod en agilemaeprod.
 */
enum VinculoOrigen: string
{
    /** Vinculado/editado por un usuario en el panel. */
    case MANUAL = 'manual';

    /** Vinculado automáticamente al recibir una cotización por API Agile. */
    case API = 'api';

    /** Sin origen atribuible (fallback). */
    case SISTEMA = 'sistema';
}
