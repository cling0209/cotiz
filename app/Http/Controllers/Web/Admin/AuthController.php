<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NotaMpResultadosService;
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
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.cotizaciones.index');
        }

        return view('admin.auth.login');
    }

    public function login(
        Request $request,
        NotaMpResultadosService $resultadosMp,
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

        $this->dispararCatchUpMpSiCorresponde($resultadosMp);
        $this->dispararCatchUpOportunidades($oportunidades);

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
     * Si el horario programado de consulta MP ya pasó sin corrida, encola catch-up al login.
     */
    private function dispararCatchUpMpSiCorresponde(NotaMpResultadosService $resultadosMp): void
    {
        try {
            $resultado = $resultadosMp->asegurarCorridaProgramadaSiCorresponde(
                'sistema',
                NotaMpResultadosService::CATCHUP_ORIGEN_LOGIN,
            );
            if (($resultado['accion'] ?? '') === 'encolada') {
                Log::info('Catch-up MP encolado al login admin', $resultado);
            }
        } catch (Throwable $e) {
            Log::warning('Catch-up MP al login falló', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function dispararCatchUpOportunidades(OportunidadBusquedaService $oportunidades): void
    {
        try {
            $resultado = $oportunidades->catchUp('sistema', true);
            if (in_array($resultado['accion'] ?? '', ['encolada', 'reanudada'], true)) {
                Log::info('Catch-up de oportunidades encolado al login admin', $resultado);
            }
        } catch (Throwable $e) {
            Log::warning('Catch-up de oportunidades al login falló', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
