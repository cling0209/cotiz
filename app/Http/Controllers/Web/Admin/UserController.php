<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminWelcomeNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public const PASSWORD_MAX_LENGTH = 20;

    public function index(Request $request): View
    {
        $admins = User::query()
            ->where('role', 'admin')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';

                return $query->where(function ($q) use ($term) {
                    $q->where('name', 'ilike', $term)
                        ->orWhere('email', 'ilike', $term);
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', compact('admins'));
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'passwordMaxLength' => self::PASSWORD_MAX_LENGTH,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => $this->passwordRules(),
        ], $this->passwordMessages());

        $existing = User::query()->where('email', $data['email'])->first();

        if ($existing?->isAdmin()) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('error', 'Ya existe un administrador con ese correo.');
        }

        if ($existing) {
            $existing->update([
                'name' => $data['name'],
                'password' => $data['password'],
                'role' => 'admin',
            ]);

            return $this->redirectAfterAdminSaved(
                $existing,
                'La cuenta existente fue promovida a administrador.'
            );
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'admin',
        ]);

        return $this->redirectAfterAdminSaved($user, 'Administrador creado correctamente.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if (! $user->isAdmin()) {
            abort(404);
        }

        if ($request->user()->id === $user->id) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'No puedes eliminar tu propia cuenta de administrador.');
        }

        if (User::query()->where('role', 'admin')->count() <= 1) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'Debe quedar al menos un administrador en el sistema.');
        }

        $user->update(['role' => 'customer']);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'El usuario ya no tiene permisos de administrador.');
    }

    protected function redirectAfterAdminSaved(User $user, string $message): RedirectResponse
    {
        try {
            $user->notify(new AdminWelcomeNotification());
            $message .= ' Se envió un correo de bienvenida al administrador.';
        } catch (\Throwable $e) {
            report($e);
            $message .= ' No se pudo enviar el correo de bienvenida; revisa la configuración SMTP.';
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', $message);
    }

    /**
     * @return array<int, mixed>
     */
    protected function passwordRules(): array
    {
        return [
            'required',
            'confirmed',
            'max:'.self::PASSWORD_MAX_LENGTH,
            Password::min(8)->letters()->numbers(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function passwordMessages(): array
    {
        return [
            'password.required' => 'Ingresa la contraseña.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.max' => 'La contraseña no puede superar '.self::PASSWORD_MAX_LENGTH.' caracteres.',
        ];
    }
}
