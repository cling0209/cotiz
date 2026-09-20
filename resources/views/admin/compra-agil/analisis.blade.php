@extends('layouts.admin')

@section('title', 'Precios competencia — Compra Ágil')

@section('content')
@php
    $fmtCant = function ($n): string {
        $n = (float) $n;
        $decimales = abs($n - round($n)) < 0.001 ? 0 : 2;

        return number_format($n, $decimales, ',', '.');
    };
    $fmtPrecio = function ($n): string {
        if ($n === null || $n === '') {
            return '—';
        }

        return '$'.number_format((int) $n, 0, ',', '.');
    };
    $sortUrl = function (string $columna) use ($filtros): string {
        $dir = ($filtros['orden'] === $columna && $filtros['dir'] === 'desc') ? 'asc' : 'desc';

        return route('admin.compra-agil.analisis.index', array_merge(request()->except('page'), [
            'orden' => $columna,
            'dir' => $dir,
        ]));
    };
    $sortMark = function (string $columna) use ($filtros): string {
        if ($filtros['orden'] !== $columna) {
            return '';
        }

        return $filtros['dir'] === 'asc' ? ' ↑' : ' ↓';
    };
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Precios y cantidades — Compra Ágil</h1>
    </div>

    <p class="small text-muted mb-3">Cant. total es lo cotizado. Adjudicada propio y adjudicadas otros es lo ganado. Tu precio es el de la última nota. Más barato y más caro salen de la última nota en la que cotizó otra empresa. Si no indicas fechas, se usa todo el historial.</p>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Productos</div>
                <div class="fs-4 fw-semibold">{{ number_format($kpi['productos'], 0, ',', '.') }}</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Cant. total</div>
                <div class="fs-4 fw-semibold">{{ $fmtCant($kpi['unidades_propias'] + $kpi['unidades_otros']) }}</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-warning"><div class="card-body py-3">
                <div class="text-muted small">Donde estás más caro</div>
                <div class="fs-4 fw-semibold text-warning">{{ number_format($kpi['mas_caro'], 0, ',', '.') }}</div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="{{ route('admin.compra-agil.analisis.index') }}" class="row g-3 align-items-end">
                <input type="hidden" name="orden" value="{{ $filtros['orden'] }}">
                <input type="hidden" name="dir" value="{{ $filtros['dir'] }}">
                <div class="col-md-4">
                    <label class="form-label" for="buscar">Buscar</label>
                    <input type="text" name="buscar" id="buscar" class="form-control form-control-sm" value="{{ $filtros['buscar'] }}"
                        placeholder="Código o descripción propia">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="fecha_desde">Desde</label>
                    <input type="date" name="fecha_desde" id="fecha_desde" class="form-control form-control-sm" value="{{ $filtros['fecha_desde'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="fecha_hasta">Hasta</label>
                    <input type="date" name="fecha_hasta" id="fecha_hasta" class="form-control form-control-sm" value="{{ $filtros['fecha_hasta'] }}">
                </div>
                <div class="col-md-auto d-flex gap-2">
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                    <a href="{{ route('admin.compra-agil.analisis.excel', array_filter([
                        'buscar' => $filtros['buscar'] !== '' ? $filtros['buscar'] : null,
                        'fecha_desde' => $filtros['fecha_desde'] !== '' ? $filtros['fecha_desde'] : null,
                        'fecha_hasta' => $filtros['fecha_hasta'] !== '' ? $filtros['fecha_hasta'] : null,
                        'orden' => $filtros['orden'],
                        'dir' => $filtros['dir'],
                    ])) }}" class="btn btn-outline-success btn-sm" data-no-loader>
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Cód. propio</th>
                        <th>Descripción propia</th>
                        <th class="text-end"><a class="link-light text-decoration-none" href="{{ $sortUrl('cant_total') }}">Cant. total{{ $sortMark('cant_total') }}</a></th>
                        <th class="text-end"><a class="link-light text-decoration-none" href="{{ $sortUrl('adjudicada_propia') }}">Adjudicada propio{{ $sortMark('adjudicada_propia') }}</a></th>
                        <th class="text-end"><a class="link-light text-decoration-none" href="{{ $sortUrl('adjudicada_otros') }}">Adjudicadas otros{{ $sortMark('adjudicada_otros') }}</a></th>
                        <th class="text-end">Tu precio</th>
                        <th class="text-end">Más barato</th>
                        <th class="text-end">Más caro</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($productos as $row)
                        <tr>
                            <td class="font-monospace">{{ $row['prod_item'] }}</td>
                            <td>{{ $row['prod_nombre'] !== '' ? $row['prod_nombre'] : '—' }}</td>
                            <td class="text-end tabular-nums">{{ $fmtCant($row['cant_total']) }}</td>
                            <td class="text-end tabular-nums">{{ $fmtCant($row['adjudicada_propia']) }}</td>
                            <td class="text-end tabular-nums">{{ $fmtCant($row['adjudicada_otros']) }}</td>
                            <td class="text-end tabular-nums">{{ $fmtPrecio($row['tu_precio']) }}</td>
                            <td class="text-end tabular-nums">{{ $fmtPrecio($row['precio_min']) }}</td>
                            <td class="text-end tabular-nums">{{ $fmtPrecio($row['precio_max']) }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-primary btn-sm btn-detalle-competencia"
                                    data-url="{{ route('admin.compra-agil.analisis.producto', ['prodItem' => $row['prod_item']]) }}"
                                    data-prod="{{ $row['prod_item'] }} — {{ $row['prod_nombre'] }}">
                                    Ver
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No hay productos cotizados para ese filtro.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer border-top-0 pt-0">
            <x-listado-paginacion :paginator="$productos" entity-label="productos" screen-key="compra-agil-analisis" />
        </div>
    </div>
</div>

<div class="modal fade" id="modal-competencia-detalle" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6" id="modal-competencia-titulo">Detalle</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <div class="row g-2 mb-3">
                    <div class="col-sm-4">
                        <div class="border rounded px-3 py-2">
                            <div class="text-muted small">Cant. total</div>
                            <div class="fs-5 fw-semibold" id="modal-competencia-cant-total">—</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="border rounded px-3 py-2">
                            <div class="text-muted small">Adjudicada propio</div>
                            <div class="fs-5 fw-semibold" id="modal-competencia-adj-propia">—</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="border rounded px-3 py-2">
                            <div class="text-muted small">Adjudicadas otros</div>
                            <div class="fs-5 fw-semibold" id="modal-competencia-adj-otros">—</div>
                        </div>
                    </div>
                </div>
                <p class="small mb-2 text-danger" id="modal-competencia-resumen"></p>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Empresa</th>
                                <th class="text-end">Cant. adjudicada</th>
                            </tr>
                        </thead>
                        <tbody id="modal-competencia-lineas"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.btn-detalle-competencia').forEach(btn => {
    btn.addEventListener('click', async () => {
        const params = new URLSearchParams(window.location.search);
        const qs = new URLSearchParams();
        if (params.get('fecha_desde')) qs.set('fecha_desde', params.get('fecha_desde'));
        if (params.get('fecha_hasta')) qs.set('fecha_hasta', params.get('fecha_hasta'));
        const url = btn.dataset.url + (qs.toString() ? '?' + qs.toString() : '');
        document.getElementById('modal-competencia-titulo').textContent = btn.dataset.prod || 'Detalle';
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        const cant = n => (Number(n) || 0).toLocaleString('es-CL');
        if (!res.ok) {
            document.getElementById('modal-competencia-cant-total').textContent = '—';
            document.getElementById('modal-competencia-adj-propia').textContent = '—';
            document.getElementById('modal-competencia-adj-otros').textContent = '—';
            document.getElementById('modal-competencia-resumen').textContent = data.error || 'No se pudo cargar el detalle.';
            document.getElementById('modal-competencia-lineas').innerHTML = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-competencia-detalle')).show();
            return;
        }
        document.getElementById('modal-competencia-resumen').textContent = '';
        document.getElementById('modal-competencia-cant-total').textContent = cant(data.cant_total);
        document.getElementById('modal-competencia-adj-propia').textContent = cant(data.adjudicada_propia);
        document.getElementById('modal-competencia-adj-otros').textContent = cant(data.adjudicada_otros);
        document.getElementById('modal-competencia-lineas').innerHTML = (data.lineas || []).map(l => {
            return `<tr><td>${l.proveedor || '—'}</td><td class="text-end">${cant(l.cantidad_adjudicada)}</td></tr>`;
        }).join('') || '<tr><td colspan="2" class="text-muted">Sin adjudicaciones de otras empresas</td></tr>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-competencia-detalle')).show();
    });
});
</script>
@endpush
