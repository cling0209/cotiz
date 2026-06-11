@extends('layouts.admin')

@section('title', 'Recuperar contraseña')

@section('content')
<div class="admin-login-wrap">
    <div class="card admin-login-card shadow">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 fw-bold mb-1">Recuperar contraseña</h1>
            <p class="text-muted mb-4">
                Ingresa el correo de tu cuenta de administrador.
                @if ($otpEnabled)
                    Te enviaremos un código de verificación.
                @else
                    Te enviaremos un enlace para restablecer la contraseña.
                @endif
            </p>

            <form method="post" action="{{ route('admin.password.email') }}">
                @csrf
                <div class="mb-4">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input type="email" name="email" id="email" value="{{ old('email') }}"
                           class="form-control @error('email') is-invalid @enderror" required autofocus>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-3">
                    @if ($otpEnabled)
                        Enviar código
                    @else
                        Enviar enlace
                    @endif
                </button>
                <a href="{{ route('admin.login') }}" class="btn btn-link w-100">Volver al inicio de sesión</a>
            </form>
        </div>
    </div>
</div>
@endsection
