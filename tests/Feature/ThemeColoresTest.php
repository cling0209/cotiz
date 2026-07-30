<?php

namespace Tests\Feature;

use App\Models\ThemeSetting;
use App\Models\User;
use App\Support\ThemePalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeColoresTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        ThemePalette::resetStored();

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }

    public function test_superadmin_puede_ver_mantenedor_colores(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.colores.index'))
            ->assertOk()
            ->assertSee('Colores del sitio')
            ->assertSee(ThemePalette::DEFAULT_PRIMARY, false);
    }

    public function test_ejecutivo_no_accede_al_mantenedor_colores(): void
    {
        $ejecutivo = User::factory()->create([
            'username' => 'ejec',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->actingAs($ejecutivo)
            ->get(route('admin.colores.index'))
            ->assertForbidden();
    }

    public function test_guardar_colores_personalizados(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.colores.update'), [
                'theme_primary' => '#6d28d9',
                'theme_primary_hover' => '#5b21b6',
                'theme_accent' => '#a78bfa',
            ])
            ->assertRedirect(route('admin.colores.index'))
            ->assertSessionHas('success');

        $this->assertSame('#6d28d9', ThemePalette::primary());
        $this->assertSame('#5b21b6', ThemePalette::primaryHover());
        $this->assertSame('#a78bfa', ThemePalette::accent());
    }

    public function test_campo_vacio_usa_default(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');

        $this->actingAs($this->admin)
            ->put(route('admin.colores.update'), [
                'theme_primary' => '',
                'theme_primary_hover' => '',
                'theme_accent' => '',
            ])
            ->assertRedirect(route('admin.colores.index'));

        $this->assertSame(ThemePalette::DEFAULT_PRIMARY, ThemePalette::primary());
    }

    public function test_restaurar_colores_predeterminados(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');

        $this->actingAs($this->admin)
            ->delete(route('admin.colores.reset'))
            ->assertRedirect(route('admin.colores.index'));

        $this->assertSame(ThemePalette::DEFAULT_PRIMARY, ThemePalette::primary());
    }

    public function test_favicon_dinamico_usa_colores_resueltos(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');
        ThemeSetting::setValue(ThemePalette::KEY_ACCENT, '#a78bfa');

        $this->get(route('theme.favicon'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->assertSee('#6d28d9', false)
            ->assertSee('#a78bfa', false)
            ->assertSee('>R</text>', false);
    }

    public function test_favicon_siempre_usa_letra_r(): void
    {
        config(['app.name' => 'Cotiz']);

        $this->get(route('theme.favicon'))
            ->assertOk()
            ->assertSee('>R</text>', false)
            ->assertDontSee('>C</text>', false);
    }

    public function test_layout_admin_favicon_incluye_version_de_cache(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');
        $version = ThemePalette::faviconVersion();

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.index'))
            ->assertOk()
            ->assertSee('theme/favicon.svg?v='.$version, false);
    }

    public function test_layout_admin_inyecta_variables_css(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.index'))
            ->assertOk()
            ->assertSee('--admin-primary: #6d28d9', false)
            ->assertSee('theme-table-headers-20260730', false);
    }
}
