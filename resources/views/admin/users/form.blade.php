@extends('layouts.admin')

@section('title', $usuario ? 'Editar usuario' : 'Nuevo usuario')

@section('content')
@php
    $esNuevo = ! $usuario;
    $action = $esNuevo ? route('admin.users.store') : route('admin.users.update', $usuario);
@endphp

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h4 mb-0">{{ $esNuevo ? 'Nuevo usuario' : 'Editar usuario' }}</h1>
        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Listado</a>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="post" action="{{ $action }}">
                        @csrf
                        @if(! $esNuevo) @method('PUT') @endif

                        <div class="mb-3">
                            <label class="form-label">Usuario (login) <span class="text-danger">*</span></label>
                            @if($esNuevo)
                                <input type="text" name="username" class="form-control form-control-sm @error('username') is-invalid @enderror"
                                       value="{{ old('username') }}" maxlength="20" required autofocus>
                                @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @else
                                <input type="text" class="form-control form-control-sm" value="{{ $usuario->username }}" readonly>
                            @endif
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Nombre <span class="text-danger">*</span></label>
                                <input type="text" name="nombre" class="form-control form-control-sm @error('nombre') is-invalid @enderror"
                                       value="{{ old('nombre', $usuario?->nombre) }}" maxlength="20" required>
                                @error('nombre')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Apellido paterno</label>
                                <input type="text" name="apellidop" class="form-control form-control-sm"
                                       value="{{ old('apellidop', $usuario?->apellidop) }}" maxlength="30">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Apellido materno</label>
                                <input type="text" name="apellidom" class="form-control form-control-sm"
                                       value="{{ old('apellidom', $usuario?->apellidom) }}" maxlength="20">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Correo</label>
                                <input type="email" name="correo" class="form-control form-control-sm @error('correo') is-invalid @enderror"
                                       value="{{ old('correo', $usuario?->correo) }}" maxlength="60">
                                @error('correo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Perfil <span class="text-danger">*</span></label>
                            <select name="perfil" id="perfil" class="form-select form-select-sm @error('perfil') is-invalid @enderror" required>
                                <option value="{{ \App\Models\User::PERFIL_SUPERADMIN }}" @selected((int) old('perfil', $usuario?->perfil) === \App\Models\User::PERFIL_SUPERADMIN)>
                                    Superadministrador (mantenedores + todo)
                                </option>
                                <option value="{{ \App\Models\User::PERFIL_EJECUTIVO }}" @selected((int) old('perfil', $usuario?->perfil ?? \App\Models\User::PERFIL_EJECUTIVO) === \App\Models\User::PERFIL_EJECUTIVO)>
                                    Ejecutivo (solo cotizaciones propias)
                                </option>
                            </select>
                            @error('perfil')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3" id="permiso-frases-wrap">
                            <div class="form-check">
                                <input type="hidden" name="puede_gestionar_frases" value="0">
                                <input type="checkbox"
                                       class="form-check-input"
                                       name="puede_gestionar_frases"
                                       id="puede_gestionar_frases"
                                       value="1"
                                       @checked((bool) old('puede_gestionar_frases', $usuario?->puede_gestionar_frases))>
                                <label class="form-check-label" for="puede_gestionar_frases">
                                    Puede agregar y eliminar frases Agile
                                </label>
                            </div>
                            <div class="form-text">
                                Solo aplica a ejecutivos. Les permite vincular productos con frases manuales (prioridad sobre el aprendizaje autom&aacute;tico).
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="password">
                                {{ $esNuevo ? 'Contraseña' : 'Nueva contraseña' }}
                                @if($esNuevo)<span class="text-danger">*</span>@else<span class="text-muted small">(opcional)</span>@endif
                            </label>
                            <x-password-input
                                name="password"
                                id="password"
                                :required="$esNuevo"
                                autocomplete="new-password"
                                class="form-control-sm @error('password') is-invalid @enderror"
                            />
                            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password_confirmation">Confirmar contrase&ntilde;a</label>
                            <x-password-input
                                name="password_confirmation"
                                id="password_confirmation"
                                autocomplete="new-password"
                                class="form-control-sm"
                            />
                            <div class="form-text">M&iacute;nimo 8 caracteres, letras y n&uacute;meros. M&aacute;x. {{ $passwordMaxLength }}.</div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var perfil = document.getElementById('perfil');
    var wrap = document.getElementById('permiso-frases-wrap');
    var check = document.getElementById('puede_gestionar_frases');
    if (!perfil || !wrap) return;

    var ejecutivo = '{{ \App\Models\User::PERFIL_EJECUTIVO }}';

    function sync() {
        var esEjecutivo = String(perfil.value) === String(ejecutivo);
        wrap.classList.toggle('d-none', !esEjecutivo);
        if (!esEjecutivo && check) {
            check.checked = false;
        }
    }

    perfil.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
