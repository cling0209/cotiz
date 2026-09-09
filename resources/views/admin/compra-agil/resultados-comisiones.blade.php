@extends('layouts.admin')

@section('title', 'Comisiones — Resultados Compra Ágil')

@section('content')
@php
    $comisionesRetorno = \App\Support\CotizacionListadoRetorno::paraComisiones(
        array_merge($filtros, ['por_pagina' => $items->perPage()]),
        (int) $items->currentPage()
    );
@endphp
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('admin.compra-agil.resultados.index') }}" class="btn btn-outline-secondary btn-sm" data-no-loader>
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <h1 class="h3 mb-0">Comisiones</h1>
        <span class="badge text-bg-secondary">{{ $items->total() }}</span>
    </div>

    <p class="text-muted small mb-3">
        Cotizaciones con registro en Mercado Público (cualquier estado), con o sin orden de compra.
        La <strong>comisión 20%</strong> solo aplica a <strong>ganadas</strong>: ganador Reicol/Rómulo <strong>y</strong> con orden de compra en la nota
        (si en MP hay OC pero aún no está el número, no aplica comisión).
        El <strong>pago</strong> (${{ number_format($pagoFijo, 0, ',', '.') }}) solo aplica si <strong>esta empresa participó</strong> en MP.
        Si MP aún no muestra proveedores cotizando, se indica <em>Sin proveedores en MP</em> (pago $0 hasta confirmar).
        Si ya hay proveedores y no está esta empresa, <em>No participó</em> (pago $0).
    </p>

    <form method="GET" action="{{ route('admin.compra-agil.resultados.comisiones') }}" class="card shadow-sm mb-3" data-no-loader>
        @if(!empty($filtros['sort']))
            <input type="hidden" name="sort" value="{{ $filtros['sort'] }}">
        @endif
        @if(!empty($filtros['dir']))
            <input type="hidden" name="dir" value="{{ $filtros['dir'] }}">
        @endif
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label for="f-creacion-desde" class="form-label small mb-0">Fecha de creación desde</label>
                    <input type="date" class="form-control form-control-sm" id="f-creacion-desde" name="fecha_creacion_desde"
                        value="{{ $filtros['fecha_creacion_desde'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-creacion-hasta" class="form-label small mb-0">Fecha de creación hasta</label>
                    <input type="date" class="form-control form-control-sm" id="f-creacion-hasta" name="fecha_creacion_hasta"
                        value="{{ $filtros['fecha_creacion_hasta'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-envio-desde" class="form-label small mb-0">Fecha envío OC desde</label>
                    <input type="date" class="form-control form-control-sm" id="f-envio-desde" name="fecha_envio_desde"
                        value="{{ $filtros['fecha_envio_desde'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-envio-hasta" class="form-label small mb-0">Fecha envío OC hasta</label>
                    <input type="date" class="form-control form-control-sm" id="f-envio-hasta" name="fecha_envio_hasta"
                        value="{{ $filtros['fecha_envio_hasta'] ?? '' }}">
                </div>
                @include('admin.compra-agil.partials.filtro-ejecutivo')
                <div class="col-auto">
                    <label for="f-nronota" class="form-label small mb-0">Nº nota</label>
                    <input type="number" class="form-control form-control-sm" id="f-nronota" name="nronota"
                        value="{{ $filtros['nronota'] ?? '' }}" placeholder="Ej: 1234" style="width:7rem">
                </div>
                <div class="col-auto">
                    <label for="f-codigo" class="form-label small mb-0">Código cotización (CA)</label>
                    <input type="text" class="form-control form-control-sm" id="f-codigo" name="codigo_proceso"
                        value="{{ $filtros['codigo_proceso'] ?? '' }}" placeholder="Ej: 2923-..." style="width:10rem">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search"></i> Filtrar
                    </button>
                    @if(collect($filtros)->except(['sort', 'dir'])->filter()->isNotEmpty())
                        <a href="{{ route('admin.compra-agil.resultados.comisiones', request()->only(['sort', 'dir'])) }}" class="btn btn-outline-secondary btn-sm ms-1" data-no-loader>
                            <i class="bi bi-x-lg"></i> Limpiar
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <p class="text-muted small mb-0">La descarga respeta los filtros actuales (todos o la selección filtrada).</p>
            @if($items->total() > 0)
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.compra-agil.resultados.comisiones.exportar-detalle', request()->query()) }}" class="btn btn-outline-success btn-sm" download data-no-loader>
                        <i class="bi bi-file-earmark-spreadsheet"></i> Descargar detalle
                    </a>
                    <a href="{{ route('admin.compra-agil.resultados.comisiones.exportar-resumen', request()->query()) }}" class="btn btn-outline-success btn-sm" download data-no-loader>
                        <i class="bi bi-people"></i> Descargar resumen por ejecutivo
                    </a>
                </div>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        @include('admin.compra-agil.partials.th-sortable', ['col' => 'nronota', 'label' => 'Nota', 'route' => 'admin.compra-agil.resultados.comisiones'])
                        @include('admin.compra-agil.partials.th-sortable', ['col' => 'fecha_creacion', 'label' => 'Fecha de creación', 'route' => 'admin.compra-agil.resultados.comisiones'])
                        @include('admin.compra-agil.partials.th-sortable', ['col' => 'codigo_proceso', 'label' => 'Código CA', 'route' => 'admin.compra-agil.resultados.comisiones'])
                        @include('admin.compra-agil.partials.th-sortable', ['col' => 'seguimiento', 'label' => 'Seguimiento', 'route' => 'admin.compra-agil.resultados.comisiones'])
                        <th>Participó MP</th>
                        <th>Ganada</th>
                        <th>Código OC</th>
                        @include('admin.compra-agil.partials.th-sortable', ['col' => 'fecha_envio', 'label' => 'Fecha envío OC', 'route' => 'admin.compra-agil.resultados.comisiones'])
                        <th>Ejecutivo</th>
                        <th>Región</th>
                        <th class="text-end">Factor</th>
                        <th class="text-end">Costo</th>
                        <th class="text-end">Venta</th>
                        <th class="text-end">Venta {{ number_format($factorBase, 1, ',', '.') }}</th>
                        <th class="text-end">Utilidad</th>
                        <th class="text-end">20% Comisión</th>
                        <th class="text-end">Pago</th>
                        <th class="text-end">A pagar</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $fila)
                        @php
                            $filaClass = $fila->es_ganada
                                ? 'table-success'
                                : ($fila->participacion_mp === \App\Services\CompraAgilComisionesService::PARTICIPACION_NO
                                    ? 'table-warning'
                                    : '');
                        @endphp
                        <tr class="{{ $filaClass }}">
                            <td class="text-nowrap">{{ $fila->nronota }}</td>
                            <td class="small text-nowrap">{{ $fila->fecha_creacion?->format('d/m/Y') ?? '—' }}</td>
                            <td class="font-monospace small">{{ $fila->codigo_proceso ?: '—' }}</td>
                            <td class="cell-seguimiento">@include('admin.compra-agil.partials.resultado-badge', ['resultado' => $fila->resultado_propio])</td>
                            <td class="small">
                                @if($fila->participacion_mp === \App\Services\CompraAgilComisionesService::PARTICIPACION_SI)
                                    <span class="badge text-bg-success">Sí</span>
                                @elseif($fila->participacion_mp === \App\Services\CompraAgilComisionesService::PARTICIPACION_SIN_PROVEEDORES)
                                    <span class="badge text-bg-info" title="Mercado Público aún no muestra proveedores cotizando">Sin proveedores en MP</span>
                                @else
                                    <span class="badge text-bg-warning">No participó</span>
                                @endif
                            </td>
                            <td class="small">{{ $fila->es_ganada ? 'Sí' : 'No' }}</td>
                            <td class="small font-monospace">{{ $fila->orden_compra ?: '—' }}</td>
                            <td class="small text-muted">{{ $fila->fecha_envio_oc?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small">{{ $fila->ejecutivo }}</td>
                            <td class="small">{{ $fila->region_nombre }}</td>
                            <td class="text-end small tabular-nums">{{ number_format($fila->factor, 2, ',', '.') }}</td>
                            <td class="text-end small tabular-nums">${{ number_format($fila->costo, 0, ',', '.') }}</td>
                            <td class="text-end small tabular-nums">${{ number_format($fila->venta, 0, ',', '.') }}</td>
                            <td class="text-end small tabular-nums">${{ number_format($fila->venta_12, 0, ',', '.') }}</td>
                            <td class="text-end small tabular-nums">${{ number_format($fila->utilidad, 0, ',', '.') }}</td>
                            <td class="text-end small tabular-nums">${{ number_format($fila->comision_20, 0, ',', '.') }}</td>
                            <td class="text-end small tabular-nums">${{ number_format($fila->pago, 0, ',', '.') }}</td>
                            <td class="text-end small fw-semibold tabular-nums">${{ number_format($fila->a_pagar, 0, ',', '.') }}</td>
                            <td class="text-end text-nowrap">
                                <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-sm btn-detalle-mp"
                                            data-nronota="{{ $fila->nronota }}"
                                            title="Ver participantes y detalle en Mercado Público">
                                        Detalle MP
                                    </button>
                                    <a href="{{ route('admin.cotizaciones.edit', array_merge(['nronota' => $fila->nronota], $comisionesRetorno)) }}"
                                       class="btn btn-outline-primary btn-sm" title="Ir a la nota">
                                        <i class="bi bi-box-arrow-up-right"></i> Nota
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="19" class="text-center text-muted py-4">Sin cotizaciones con seguimiento MP para los filtros aplicados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer border-top-0 pt-0">
            <x-listado-paginacion :paginator="$items" entity-label="cotizaciones MP" />
        </div>
    </div>
</div>

@include('admin.compra-agil.partials.modal-detalle-mp')
@endsection
