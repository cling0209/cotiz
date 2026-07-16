<?php

namespace App\Enums;

/**
 * Origen de la vinculación descripción Agile → producto maeprod en agilemaeprod.
 */
enum VinculoOrigen: string
{
    /** Confirmado al grabar la cotización en el panel. */
    case MANUAL = 'manual';

    /** Confirmado al generar/descargar el PDF de la cotización. */
    case PDF = 'pdf';

    /** Vinculado automáticamente al recibir una cotización por API Agile. */
    case API = 'api';

    /** Sin origen atribuible (fallback). */
    case SISTEMA = 'sistema';
}
