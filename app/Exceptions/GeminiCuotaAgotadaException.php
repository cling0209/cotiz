<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Gemini respondió 429: sin cuota gratuita disponible (por minuto o por día).
 */
class GeminiCuotaAgotadaException extends RuntimeException {}
