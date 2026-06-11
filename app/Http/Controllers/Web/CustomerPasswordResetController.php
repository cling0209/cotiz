<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\MailDevelopmentLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class CustomerPasswordResetController extends Controller
{
    public const PASSWORD_MAX_LENGTH = 20;

    public function create(): View
    {
        return view('shop.auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        MailDevelopmentLogger::info('Recuperación cliente solicitada', [
            'email' => $data['email'],
            'usuario_encontrado' => $user !== null,
            'es_cliente' => $user !== null && ! $user->isAdmin(),
        ]);

        if ($user && ! $user->isAdmin()) {
            try {
                Password::sendResetLink(['email' => $data['email']]);
                MailDevelopmentLogger::info('Enlace de recuperación cliente procesado', [
                    'email' => $data['email'],
                ]);
            } catch (\Throwable $e) {
                report($e);
                MailDevelopmentLogger::error('Fallo al enviar enlace de recuperación cliente', [
                    'email' => $data['email'],
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($user?->isAdmin()) {
            MailDevelopmentLogger::info('Recuperación cliente sin envío (correo es admin)', [
                'email' => $data['email'],
            ]);
        } else {
            MailDevelopmentLogger::info('Recuperación cliente sin envío (correo no registrado)', [
                'email' => $data['email'],
            ]);
        }

        return back()->with(
            'success',
            'Si el correo corresponde a una cuenta de cliente, recibirás un enlace para restablecer la contraseña. Si no lo ves, revisa también la carpeta de spam o correo no deseado.'
        );
    }

    public function edit(Request $request, string $token): View|RedirectResponse
    {
        $email = $request->query('email', '');
        $user = User::query()->where('email', $email)->first();

        if (! $user || $user->isAdmin()) {
            return redirect()
                ->route('account.password.request')
                ->with('error', 'El enlace de recuperación no es válido para esta cuenta.');
        }

        return view('shop.auth.reset-password', [
            'token' => $token,
            'email' => $email,
            'passwordMaxLength' => self::PASSWORD_MAX_LENGTH,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'confirmed',
                'max:'.self::PASSWORD_MAX_LENGTH,
                PasswordRule::min(8)->letters()->numbers(),
            ],
        ]);

        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user || $user->isAdmin()) {
            return back()->withErrors([
                'email' => 'No se pudo restablecer la contraseña para esta cuenta.',
            ]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $resetUser, string $password) {
                if ($resetUser->isAdmin()) {
                    return;
                }

                $resetUser->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($resetUser));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()
            ->route('account.login')
            ->with('success', 'Contraseña actualizada. Ya puedes iniciar sesión.');
    }
}
