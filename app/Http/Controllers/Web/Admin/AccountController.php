<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public const PASSWORD_MAX_LENGTH = 20;

    public function editPassword(): View
    {
        return view('admin.account.password', [
            'passwordMaxLength' => self::PASSWORD_MAX_LENGTH,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                'confirmed',
                'max:'.self::PASSWORD_MAX_LENGTH,
                Password::min(6)->letters()->numbers(),
            ],
        ], [
            'current_password.required' => 'Ingresa tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'Ingresa la nueva contraseña.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.max' => 'La contraseña no puede superar '.self::PASSWORD_MAX_LENGTH.' caracteres.',
        ]);

        $request->user()->update([
            'password' => $validated['password'],
        ]);

        return redirect()
            ->route('admin.account.password')
            ->with('success', 'Contraseña actualizada correctamente.');
    }
}
