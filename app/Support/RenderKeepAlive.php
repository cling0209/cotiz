<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Evita que Render free haga spin-down mientras hay jobs de cola en curso.
 * Solo cuenta tráfico HTTP entrante; el worker llama a APP_URL/up con throttle.
 */
final class RenderKeepAlive
{
    public static function pingIfDue(): void
    {
        if (! config('cotiz.render_keepalive.enabled', false)) {
            return;
        }

        $url = self::healthUrl();
        if ($url === '') {
            return;
        }

        $minutes = max(5, min(14, (int) config('cotiz.render_keepalive.minutes', 10)));
        $cacheKey = 'render_keepalive_ping_'.$minutes;

        Cache::remember($cacheKey, now()->addMinutes($minutes), function () use ($url) {
            try {
                Http::timeout(8)
                    ->connectTimeout(5)
                    ->withHeaders(['User-Agent' => 'Cotiz-RenderKeepAlive/1.0'])
                    ->get($url);
            } catch (\Throwable $e) {
                Log::warning('RenderKeepAlive: ping falló', [
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]);
            }

            return true;
        });
    }

    public static function healthUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base !== '' ? $base.'/up' : '';
    }
}
