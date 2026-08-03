<?php

namespace Tests\Feature;

use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    public function test_app_normal_sin_flag(): void
    {
        config(['app.maintenance_mode' => false]);

        $this->get('/')->assertRedirect('/admin/login');
    }

    public function test_muestra_pagina_mantencion_cuando_flag_true(): void
    {
        config(['app.maintenance_mode' => true]);

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('Aplicación en mantención', false)
            ->assertSee('Estará disponible en unos minutos', false);
    }

    public function test_api_responde_503_json_en_mantencion(): void
    {
        config(['app.maintenance_mode' => true]);

        $this->getJson('/api/v1/nota')
            ->assertStatus(503)
            ->assertJson([
                'message' => 'La aplicación está en mantención. Estará disponible en unos minutos.',
            ]);
    }

    public function test_health_up_sigue_disponible_en_mantencion(): void
    {
        config(['app.maintenance_mode' => true]);

        $this->get('/up')->assertOk();
    }
}
