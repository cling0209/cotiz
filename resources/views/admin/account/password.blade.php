@extends('layouts.admin')

@section('title', 'Cambiar contraseña')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card admin-card">
                <div class="card-header bg-white fw-semibold">Cambiar contraseña</div>
                <div class="card-body">
                    <p class="text-muted small mb-4">
                        Mínimo 6 caracteres, con letras y números. Máximo {{ $passwordMaxLength }} caracteres.
                    </p>

                    <form method="post" action="{{ route('admin.account.password.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label" for="current_password">Contraseña actual *</label>
                            <x-password-input
                                name="current_password"
                                id="current_password"
                                required
                                autocomplete="current-password"
                                :maxlength="$passwordMaxLength"
                                class="@error('current_password') is-invalid @enderror"
                            />
                            @error('current_password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="password">Nueva contraseña *</label>
                            <x-password-input
                                name="password"
                                id="password"
                                required
                                autocomplete="new-password"
                                minlength="6"
                                :maxlength="$passwordMaxLength"
                                class="@error('password') is-invalid @enderror"
                            />
                            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="password_confirmation">Confirmar nueva contraseña *</label>
                            <x-password-input
                                name="password_confirmation"
                                id="password_confirmation"
                                required
                                autocomplete="new-password"
                                minlength="6"
                                :maxlength="$passwordMaxLength"
                            />
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-key"></i> Guardar contraseña
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
