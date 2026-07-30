<?php

namespace Tests\Unit;

use App\Support\ListadoPorPagina;
use Illuminate\Http\Request;
use Tests\TestCase;

class ListadoPorPaginaTest extends TestCase
{
    public function test_normalizar_solo_acepta_20_40_60(): void
    {
        $this->assertSame(20, ListadoPorPagina::normalizar(20));
        $this->assertSame(40, ListadoPorPagina::normalizar(40));
        $this->assertSame(60, ListadoPorPagina::normalizar(60));
        $this->assertSame(20, ListadoPorPagina::normalizar(10));
        $this->assertSame(20, ListadoPorPagina::normalizar(100));
    }

    public function test_resolver_usa_query_y_persiste_en_sesion(): void
    {
        $request = Request::create('/admin/cotizaciones', 'GET', ['por_pagina' => '40']);
        $request->setLaravelSession($this->app['session.store']);

        $this->assertSame(40, ListadoPorPagina::resolver($request, 'cotizaciones.test'));

        $requestSinQuery = Request::create('/admin/cotizaciones', 'GET');
        $requestSinQuery->setLaravelSession($this->app['session.store']);

        $this->assertSame(40, ListadoPorPagina::resolver($requestSinQuery, 'cotizaciones.test'));
    }

    public function test_resolver_default_es_20(): void
    {
        $request = Request::create('/admin/cotizaciones', 'GET');
        $request->setLaravelSession($this->app['session.store']);

        $this->assertSame(20, ListadoPorPagina::resolver($request, 'cotizaciones.vacio'));
    }
}
