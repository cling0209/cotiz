@extends('layouts.shop')

@section('title', 'Recuperar contraseña')

@section('content')
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="checkout-card card p-4">
                <h1 class="h4 fw-bold mb-1">Recuperar contraseña</h1>
                <p class="text-muted small mb-4">
                    Ingresa el correo de tu cuenta. Te enviaremos un enlace para restablecer la contraseña.
                </p>

                <form method="post" action="{{ route('account.password.email') }}">
                    @csrf
                    <div class="mb-4">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror" required autofocus>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100 rounded-pill mb-3">Enviar enlace</button>
                    <a href="{{ route('account.login') }}" class="btn btn-link w-100">Volver al inicio de sesión</a>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection
