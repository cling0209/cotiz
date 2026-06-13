@extends('layouts.admin')

@section('title', 'Iniciar sesión')

@section('content')
<div class="admin-login-wrap">
    <div class="card admin-login-card shadow">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 fw-bold mb-1">{{ config('app.name', 'Cotiz') }}</h1>
            <p class="text-muted mb-4">Sistema de cotizaciones Romulo</p>

            <form method="post" action="{{ route('admin.login.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="username">Usuario</label>
                    <input type="text" name="username" id="username" value="{{ old('username') }}"
                           class="form-control @error('username') is-invalid @enderror" required autofocus maxlength="20">
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Contrase&ntilde;a</label>
                    <input type="password" name="password" id="password"
                           class="form-control @error('password') is-invalid @enderror" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                    <label class="form-check-label" for="remember">Recordarme</label>
                </div>
                <button type="submit" class="btn btn-primary w-100">Entrar</button>
            </form>
        </div>
    </div>
</div>
@endsection
