<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data = null, array $meta = [], int $code = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
            'errors' => null,
        ], $code);
    }

    protected function error(string $message, int $code = 400, ?array $details = null): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => (object) [],
            'errors' => array_filter([
                'message' => $message,
                'details' => $details,
            ]),
        ], $code);
    }
}
