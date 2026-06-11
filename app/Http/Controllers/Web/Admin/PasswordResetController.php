<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminOtpService;
use App\Support\MailDevelopmentLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public const PASSWORD_MAX_LENGTH = 20;

    public function __construct(protected AdminOtpService $adminOtp) {}

    public function create(): View
    {
        return view('admin.auth.forgot-password', [
            'otpEnabled' => config('admin.otp_enabled'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        MailDevelopmentLogger::info('Recuperación admin solicitada', [
            'email' => $data['email'],
            'usuario_encontrado' => $user !== null,
            'es_admin' => $user?->isAdmin() ?? false,
            'otp_habilitado' => config('admin.otp_enabled'),
        ]);

        if ($user?->isAdmin()) {
            if (config('admin.otp_enabled')) {
                return $this->storeWithOtp($request, $user);
            }

            try {
                MailDevelopmentLogger::info('Enviando enlace de recuperación admin', [
                    'email' => $data['email'],
                ]);

                Password::sendResetLink(['email' => $data['email']]);

                MailDevelopmentLogger::info('Enlace de recuperación admin procesado', [
                    'email' => $data['email'],
                ]);
            } catch (\Throwable $e) {
                report($e);
                MailDevelopmentLogger::error('Fallo al enviar enlace de recuperación admin', [
                    'email' => $data['email'],
                    'error' => $e->getMessage(),
                ]);

                return back()->with('error', 'No se pudo enviar el enlace. Revisa la configuración de correo.');
            }

            return back()->with(
                'success',
                'Te enviamos un enlace para restablecer la contraseña. Si no lo ves, revisa también la carpeta de spam o correo no deseado.'
            );
        }

        MailDevelopmentLogger::info('Recuperación admin sin envío (correo no es admin)', [
            'email' => $data['email'],
        ]);

        return back()->with(
            'success',
            $this->genericSuccessMessage()
        );
    }

    public function edit(Request $request, ?string $token = null): View|RedirectResponse
    {
        if (config('admin.otp_enabled')) {
            return $this->editWithOtp($request);
        }

        return $this->editWithLink($request, $token);
    }

    public function update(Request $request): RedirectResponse
    {
        if (config('admin.otp_enabled')) {
            return $this->updateWithOtp($request);
        }

        return $this->updateWithLink($request);
    }

    protected function storeWithOtp(Request $request, User $user): RedirectResponse
    {
        try {
            $this->adminOtp->send(
                $user,
                AdminOtpService::PURPOSE_PASSWORD_RESET,
                'Recuperar contraseña del panel — '.config('app.name', 'Tienda Rómulo'),
                'Para restablecer la contraseña de tu cuenta de administrador, usa este código en el formulario.',
            );

            $request->session()->put('admin_reset_user_id', $user->id);

            return redirect()
                ->route('admin.password.reset')
                ->with('success', 'Te enviamos un código de verificación a tu correo. Si no lo ves, revisa también la carpeta de spam o correo no deseado.');
        } catch (\Throwable $e) {
            report($e);
            MailDevelopmentLogger::error('Fallo al enviar código OTP de recuperación admin', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'No se pudo enviar el código. Revisa la configuración de correo.');
        }
    }

    protected function editWithOtp(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('admin_reset_user_id')) {
            return redirect()
                ->route('admin.password.request')
                ->with('error', 'Primero solicita un código con tu correo de administrador.');
        }

        $user = User::query()->find($request->session()->get('admin_reset_user_id'));

        if (! $user?->isAdmin()) {
            $request->session()->forget('admin_reset_user_id');

            return redirect()->route('admin.password.request');
        }

        return view('admin.auth.reset-password', [
            'email' => $user->email,
            'passwordMaxLength' => self::PASSWORD_MAX_LENGTH,
            'otpEnabled' => true,
            'token' => null,
        ]);
    }

    protected function editWithLink(Request $request, ?string $token): View|RedirectResponse
    {
        $email = $request->query('email', '');
        $user = User::query()->where('email', $email)->first();

        if (! $token || ! $user?->isAdmin()) {
            return redirect()
                ->route('admin.password.request')
                ->with('error', 'El enlace de recuperación no es válido para esta cuenta.');
        }

        return view('admin.auth.reset-password', [
            'email' => $user->email,
            'passwordMaxLength' => self::PASSWORD_MAX_LENGTH,
            'otpEnabled' => false,
            'token' => $token,
        ]);
    }

    protected function updateWithOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'password' => [
                'required',
                'confirmed',
                'max:'.self::PASSWORD_MAX_LENGTH,
                PasswordRule::min(8)->letters()->numbers(),
            ],
        ]);

        $userId = $request->session()->get('admin_reset_user_id');

        if (! $userId) {
            return redirect()->route('admin.password.request')->with('error', 'La sesión de recuperación expiró.');
        }

        $user = User::query()->find($userId);

        if (! $user?->isAdmin()) {
            $request->session()->forget('admin_reset_user_id');

            return redirect()->route('admin.password.request')->with('error', 'No se pudo restablecer la contraseña.');
        }

        if (! $this->adminOtp->verify($user, AdminOtpService::PURPOSE_PASSWORD_RESET, $data['code'])) {
            return back()->with('error', 'El código no es válido o ya expiró.');
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $request->session()->forget('admin_reset_user_id');

        return redirect()
            ->route('admin.login')
            ->with('success', 'Contraseña actualizada. Inicia sesión con tu nueva clave; te enviaremos un código de verificación.');
    }

    protected function updateWithLink(Request $request): RedirectResponse
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

        if (! $user?->isAdmin()) {
            return back()->withErrors([
                'email' => 'No se pudo restablecer la contraseña para esta cuenta.',
            ]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $resetUser, string $password) {
                if (! $resetUser->isAdmin()) {
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
            ->route('admin.login')
            ->with('success', 'Contraseña actualizada. Ya puedes iniciar sesión.');
    }

    protected function genericSuccessMessage(): string
    {
        if (config('admin.otp_enabled')) {
            return 'Si el correo corresponde a un administrador, recibirás un código para restablecer la contraseña. Si no lo ves, revisa también la carpeta de spam o correo no deseado.';
        }

        return 'Si el correo corresponde a un administrador, recibirás un enlace para restablecer la contraseña. Si no lo ves, revisa también la carpeta de spam o correo no deseado.';
    }
}
