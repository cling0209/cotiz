<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureMpResultadosScheduleCatchUp;
use App\Services\NotaMpResultadosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EnsureMpResultadosScheduleCatchUpTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Cache::forget('mp_resultados_schedule_catchup_check');
        parent::tearDown();
    }

    public function test_request_web_dispara_catch_up_si_slot_perdido(): void
    {
        config(['cotiz.mercadopublico.resultados_schedule_habilitado' => true]);
        Cache::forget('mp_resultados_schedule_catchup_check');

        $service = Mockery::mock(NotaMpResultadosService::class);
        $service->shouldReceive('asegurarCorridaProgramadaSiCorresponde')
            ->once()
            ->with('sistema')
            ->andReturn([
                'accion' => 'encolada',
                'corrida_id' => 99,
                'mensaje' => 'Catch-up test',
            ]);
        $this->app->instance(NotaMpResultadosService::class, $service);

        $middleware = new EnsureMpResultadosScheduleCatchUp;
        $request = Request::create('/admin/login', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_segundo_request_en_el_mismo_minuto_no_repite_catch_up(): void
    {
        config(['cotiz.mercadopublico.resultados_schedule_habilitado' => true]);
        Cache::forget('mp_resultados_schedule_catchup_check');

        $service = Mockery::mock(NotaMpResultadosService::class);
        $service->shouldReceive('asegurarCorridaProgramadaSiCorresponde')
            ->once()
            ->with('sistema')
            ->andReturn(['accion' => 'omitido', 'mensaje' => 'ok']);
        $this->app->instance(NotaMpResultadosService::class, $service);

        $middleware = new EnsureMpResultadosScheduleCatchUp;
        $request = Request::create('/admin', 'GET');

        $middleware->handle($request, fn () => new Response('ok'));
        $middleware->handle($request, fn () => new Response('ok'));
    }

    public function test_schedule_deshabilitado_no_llama_servicio(): void
    {
        config(['cotiz.mercadopublico.resultados_schedule_habilitado' => false]);
        Cache::forget('mp_resultados_schedule_catchup_check');

        $service = Mockery::mock(NotaMpResultadosService::class);
        $service->shouldNotReceive('asegurarCorridaProgramadaSiCorresponde');
        $this->app->instance(NotaMpResultadosService::class, $service);

        $middleware = new EnsureMpResultadosScheduleCatchUp;
        $request = Request::create('/admin', 'GET');

        $middleware->handle($request, fn () => new Response('ok'));
    }
}
