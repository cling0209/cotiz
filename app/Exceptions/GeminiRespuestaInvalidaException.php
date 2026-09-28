<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Gemini respondió 200 pero el texto no contiene un JSON legible.
 */
class GeminiRespuestaInvalidaException extends RuntimeException {}
