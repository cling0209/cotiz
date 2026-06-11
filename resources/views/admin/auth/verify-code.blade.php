@extends('layouts.admin')

@section('title', 'Verificar acceso')

@section('content')
<div class="admin-login-wrap">
    <div class="card admin-login-card shadow">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 fw-bold mb-1">Verifica tu acceso</h1>
            <p class="text-muted mb-4">
                Ingresa el código de 6 dígitos enviado a
                <strong>{{ $email }}</strong>.
            </p>

            <form method="post" action="{{ route('admin.login.verify.store') }}">
                @csrf
                <div class="mb-4">
                    <label class="form-label" for="code">Código de verificación</label>
                    <input type="text" name="code" id="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                           class="form-control form-control-lg text-center letter-spacing-2 @error('code') is-invalid @enderror"
                           required autofocus autocomplete="one-time-code" placeholder="000000">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-3">Confirmar e ingresar</button>
                <a href="{{ route('admin.login') }}" class="btn btn-link w-100">Volver al inicio de sesión</a>
            </form>
        </div>
    </div>
</div>
@endsection
