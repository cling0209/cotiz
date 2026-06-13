@extends('layouts.admin')

@section('title', 'Iniciar sesión')

@section('content')
<div class="admin-login-wrap">
    <div class="card admin-login-card shadow">
        <div class="card-body p-4 p-md-5">
            <div class="admin-login-brand">
                <span class="admin-login-icon" aria-hidden="true"><i class="bi bi-calculator"></i></span>
                <div>
                    <h1 class="h4 fw-bold mb-0">{{ config('app.name', 'Cotiz') }}</h1>
                    <p class="admin-login-subtitle mb-0 small">Sistema de cotizaciones Romulo</p>
                </div>
            </div>

            <form method="post" action="{{ route('admin.login.store') }}" class="mt-4">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="username">Usuario</label>
                    <input type="text" name="username" id="username" value="{{ old('username') }}"
                           class="form-control @error('username') is-invalid @enderror" required autofocus maxlength="20">
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Contrase&ntilde;a</label>
                    <x-password-input
                        name="password"
                        id="password"
                        required
                        autocomplete="current-password"
                        class="@error('password') is-invalid @enderror"
                    />
                    @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                    <label class="form-check-label" for="remember">Recordarme</label>
                </div>
                <div class="text-end mb-3">
                    <a href="{{ route('admin.password.request') }}" class="small">¿Olvidaste tu contraseña?</a>
                </div>
                <button type="submit" class="btn btn-primary w-100">Entrar</button>
            </form>
        </div>
    </div>
</div>
@endsection
