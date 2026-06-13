@extends('layouts.admin')

@section('title', 'Asignar cotización #'.$nota->nronota)

@section('content')
<div class="container-fluid py-4" style="max-width: 32rem">
    <h1 class="h4 mb-3">Asignar cotizaci&oacute;n #{{ $nota->nronota }}</h1>

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="small text-muted mb-3">
                Cotizaci&oacute;n: <strong>{{ $nota->encargado ?: '—' }}</strong><br>
                Empresa: {{ $nota->empresa ?: '—' }}
            </p>

            <form method="post" action="{{ route('admin.cotizaciones.asignar.store', $nota->nronota) }}">
                @csrf
                <div class="mb-3">
                    <label for="usuario" class="form-label">Usuario</label>
                    <select name="usuario" id="usuario" class="form-select form-select-sm @error('usuario') is-invalid @enderror" required>
                        <option value="">(Seleccione)</option>
                        @foreach($usuarios as $u)
                            <option value="{{ $u->username }}" @selected(old('usuario') === $u->username)>
                                {{ $u->fullName() ?: $u->username }} ({{ $u->username }})
                            </option>
                        @endforeach
                    </select>
                    @error('usuario')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="d-flex gap-2">
                    <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">Volver</a>
                    <button type="submit" class="btn btn-primary btn-sm">Asignar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
