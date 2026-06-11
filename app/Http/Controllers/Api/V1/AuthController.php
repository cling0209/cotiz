<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth')]
class AuthController extends Controller
{
    use ApiResponse;

    #[OA\Post(path: '/api/v1/auth/register', summary: 'Registrar usuario', tags: ['Auth'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'customer',
        ]);

        Auth::login($user);

        return $this->success($this->userPayload($user), [], 201);
    }

    #[OA\Post(path: '/api/v1/auth/login', summary: 'Iniciar sesión', tags: ['Auth'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            return $this->error('Credenciales inválidas.', 401);
        }

        $request->session()->regenerate();

        return $this->success($this->userPayload($request->user()));
    }

    #[OA\Post(path: '/api/v1/auth/logout', summary: 'Cerrar sesión', tags: ['Auth'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(['message' => 'Sesión cerrada.']);
    }

    #[OA\Get(path: '/api/v1/auth/me', summary: 'Usuario actual', tags: ['Auth'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function me(Request $request): JsonResponse
    {
        return $this->success($this->userPayload($request->user()));
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
}
