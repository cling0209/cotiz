<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\User;
use App\Services\NotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaSoftlandInicioTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create([
            'username' => 'softland01',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_sin_notas_parte_en_el_inicio_configurado(): void
    {
        config(['cotiz.nota_softland_inicio' => 35001]);

        $nota = app(NotaService::class)->crear($this->usuario->username);

        $this->assertSame(35001, (int) $nota->nota_softland);
    }

    public function test_si_el_maximo_es_menor_al_inicio_salta_al_inicio(): void
    {
        config(['cotiz.nota_softland_inicio' => 10000]);
        $anterior = app(NotaService::class)->crear($this->usuario->username);
        $this->assertSame(10000, (int) $anterior->nota_softland);

        config(['cotiz.nota_softland_inicio' => 55001]);
        $nota = app(NotaService::class)->crear($this->usuario->username);
        $siguiente = app(NotaService::class)->crear($this->usuario->username);

        $this->assertSame(55001, (int) $nota->nota_softland);
        $this->assertSame(55002, (int) $siguiente->nota_softland);
    }

    public function test_si_el_maximo_supera_el_inicio_sigue_desde_el_maximo(): void
    {
        config(['cotiz.nota_softland_inicio' => 55001]);
        app(NotaService::class)->crear($this->usuario->username);
        Nota::query()->update(['nota_softland' => 60000]);

        $nota = app(NotaService::class)->crear($this->usuario->username);

        $this->assertSame(60001, (int) $nota->nota_softland);
    }
}
