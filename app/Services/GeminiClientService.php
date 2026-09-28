<?php

namespace App\Services;

use App\Exceptions\GeminiCuotaAgotadaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente REST de Google Gemini (generateContent).
 */
class GeminiClientService
{
    public function isConfigured(): bool
    {
        return trim((string) config('cotiz.gemini.api_key', '')) !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $parts  partes del mensaje de usuario (text / inline_data)
     * @param  array{json?: bool, google_search?: bool, system?: string, temperature?: float}  $opciones
     * @return array{json: mixed, texto: string, fuentes: list<array{uri: string, title: string}>}
     *
     * @throws GeminiCuotaAgotadaException
     * @throws RuntimeException
     */
    public function generar(array $parts, array $opciones = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Gemini no está configurado. Defina GEMINI_API_KEY en el servidor.');
        }

        $conBusqueda = (bool) ($opciones['google_search'] ?? false);
        $esperaJson = (bool) ($opciones['json'] ?? true);

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => (float) ($opciones['temperature'] ?? 0.1),
            ],
        ];

        // La API no admite responseMimeType JSON junto con la herramienta google_search.
        if ($esperaJson && ! $conBusqueda) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }
        if ($conBusqueda) {
            $payload['tools'] = [['google_search' => (object) []]];
        }
        $system = trim((string) ($opciones['system'] ?? ''));
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $response = $this->enviarConReintento($payload);
        $data = $response->json();
        $texto = $this->textoRespuesta(is_array($data) ? $data : []);

        return [
            'json' => $esperaJson ? $this->decodificarJson($texto) : null,
            'texto' => $texto,
            'fuentes' => $this->fuentesGrounding(is_array($data) ? $data : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enviarConReintento(array $payload): Response
    {
        $modelos = array_values(array_unique(array_filter(array_map('trim', array_merge(
            [(string) config('cotiz.gemini.model', 'gemini-3.8-flash')],
            (array) config('cotiz.gemini.modelos_respaldo', []),
        )))));

        $ultimoError = 'Gemini no respondió.';
        $todosSinCuota = true;
        foreach ($modelos as $model) {
            $resultado = $this->enviarAModelo($model, $payload);
            if ($resultado instanceof Response) {
                return $resultado;
            }
            [$status, $ultimoError] = $resultado;
            $todosSinCuota = $todosSinCuota && $status === 429;
            // 400/401/403: el pedido o la key son inválidos; otro modelo no lo arregla.
            if (! in_array($status, [0, 404, 429], true) && $status < 500) {
                throw new RuntimeException($ultimoError);
            }
        }

        if ($todosSinCuota) {
            throw new GeminiCuotaAgotadaException(
                'Gemini sin cuota gratuita disponible por ahora (límite por minuto o diario).',
            );
        }

        throw new RuntimeException($ultimoError);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Response|array{0: int, 1: string} respuesta exitosa o [status (0 = conexión), mensaje]
     */
    private function enviarAModelo(string $model, array $payload): Response|array
    {
        $url = config('cotiz.gemini.endpoint').'/models/'.rawurlencode($model).':generateContent';
        $timeout = (int) config('cotiz.gemini.timeout', 120);
        $esperaMs = (int) config('cotiz.gemini.reintento_espera_ms', 2500);
        $intentos = 2;

        $fallo = [0, 'Gemini no respondió.'];
        for ($intento = 1; $intento <= $intentos; $intento++) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(20)
                    ->withHeaders(['x-goog-api-key' => (string) config('cotiz.gemini.api_key')])
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);
            } catch (ConnectionException $e) {
                $fallo = [0, 'No se pudo conectar con Gemini: '.$e->getMessage()];
                if ($intento < $intentos) {
                    usleep($esperaMs * 1000);
                }

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $mensaje = trim((string) ($response->json('error.message') ?? ''));
            Log::warning('Gemini: respuesta HTTP no exitosa', [
                'status' => $status,
                'model' => $model,
                'intento' => $intento,
                'message' => mb_substr($mensaje, 0, 500),
            ]);

            $fallo = [$status, 'Gemini respondió HTTP '.$status.($mensaje !== '' ? ': '.mb_substr($mensaje, 0, 300) : '')];
            if ($status < 500 || $intento >= $intentos) {
                break;
            }
            usleep($esperaMs * $intento * 1000);
        }

        return $fallo;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function textoRespuesta(array $data): string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts)) {
            return '';
        }

        $texto = '';
        foreach ($parts as $part) {
            if (! is_array($part) || ! empty($part['thought'])) {
                continue;
            }
            $texto .= (string) ($part['text'] ?? '');
        }

        return trim($texto);
    }

    private function decodificarJson(string $texto): mixed
    {
        $limpio = trim($texto);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/su', $limpio, $m)) {
            $limpio = $m[1];
        }

        $data = json_decode($limpio, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // Respuesta con texto alrededor: tomar el primer bloque {...} o [...].
        $ini = strcspn($limpio, '{[');
        $finObj = strrpos($limpio, '}');
        $finArr = strrpos($limpio, ']');
        $fin = max($finObj === false ? -1 : $finObj, $finArr === false ? -1 : $finArr);
        if ($ini < strlen($limpio) && $fin > $ini) {
            $data = json_decode(substr($limpio, $ini, $fin - $ini + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        throw new RuntimeException('Gemini devolvió una respuesta que no es JSON válido.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{uri: string, title: string}>
     */
    private function fuentesGrounding(array $data): array
    {
        $chunks = $data['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
        if (! is_array($chunks)) {
            return [];
        }

        $out = [];
        foreach ($chunks as $chunk) {
            $web = is_array($chunk) ? ($chunk['web'] ?? null) : null;
            if (! is_array($web)) {
                continue;
            }
            $out[] = [
                'uri' => (string) ($web['uri'] ?? ''),
                'title' => (string) ($web['title'] ?? ''),
            ];
        }

        return $out;
    }
}
