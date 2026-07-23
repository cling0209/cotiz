<?php

namespace Tests\Unit;

use App\Support\RenderKeepAlive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RenderKeepAliveTest extends TestCase
{
    public function test_no_pingea_si_esta_deshabilitado(): void
    {
        config([
            'cotiz.render_keepalive.enabled' => false,
            'app.url' => 'https://cotiza.example.test',
        ]);

        Http::fake();

        RenderKeepAlive::pingIfDue();
        RenderKeepAlive::pingIfDue();

        Http::assertNothingSent();
    }

    public function test_pingea_up_con_throttle_de_cache(): void
    {
        config([
            'cotiz.render_keepalive.enabled' => true,
            'cotiz.render_keepalive.minutes' => 10,
            'app.url' => 'https://cotiza.example.test',
            'cache.default' => 'array',
        ]);

        Cache::flush();
        Http::fake([
            'https://cotiza.example.test/up' => Http::response('ok', 200),
        ]);

        RenderKeepAlive::pingIfDue();
        RenderKeepAlive::pingIfDue();
        RenderKeepAlive::pingIfDue();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://cotiza.example.test/up');
    }

    public function test_health_url_usa_app_url(): void
    {
        config(['app.url' => 'https://cotiza.romulo.cl/']);

        $this->assertSame('https://cotiza.romulo.cl/up', RenderKeepAlive::healthUrl());
    }
}
