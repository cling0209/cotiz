<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OportunidadBusquedaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.cotizaciones.index');
        }

        $this->recordarRetorno($request);

        return view('admin.auth.login');
    }

    public function login(
        Request $request,
        OportunidadBusquedaService $oportunidades,
    ): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:20'],
            'password' => ['required'],
        ]);

        $user = User::query()->where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()
                ->withInput($request->only('username'))
                ->with('error', 'Usuario o contraseña inválidos.');
        }

        if (! $user->canAccessPanel()) {
            return back()
                ->withInput($request->only('username'))
                ->with('error', 'Este usuario no tiene acceso al panel de cotizaciones.');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $this->dispararCatchUpMpSiCorresponde($oportunidades);

        return redirect()->intended(route('admin.cotizaciones.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Sesión cerrada.');
    }

    /**
     * Guarda como destino post-login la pantalla donde venció la sesión (?volver=).
     * Solo rutas internas del panel, para no habilitar redirecciones abiertas.
     */
    private function recordarRetorno(Request $request): void
    {
        $volver = trim((string) $request->query('volver', ''));

        if ($volver === '' || ! str_starts_with($volver, '/admin/')) {
            return;
        }

        if (str_contains($volver, '\\') || str_starts_with($volver, '/admin//')) {
            return;
        }

        if (str_starts_with($volver, '/admin/login') || str_starts_with($volver, '/admin/logout')) {
            return;
        }

        $request->session()->put('url.intended', url($volver));
    }

    /**
     * Catch-up al login (Render): arranca desde búsqueda de cotizaciones (paso 1).
     * Si hay cambios de estado en curso, la búsqueda los cancela.
     */
    private function dispararCatchUpMpSiCorresponde(
        OportunidadBusquedaService $oportunidades,
    ): void {
        if (! config('cotiz.mercadopublico.resultados_catchup_login', false)) {
            return;
        }

        try {
            $busqueda = $oportunidades->catchUp('sistema', true);
            if (in_array($busqueda['accion'] ?? '', ['encolada', 'reanudada', 'en_curso'], true)) {
                Log::info('Catch-up de oportunidades encolado al login admin', $busqueda);
            }
        } catch (Throwable $e) {
            Log::warning('Catch-up de oportunidades al login falló', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
