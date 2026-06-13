@extends('layouts.admin')

@section('title', 'Nueva contraseña')

@section('content')
<div class="admin-login-wrap">
    <div class="card admin-login-card shadow">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 fw-bold mb-1">Nueva contraseña</h1>
            <p class="text-muted mb-4">
                @if ($otpEnabled)
                    Ingresa el código enviado a <strong>{{ $email }}</strong> y define tu nueva contraseña.
                @else
                    Define una nueva contraseña para <strong>{{ $email }}</strong>.
                @endif
            </p>

            <form method="post" action="{{ route('admin.password.update') }}">
                @csrf
                @if ($otpEnabled)
                    <div class="mb-3">
                        <label class="form-label" for="code">Código de verificación *</label>
                        <input type="text" name="code" id="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               class="form-control text-center @error('code') is-invalid @enderror"
                               required autofocus autocomplete="one-time-code" placeholder="000000">
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @else
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="email" value="{{ $email }}">
                @endif
                <div class="mb-3">
                    <label class="form-label" for="password">Nueva contraseña *</label>
                    <div class="input-group">
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               required autocomplete="new-password"
                               maxlength="{{ $passwordMaxLength }}">
                        <button type="button" class="btn btn-outline-secondary js-password-toggle"
                                data-target="password" aria-label="Mostrar contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <div class="form-text">Mínimo 8 caracteres, con letras y números.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password_confirmation">Confirmar contraseña *</label>
                    <div class="input-group">
                        <input type="password" name="password_confirmation" id="password_confirmation"
                               class="form-control" required autocomplete="new-password"
                               maxlength="{{ $passwordMaxLength }}">
                        <button type="button" class="btn btn-outline-secondary js-password-toggle"
                                data-target="password_confirmation" aria-label="Mostrar contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Guardar contraseña</button>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@endpush
