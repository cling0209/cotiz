@extends('layouts.admin')

@section('title', 'Nuevo administrador')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="{{ route('admin.users.index') }}" class="text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
        <h1 class="h3 fw-bold mt-2">Nuevo administrador</h1>
        <p class="text-muted mb-0">
            Si el correo ya existe como cliente, la cuenta se promoverá a administrador.
            Al guardar, se enviará un correo de bienvenida. Al ingresar al panel se pedirá un código enviado por correo.
        </p>
    </div>

    <div class="row">
        <div class="col-lg-7">
            <div class="card admin-card">
                <div class="card-body p-4">
                    <form method="post" action="{{ route('admin.users.store') }}">
                        @csrf

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Nombre *</label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}" required autocomplete="name">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Correo *</label>
                                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                       value="{{ old('email') }}" required autocomplete="email">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="password">Contraseña *</label>
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
                            <div class="col-md-6">
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
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Crear administrador</button>
                            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/password-toggle.js') }}" defer></script>
@endpush
