@extends('layouts.admin')

@section('title', 'Análisis precios Mercado Público')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Análisis precios — Compra Ágil</h1>
        @if($apiConfigurada)
            <form method="post" action="{{ route('admin.compra-agil.analisis.sync') }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-repeat"></i> Sincronizar ahora
                </button>
            </form>
        @else
            <span class="badge text-bg-warning">Configure MERCADOPUBLICO_TICKET</span>
        @endif
    </div>

    @if($ultimaSync)
        <div class="alert alert-light border small mb-3 py-2">
            <strong>Último análisis</strong><br>
            Inicio: {{ $ultimaSync->inicio->format('d/m/Y H:i') }}
            · Fin: {{ $ultimaSync->fin?->format('d/m/Y H:i') ?? '—' }}
            · Usuario: {{ $ultimaSync->usuario ?: '—' }}
            · Listados {{ $ultimaSync->listados }} · Detalles {{ $ultimaSync->detalles }}
            @if($ultimaSync->procesos_nuevos > 0)
                · Procesos nuevos {{ $ultimaSync->procesos_nuevos }}
            @endif
            @if($ultimaSync->estado === 'error')
                · <span class="text-danger">{{ $ultimaSync->mensaje }}</span>
            @endif
        </div>
    @else
        <p class="small text-muted mb-3">Aún no se ha ejecutado ningún análisis. Use «Sincronizar ahora» para cargar datos de Mercado Público.</p>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Productos catálogo</div>
                <div class="fs-4 fw-semibold">{{ number_format($kpi['total'], 0, ',', '.') }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Con datos MP</div>
                <div class="fs-4 fw-semibold text-success">{{ number_format($kpi['con_datos'], 0, ',', '.') }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Sin datos MP</div>
                <div class="fs-4 fw-semibold text-secondary">{{ number_format($kpi['sin_datos'], 0, ',', '.') }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100 border-warning"><div class="card-body py-3">
                <div class="text-muted small">Alertas desvío</div>
                <div class="fs-4 fw-semibold text-warning">{{ number_format($kpi['alertas'], 0, ',', '.') }}</div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link {{ $vista === 'vinculados' ? 'active' : '' }}"
                       href="{{ route('admin.compra-agil.analisis.index', array_merge(request()->except('page'), ['vista' => 'vinculados'])) }}">
                        Vinculados al catálogo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $vista === 'sin_vinculo' ? 'active' : '' }}"
                       href="{{ route('admin.compra-agil.analisis.index', array_merge(request()->except('page'), ['vista' => 'sin_vinculo'])) }}">
                        Sin vínculo (últimos {{ $diasAnalisis }} días)
                    </a>
                </li>
            </ul>
            <form method="get" action="{{ route('admin.compra-agil.analisis.index') }}" class="row g-3 align-items-end">
                <input type="hidden" name="vista" value="{{ $vista }}">
                <div class="col-md-4">
                    <label class="form-label" for="buscar">Buscar</label>
                    <input type="text" name="buscar" id="buscar" class="form-control form-control-sm" value="{{ $filtros['buscar'] }}"
                        placeholder="{{ $vista === 'sin_vinculo' ? 'Código o nombre MP' : 'Código/nombre catálogo o MP' }}">
                </div>
                @if($vista === 'vinculados')
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="solo_alertas" value="1" id="solo_alertas" @checked($filtros['solo_alertas'])>
                        <label class="form-check-label" for="solo_alertas">Solo alertas</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="solo_con_datos" value="1" id="solo_con_datos" @checked($filtros['solo_con_datos'])>
                        <label class="form-check-label" for="solo_con_datos">Con datos MP</label>
                    </div>
                </div>
                @endif
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    @if($vista === 'vinculados')
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Cód. catálogo</th>
                        <th>Nombre catálogo</th>
                        <th>Cód. MP</th>
                        <th>Nombre MP</th>
                        <th class="text-end">Tu precio</th>
                        <th class="text-end">Mediana MP</th>
                        <th class="text-end">Min–Max MP</th>
                        <th class="text-end">Desvío</th>
                        <th class="text-end">Obs.</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($productos as $row)
                        @php
                            $desvio = $row['desvio_pct'];
                            $rowClass = match (true) {
                                $desvio === null => '',
                                $desvio > 15 => 'table-danger',
                                $desvio > 5 => 'table-warning',
                                $desvio < -15 => 'table-info',
                                default => '',
                            };
                        @endphp
                        <tr class="{{ $rowClass }}">
                            <td class="font-monospace">{{ $row['prod_item'] }}</td>
                            <td>{{ $row['prod_nombre'] }}</td>
                            <td class="font-monospace small">
                                @if($row['agile_codigo'])
                                    {{ $row['agile_codigo'] }}
                                    @if($row['agile_codigos_extra'] > 0)
                                        <span class="text-muted">(+{{ $row['agile_codigos_extra'] }})</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small">
                                @if($row['agile_nombre'])
                                    {{ $row['agile_nombre'] }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end tabular-nums">${{ number_format($row['prod_valor'], 0, ',', '.') }}</td>
                            <td class="text-end tabular-nums">
                                @if($row['precio_mercado_mediana'])
                                    ${{ number_format($row['precio_mercado_mediana'], 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end tabular-nums small">
                                @if($row['precio_mercado_min'] && $row['precio_mercado_max'])
                                    ${{ number_format($row['precio_mercado_min'], 0, ',', '.') }}
                                    –
                                    ${{ number_format($row['precio_mercado_max'], 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end tabular-nums">
                                @if($desvio !== null)
                                    {{ $desvio > 0 ? '+' : '' }}{{ number_format($desvio, 1, ',', '.') }}%
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end">{{ $row['observaciones'] ?: '—' }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-primary btn-sm btn-detalle-benchmark"
                                    data-url="{{ route('admin.compra-agil.analisis.producto', ['prodItem' => $row['prod_item']]) }}"
                                    data-prod-nombre="{{ $row['prod_nombre'] }}">
                                    Ver
                                </button>
                                <a href="{{ route('admin.productos.edit', $row['prod_item']) }}" class="btn btn-outline-secondary btn-sm">Editar</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">
                            @if($kpi['total'] === 0)
                                No hay productos en el catálogo (maeprod). Cargue productos primero.
                            @elseif($kpi['con_datos'] === 0)
                                Sin datos de mercado aún. Ejecute sincronizar; la primera carga busca hasta {{ config('cotiz.mercadopublico.sync_dias_inicial') }} días atrás.
                            @else
                                Sin productos para los filtros aplicados.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($productos->hasPages())
            <div class="card-footer">{{ $productos->links() }}</div>
        @endif
    </div>
    @else
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Cód. MP</th>
                        <th>Nombre MP</th>
                        <th class="text-end">Procesos</th>
                        <th class="text-end">Unidades</th>
                        <th>Última obs.</th>
                        <th>Sugerencia catálogo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sinVinculo as $row)
                        <tr>
                            <td class="font-monospace">{{ $row['codigo_producto_mp'] }}</td>
                            <td>{{ $row['nombre_producto'] ?: '—' }}</td>
                            <td class="text-end tabular-nums">{{ number_format($row['procesos'], 0, ',', '.') }}</td>
                            <td class="text-end tabular-nums">{{ number_format($row['unidades'], 0, ',', '.') }}</td>
                            <td class="small">
                                @if($row['ultima_observacion'])
                                    {{ \Illuminate\Support\Carbon::parse($row['ultima_observacion'])->format('d/m/Y') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small">
                                @if($row['sugerencia_prod_item'])
                                    <span class="font-monospace">{{ $row['sugerencia_prod_item'] }}</span>
                                    — {{ $row['sugerencia_prod_nombre'] }}
                                @else
                                    <span class="text-muted">Sin sugerencia</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">
                            No hay productos MP adjudicados sin vínculo en los últimos {{ $diasAnalisis }} días.
                            Ejecute sincronizar o revise vínculos en agilemaeprod.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($sinVinculo->hasPages())
            <div class="card-footer">{{ $sinVinculo->links() }}</div>
        @endif
    </div>
    @endif
</div>

<div class="modal fade" id="modal-benchmark-detalle" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6" id="modal-benchmark-titulo">Detalle</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <h3 class="h6">Adjudicaciones MP (más barato → más caro)</h3>
                <div class="table-responsive mb-3">
                    <table class="table table-sm">
                        <thead><tr><th>Proceso</th><th>Cód. MP</th><th>Producto MP</th><th>Organismo</th><th>Precio/u</th><th>Fecha</th></tr></thead>
                        <tbody id="benchmark-lineas-mp"></tbody>
                    </table>
                </div>
                <h3 class="h6">Similares en catálogo</h3>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Código</th><th>Nombre</th><th class="text-end">Precio</th></tr></thead>
                        <tbody id="benchmark-similares"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.btn-detalle-benchmark').forEach(btn => {
    btn.addEventListener('click', async () => {
        const url = btn.dataset.url;
        document.getElementById('modal-benchmark-titulo').textContent = btn.dataset.prodNombre || '';
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        const fmt = n => '$' + (Number(n) || 0).toLocaleString('es-CL');
        document.getElementById('benchmark-lineas-mp').innerHTML = (data.lineas_mercado || []).map(l =>
            `<tr><td>${l.codigo_proceso}</td><td class="font-monospace">${l.codigo_producto_mp || '—'}</td><td>${l.nombre_producto || '—'}</td><td>${l.organismo || '—'}</td><td class="text-end">${fmt(l.precio_ganador_unitario)}</td><td>${l.fecha_proceso || '—'}</td></tr>`
        ).join('') || '<tr><td colspan="6" class="text-muted">Sin observaciones</td></tr>';
        document.getElementById('benchmark-similares').innerHTML = (data.similares_catalogo || []).map(s =>
            `<tr><td>${s.prod_item}</td><td>${s.prod_nombre}</td><td class="text-end">${fmt(s.prod_valor)}</td></tr>`
        ).join('') || '<tr><td colspan="3" class="text-muted">Sin similares</td></tr>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-benchmark-detalle')).show();
    });
});
</script>
@endpush
