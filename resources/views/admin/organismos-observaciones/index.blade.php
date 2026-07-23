@extends('layouts.admin')

@section('title', 'Organismos observaciones')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Organismos observaciones</h1>
            <p class="text-muted mb-0 small">
                Solo organismos de <strong>cotizaciones MP cerradas</strong> (RUT + nombre fidedignos).
                Sugerencia del administrador (sincroniza Romulo &harr; Reicol) y perfil autom&aacute;tico
                (calculado semanalmente donde <code>MERCADOPUBLICO_ANALISIS_ADMIN=true</code>).
                Los nuevos se agregan al cerrar una CA.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" action="{{ route('admin.organismos-observaciones.reset-cerradas') }}"
                  data-confirm="¿Borrar todo y recrear solo desde cerradas? Se sincronizará al sitio par.">
                @csrf
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset desde cerradas
                </button>
            </form>
            @if($puedeAnalizar)
                <form method="post" action="{{ route('admin.organismos-observaciones.analizar') }}"
                      data-confirm="¿Recalcular ahora todos los perfiles automáticos y sincronizar al par?">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-repeat"></i> Recalcular perfiles
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="q">Buscar</label>
                    <input type="search" name="q" id="q" class="form-control form-control-sm"
                           placeholder="RUT, nombre u observaci&oacute;n..."
                           value="{{ request('q') }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                    <a href="{{ route('admin.organismos-observaciones.index') }}" class="btn btn-link btn-sm">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>RUT</th>
                        <th>Nombre</th>
                        <th>Autom&aacute;tico</th>
                        <th>Admin</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($organismos as $org)
                        <tr>
                            <td class="text-nowrap"><code>{{ $org->rut_organismo }}</code></td>
                            <td>{{ $org->nombre ?: '—' }}</td>
                            <td class="small" style="max-width:22rem;">
                                @if($org->tieneObservacionAutomatica())
                                    <span class="text-body">{{ \Illuminate\Support\Str::limit($org->observacion_automatica, 120) }}</span>
                                    @if($org->observacion_automatica_casos)
                                        <span class="text-muted d-block tabular-nums">{{ $org->observacion_automatica_casos }} CA</span>
                                    @endif
                                @else
                                    <span class="text-muted">Sin perfil</span>
                                @endif
                            </td>
                            <td class="small" style="max-width:22rem;">
                                @if($org->tieneObservacion())
                                    <span class="text-body">{{ \Illuminate\Support\Str::limit($org->observacion, 120) }}</span>
                                @else
                                    <span class="text-muted">Sin observaci&oacute;n</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.organismos-observaciones.edit', $org) }}"
                                   class="btn btn-outline-primary btn-sm py-0">
                                    <i class="bi bi-pencil"></i> Modificar
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                No hay organismos registrados a&uacute;n.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($organismos->hasPages())
            <div class="card-footer">
                {{ $organismos->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
