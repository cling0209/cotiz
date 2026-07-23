@extends('layouts.admin')

@section('title', 'Observación organismo')

@section('content')
<div class="container-fluid py-4" style="max-width:720px;">
    <div class="mb-4">
        <a href="{{ route('admin.organismos-observaciones.index') }}" class="btn btn-link btn-sm px-0">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
        <h1 class="h3 mb-1">Observaci&oacute;n del organismo</h1>
        <p class="text-muted mb-0 small">
            El ejecutivo ver&aacute; la sugerencia del administrador y el perfil autom&aacute;tico al cotizar.
            Al guardar, se sincroniza con el sitio par.
        </p>
    </div>

    @if($organismo->tieneObservacionAutomatica())
        <div class="alert alert-secondary small mb-3" role="status">
            <div class="fw-semibold mb-1">
                <i class="bi bi-robot"></i> Perfil autom&aacute;tico
                @if($organismo->observacion_automatica_casos)
                    <span class="text-muted fw-normal">({{ $organismo->observacion_automatica_casos }} CA)</span>
                @endif
            </div>
            <div style="white-space: pre-wrap;">{{ $organismo->observacion_automatica }}</div>
            @if($organismo->observacion_automatica_en)
                <div class="text-muted mt-1">
                    Calculado: {{ $organismo->observacion_automatica_en->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                </div>
            @endif
            @unless($puedeAnalizar)
                <div class="text-muted mt-1">Solo lectura aqu&iacute;; se calcula en el sitio con an&aacute;lisis admin.</div>
            @endunless
        </div>
    @elseif($puedeAnalizar)
        <div class="alert alert-light border small mb-3">
            A&uacute;n no hay perfil autom&aacute;tico. Use &laquo;Recalcular perfiles&raquo; en el listado (o espere el job semanal).
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" action="{{ route('admin.organismos-observaciones.update', $organismo) }}">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label class="form-label small mb-1">RUT</label>
                    <input type="text" class="form-control form-control-sm" value="{{ $organismo->rut_organismo }}" readonly>
                </div>

                <div class="mb-3">
                    <label class="form-label small mb-1" for="nombre">Nombre</label>
                    <input type="text" name="nombre" id="nombre" maxlength="200"
                           class="form-control form-control-sm @error('nombre') is-invalid @enderror"
                           value="{{ old('nombre', $organismo->nombre) }}">
                    @error('nombre')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label small mb-1" for="observacion">Observaci&oacute;n / sugerencia del administrador</label>
                    <textarea name="observacion" id="observacion" rows="6" maxlength="5000"
                              class="form-control form-control-sm @error('observacion') is-invalid @enderror"
                              placeholder="Ej.: Prefieren Brother; no ofrecer gen&#233;rico en tinta; contactar a Mar&#237;a antes de ofertar.">{{ old('observacion', $organismo->observacion) }}</textarea>
                    @error('observacion')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text">Deje vac&iacute;o para quitar la sugerencia visible al ejecutivo.</div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg"></i> Guardar y sincronizar
                    </button>
                    <a href="{{ route('admin.organismos-observaciones.index') }}" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
