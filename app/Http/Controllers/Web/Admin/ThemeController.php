<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ThemeSetting;
use App\Support\ThemePalette;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ThemeController extends Controller
{
    public function edit(): View
    {
        $stored = ThemePalette::storedValues();
        $resolved = ThemePalette::resolved();

        return view('admin.colores.index', [
            'stored' => $stored,
            'resolved' => $resolved,
            'defaults' => ThemePalette::DEFAULTS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme_primary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme_primary_hover' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme_accent' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'theme_primary.regex' => 'El color primario debe ser hexadecimal (#RRGGBB).',
            'theme_primary_hover.regex' => 'El color hover debe ser hexadecimal (#RRGGBB).',
            'theme_accent.regex' => 'El color acento debe ser hexadecimal (#RRGGBB).',
        ]);

        ThemeSetting::setValue(
            ThemePalette::KEY_PRIMARY,
            ThemePalette::normalizeHexInput($data['theme_primary'] ?? null),
        );
        ThemeSetting::setValue(
            ThemePalette::KEY_PRIMARY_HOVER,
            ThemePalette::normalizeHexInput($data['theme_primary_hover'] ?? null),
        );
        ThemeSetting::setValue(
            ThemePalette::KEY_ACCENT,
            ThemePalette::normalizeHexInput($data['theme_accent'] ?? null),
        );

        return redirect()
            ->route('admin.colores.index')
            ->with('success', 'Colores del sitio actualizados.');
    }

    public function reset(): RedirectResponse
    {
        ThemePalette::resetStored();

        return redirect()
            ->route('admin.colores.index')
            ->with('success', 'Colores restaurados al valor predeterminado.');
    }
}
