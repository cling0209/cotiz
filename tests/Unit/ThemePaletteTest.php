<?php

namespace Tests\Unit;

use App\Models\ThemeSetting;
use App\Support\ThemePalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePaletteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ThemePalette::resetStored();
    }

    public function test_favicon_letter_is_always_r(): void
    {
        config(['app.name' => 'Cotiz']);

        $this->assertSame('R', ThemePalette::faviconLetter());
    }

    public function test_uses_defaults_when_no_settings(): void
    {
        $this->assertSame(ThemePalette::DEFAULT_PRIMARY, ThemePalette::primary());
        $this->assertSame(ThemePalette::DEFAULT_PRIMARY_HOVER, ThemePalette::primaryHover());
        $this->assertSame(ThemePalette::DEFAULT_ACCENT, ThemePalette::accent());
    }

    public function test_uses_stored_hex_when_present(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');
        ThemeSetting::setValue(ThemePalette::KEY_ACCENT, '#a78bfa');

        $this->assertSame('#6d28d9', ThemePalette::primary());
        $this->assertSame('#a78bfa', ThemePalette::accent());
    }

    public function test_empty_value_falls_back_to_default(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, null);

        $this->assertSame(ThemePalette::DEFAULT_PRIMARY, ThemePalette::primary());
    }

    public function test_reset_clears_custom_colors(): void
    {
        ThemeSetting::setValue(ThemePalette::KEY_PRIMARY, '#6d28d9');
        ThemePalette::resetStored();

        $this->assertSame(ThemePalette::DEFAULT_PRIMARY, ThemePalette::primary());
        $this->assertDatabaseMissing('theme_settings', ['key' => ThemePalette::KEY_PRIMARY]);
    }
}
