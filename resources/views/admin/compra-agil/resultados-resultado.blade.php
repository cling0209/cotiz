@extends('layouts.admin')

@section('title', 'Resultado último proceso')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('admin.compra-agil.resultados.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <h1 class="h3 mb-0">Resultado último proceso</h1>
    </div>

    @if($ultimaCorrida)
        <div class="alert alert-light border small mb-3 py-2">
            <strong>Última consulta</strong><br>
            Usuario: {{ $ultimaCorrida->usuario }}
            · Inicio: {{ $ultimaCorrida->inicio->format('d/m/Y H:i:s') }}
            · Fin: {{ $ultimaCorrida->fin?->format('d/m/Y H:i:s') ?? '—' }}
            · Procesadas: {{ $ultimaCorrida->notas_procesadas }}
            · Con cambio: {{ $ultimaCorrida->notas_con_cambio }}
        </div>

        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0">Resultado por cotización</h2>
                @if($detalleCorrida->total() > 0)
                    <span class="small text-muted">
                        {{ $detalleCorrida->total() }} registros · Página {{ $detalleCorrida->currentPage() }} de {{ $detalleCorrida->lastPage() }}
                    </span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nota</th>
                            <th>Código CA</th>
                            <th>Cliente</th>
                            <th>Resultado</th>
                            <th>Error / detalle</th>
                            <th>Estado MP</th>
                            <th>Prov. seleccionado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($detalleCorrida as $det)
                            <tr class="{{ $det->exito ? '' : 'table-light' }}">
                                <td>{{ $det->nronota }}</td>
                                <td class="font-monospace small">{{ $det->codigo_proceso }}</td>
                                <td class="small">{{ $det->empresa ?: '—' }}</td>
                                <td>
                                    @if($det->exito)
                                        @include('admin.compra-agil.partials.resultado-badge', ['resultado' => $det->resultado_propio])
                                        @if($det->cambio)
                                            <span class="badge text-bg-info ms-1">Cambio</span>
                                        @endif
                                    @else
                                        <span class="badge text-bg-danger">Error</span>
                                    @endif
                                </td>
                                <td class="small text-break" style="min-width: 12rem; max-width: 22rem;">
                                    @if($det->exito)
                                        <span class="text-muted">—</span>
                                    @else
                                        <span class="text-danger">{{ $det->mensaje ?: 'Error desconocido' }}</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($det->exito)
                                        {{ $det->estado_mp_glosa ?: '—' }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($det->razon_social_ganador)
                                        {{ $det->razon_social_ganador }}<br>
                                        <span class="text-muted">{{ $det->rut_ganador }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if($det->exito)
                                        <button type="button" class="btn btn-outline-primary btn-sm btn-comparar-mp" data-nronota="{{ $det->nronota }}" title="Comparar precios Prov. seleccionado vs {{ config('cotiz.sistema') }}"><i class="bi bi-arrow-left-right"></i> Comparar</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="{{ $det->nronota }}">Detalle</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    Sin detalle registrado en la última consulta.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($detalleCorrida->hasPages())
                <div class="card-footer py-2 d-flex justify-content-center">
                    {{ $detalleCorrida->links() }}
                </div>
            @endif
        </div>
    @else
        <div class="alert alert-info">No se ha ejecutado ninguna consulta aún.</div>
    @endif
</div>

@include('admin.compra-agil.partials.modal-detalle-mp')
@endsection
