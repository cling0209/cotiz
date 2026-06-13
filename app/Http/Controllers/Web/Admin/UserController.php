<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public const PASSWORD_MAX_LENGTH = 20;

    public function index(Request $request): View
    {
        $usuarios = User::query()
            ->whereIn('perfil', [User::PERFIL_SUPERADMIN, User::PERFIL_EJECUTIVO])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';

                return $query->where(function ($q) use ($term) {
                    $q->where('username', 'ilike', $term)
                        ->orWhere('nombre', 'ilike', $term)
                        ->orWhere('apellidop', 'ilike', $term)
                        ->orWhere('correo', 'ilike', $term);
                });
            })
            ->orderBy('username')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', compact('usuarios'));
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'usuario' => null,
            'passwordMaxLength' => self::PASSWORD_MAX_LENGTH,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate($this->reglasUsuario(true));

        User::query()->create([
            'username' => $datos['username'],
            'nombre' => $datos['nombre'],
            'apellidop' => $datos['apellidop'] ?? null,
            'apellidom' => $datos['apellidom'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'perfil' => (int) $datos['perfil'],
            'password' => $datos['password'],
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Usuario creado.');
    }

    public function edit(User $usuario): View
    {
        $this->asegurarUsuarioPanel($usuario);

        return view('admin.users.form', [
            'usuario' => $usuario,
            'passwordMaxLength' => self::PASSWORD_MAX_LENGTH,
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $this->asegurarUsuarioPanel($usuario);

        $datos = $request->validate($this->reglasUsuario(false, $usuario));

        $updates = [
            'nombre' => $datos['nombre'],
            'apellidop' => $datos['apellidop'] ?? null,
            'apellidom' => $datos['apellidom'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'perfil' => (int) $datos['perfil'],
        ];

        if (! empty($datos['password'])) {
            $updates['password'] = $datos['password'];
        }

        if ($usuario->id === $request->user()->id && (int) $datos['perfil'] !== User::PERFIL_SUPERADMIN) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('error', 'No puedes quitarte el perfil de superadministrador.');
        }

        if ($usuario->isSuperAdmin()
            && (int) $datos['perfil'] !== User::PERFIL_SUPERADMIN
            && $this->cantidadSuperadmins() <= 1) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('error', 'Debe quedar al menos un superadministrador.');
        }

        $usuario->update($updates);

        return redirect()
            ->route('admin.users.edit', $usuario)
            ->with('success', 'Usuario actualizado.');
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        $this->asegurarUsuarioPanel($usuario);

        if ($usuario->id === $request->user()->id) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        if ($usuario->isSuperAdmin() && $this->cantidadSuperadmins() <= 1) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'Debe quedar al menos un superadministrador.');
        }

        $usuario->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Usuario eliminado.');
    }

    private function asegurarUsuarioPanel(User $usuario): void
    {
        if (! in_array($usuario->perfil, [User::PERFIL_SUPERADMIN, User::PERFIL_EJECUTIVO], true)) {
            abort(404);
        }
    }

    private function cantidadSuperadmins(): int
    {
        return User::query()->where('perfil', User::PERFIL_SUPERADMIN)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasUsuario(bool $esNuevo, ?User $usuario = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:20'],
            'apellidop' => ['nullable', 'string', 'max:30'],
            'apellidom' => ['nullable', 'string', 'max:20'],
            'correo' => ['nullable', 'email', 'max:60'],
            'perfil' => ['required', 'integer', Rule::in([User::PERFIL_SUPERADMIN, User::PERFIL_EJECUTIVO])],
        ];

        if ($esNuevo) {
            $reglas['username'] = ['required', 'string', 'max:20', 'alpha_dash', 'unique:users,username'];
            $reglas['password'] = $this->passwordRules(required: true);
        } else {
            $reglas['password'] = $this->passwordRules(required: false);
        }

        return $reglas;
    }

    /**
     * @return array<int, mixed>
     */
    private function passwordRules(bool $required): array
    {
        $rules = [
            $required ? 'required' : 'nullable',
            'confirmed',
            'max:'.self::PASSWORD_MAX_LENGTH,
            Password::min(8)->letters()->numbers(),
        ];

        return $rules;
    }
}
