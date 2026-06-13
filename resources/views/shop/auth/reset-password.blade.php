@extends('layouts.shop')

@section('title', 'Nueva contraseña')

@section('content')
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="checkout-card card p-4">
                <h1 class="h4 fw-bold mb-1">Nueva contraseña</h1>
                <p class="text-muted small mb-4">Define una nueva contraseña para tu cuenta.</p>

                <form method="post" action="{{ route('account.password.update') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="email" value="{{ $email }}">

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
                    <button type="submit" class="btn btn-primary w-100 rounded-pill">Guardar contraseña</button>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
@endpush
