<?php

namespace Tests\Unit;

use App\Http\Middleware\ForceRequestRootUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ForceRequestRootUrlTest extends TestCase
{
    public function test_production_usa_host_de_la_peticion(): void
    {
        $this->app['env'] = 'production';

        $request = Request::create(
            'https://cotiza.romulo.cl/admin/cotizaciones/10',
            'GET',
            server: ['HTTP_HOST' => 'cotiza.romulo.cl', 'HTTPS' => 'on'],
        );

        $middleware = new ForceRequestRootUrl;
        $middleware->handle($request, fn ($req) => response('ok'));

        $this->assertSame(
            'https://cotiza.romulo.cl/admin/cotizaciones/10',
            route('admin.cotizaciones.edit', 10),
        );
    }

    public function test_local_no_sobrescribe_app_url(): void
    {
        $this->app['env'] = 'local';
        config(['app.url' => 'http://localhost:8082']);

        $request = Request::create(
            'http://127.0.0.1:8082/admin/cotizaciones/10',
            'GET',
            server: ['HTTP_HOST' => '127.0.0.1:8082'],
        );

        $middleware = new ForceRequestRootUrl;
        $middleware->handle($request, fn ($req) => response('ok'));

        $this->assertSame(
            'http://localhost:8082/admin/cotizaciones/10',
            route('admin.cotizaciones.edit', 10),
        );
    }
}
