@extends('layouts.admin')

@section('title', 'Asignar oportunidad '.$oportunidad->codigo)

@section('content')
<div class="container-fluid py-4" style="max-width: 32rem">
    <h1 class="h4 mb-3">Asignar oportunidad</h1>

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="small text-muted mb-3">
                C&oacute;digo: <strong><code>{{ strtoupper((string) $oportunidad->codigo) }}</code></strong><br>
                {{ $oportunidad->nombre ?: '—' }}<br>
                Organismo: {{ $oportunidad->organismo ?: '—' }}
            </p>

            <p class="small mb-3">
                Se crear&aacute; una cotizaci&oacute;n para el ejecutivo seleccionado y la oportunidad
                saldr&aacute; del listado.
            </p>

            @if($usuarios->isEmpty())
                <div class="alert alert-warning py-2 small mb-3">
                    No hay ejecutivos activos para asignar. Revise usuarios deshabilitados.
                </div>
            @endif

            <form method="post" action="{{ route('admin.oportunidades.para-cotizar.asignar.store', $oportunidad->codigo) }}">
                @csrf
                <div class="mb-3">
                    <label for="usuario" class="form-label">Ejecutivo</label>
                    <select name="usuario" id="usuario" class="form-select form-select-sm @error('usuario') is-invalid @enderror" required @disabled($usuarios->isEmpty())>
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
                    <a href="{{ route('admin.oportunidades.para-cotizar.index') }}" class="btn btn-outline-secondary btn-sm">Volver</a>
                    <button type="submit" class="btn btn-primary btn-sm" @disabled($usuarios->isEmpty())>Asignar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
