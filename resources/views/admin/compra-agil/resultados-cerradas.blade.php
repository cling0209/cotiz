@extends('layouts.admin')

@section('title', 'Cerradas — Resultados Compra Ágil')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('admin.compra-agil.resultados.index') }}" class="btn btn-outline-secondary btn-sm" data-no-loader>
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <h1 class="h3 mb-0">Cerradas</h1>
        <span class="badge text-bg-secondary">{{ $cerradas->total() }}</span>
    </div>

    <form method="GET" action="{{ route('admin.compra-agil.resultados.cerradas') }}" class="card shadow-sm mb-3" data-no-loader>
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label for="f-nronota" class="form-label small mb-0">Nota</label>
                    <input type="number" class="form-control form-control-sm" id="f-nronota" name="nronota"
                        value="{{ $filtros['nronota'] ?? '' }}" placeholder="Ej: 1234" style="width:7rem">
                </div>
                <div class="col-auto">
                    <label for="f-codigo" class="form-label small mb-0">Código CA</label>
                    <input type="text" class="form-control form-control-sm" id="f-codigo" name="codigo_proceso"
                        value="{{ $filtros['codigo_proceso'] ?? '' }}" placeholder="Ej: 2923-..." style="width:10rem">
                </div>
                <div class="col-auto">
                    <label for="f-organismo" class="form-label small mb-0">Organismo</label>
                    <input type="text" class="form-control form-control-sm" id="f-organismo" name="organismo"
                        value="{{ $filtros['organismo'] ?? '' }}" placeholder="Buscar..." style="width:12rem">
                </div>
                <div class="col-auto">
                    <label for="f-proveedor" class="form-label small mb-0">Prov. seleccionado</label>
                    <input type="text" class="form-control form-control-sm" id="f-proveedor" name="proveedor"
                        value="{{ $filtros['proveedor'] ?? '' }}" placeholder="Razón social..." style="width:12rem">
                </div>
                <div class="col-auto">
                    <label for="f-convocatoria" class="form-label small mb-0">Estado convocatoria</label>
                    <select class="form-select form-select-sm" id="f-convocatoria" name="convocatoria" style="width:12rem">
                        <option value="">Todos</option>
                        <option value="Primer llamado" @selected(($filtros['convocatoria'] ?? '') === 'Primer llamado')>Primer llamado</option>
                        <option value="Segundo llamado" @selected(($filtros['convocatoria'] ?? '') === 'Segundo llamado')>Segundo llamado</option>
                        <option value="sin" @selected(($filtros['convocatoria'] ?? '') === 'sin')>Sin convocatoria</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label for="f-fecha-desde" class="form-label small mb-0">Publicación desde</label>
                    <input type="date" class="form-control form-control-sm" id="f-fecha-desde" name="fecha_desde"
                        value="{{ $filtros['fecha_desde'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-fecha-hasta" class="form-label small mb-0">Publicación hasta</label>
                    <input type="date" class="form-control form-control-sm" id="f-fecha-hasta" name="fecha_hasta"
                        value="{{ $filtros['fecha_hasta'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-cambio-desde" class="form-label small mb-0">Último cambio desde</label>
                    <input type="date" class="form-control form-control-sm" id="f-cambio-desde" name="cambio_desde"
                        value="{{ $filtros['cambio_desde'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-cambio-hasta" class="form-label small mb-0">Último cambio hasta</label>
                    <input type="date" class="form-control form-control-sm" id="f-cambio-hasta" name="cambio_hasta"
                        value="{{ $filtros['cambio_hasta'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search"></i> Filtrar
                    </button>
                    @if(collect($filtros)->filter()->isNotEmpty())
                        <a href="{{ route('admin.compra-agil.resultados.cerradas') }}" class="btn btn-outline-secondary btn-sm ms-1" data-no-loader>
                            <i class="bi bi-x-lg"></i> Limpiar
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <p class="text-muted small mb-0">Filas en verde: ganador propio ({{ config('cotiz.sistema') }}).</p>
            @if($cerradas->total() > 0)
                <a href="{{ route('admin.compra-agil.resultados.cerradas.exportar', request()->query()) }}" class="btn btn-outline-success btn-sm" download data-no-loader>
                    <i class="bi bi-file-earmark-spreadsheet"></i> Descargar CSV
                </a>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nota</th>
                        <th>Código CA</th>
                        <th>Publicación</th>
                        <th>Último cambio</th>
                        <th>Cierre 1er llamado</th>
                        <th>Cierre 2do llamado</th>
                        <th>Estado convocatoria</th>
                        <th>Ejecutivo</th>
                        <th>Organismo</th>
                        <th>Estado MP</th>
                        <th>Seguimiento</th>
                        <th>Prov. seleccionado</th>
                        <th class="text-end">Monto</th>
                        <th>Consultado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cerradas as $seg)
                        <tr class="{{ !empty($seg->es_ganador_propio) ? 'table-success' : '' }}">
                            <td>{{ $seg->nronota }}</td>
                            <td class="font-monospace small">{{ $seg->codigo_proceso }}</td>
                            <td class="small text-muted">{{ $seg->fecha_publicacion?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small text-muted">{{ $seg->fecha_ultimo_cambio?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small text-muted">{{ $seg->fecha_cierre_primer_llamado?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small text-muted">{{ $seg->fecha_cierre_segundo_llamado?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small">
                                @if($seg->convocatoria_descripcion)
                                    {{ $seg->convocatoria_descripcion }}
                                @elseif($seg->convocatoria_estado !== null)
                                    {{ $seg->convocatoria_estado }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small">{{ $seg->nota?->usuarioRel?->fullName() ?: ($seg->nota?->usuario ?: '—') }}</td>
                            <td class="small">{{ Str::limit($seg->organismo, 40) }}</td>
                            <td class="small">{{ $seg->estado_mp_glosa ?: $seg->estado_mp_codigo }}</td>
                            <td>@include('admin.compra-agil.partials.resultado-badge', ['resultado' => $seg->resultado_propio])</td>
                            <td class="small">
                                @if($seg->razon_social_ganador)
                                    {{ Str::limit($seg->razon_social_ganador, 30) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end small">
                                @if($seg->monto_total_ganador)
                                    ${{ number_format($seg->monto_total_ganador, 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small text-muted cell-consultado">{{ $seg->textoConsultado() }}</td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-outline-primary btn-sm btn-comparar-mp" data-nronota="{{ $seg->nronota }}" title="Comparar precios Prov. seleccionado vs {{ config('cotiz.sistema') }}"><i class="bi bi-arrow-left-right"></i> Comparar</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="{{ $seg->nronota }}">Detalle</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="15" class="text-center text-muted py-4">Sin resultados para los filtros aplicados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($cerradas->hasPages())
            <div class="card-footer py-2 d-flex justify-content-center">
                {{ $cerradas->links() }}
            </div>
        @endif
    </div>
</div>

@include('admin.compra-agil.partials.modal-detalle-mp')
@endsection
