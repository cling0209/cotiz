@extends('layouts.admin')

@section('title', 'Cotización '.$nota->nronota)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Cotizaci&oacute;n #{{ $nota->nronota }}</h1>
        <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Listado</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Datos cliente</div>
                <div class="card-body">
                    <form method="post" action="{{ route('admin.cotizaciones.update', $nota->nronota) }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-2">
                            <label class="form-label">Descripci&oacute;n</label>
                            <input type="text" name="descripcion" class="form-control form-control-sm" value="{{ old('descripcion', $nota->descripcion) }}" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Empresa</label>
                            <input type="text" name="empresa" class="form-control form-control-sm" value="{{ old('empresa', $nota->empresa) }}">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">N&ordm; cotizaci&oacute;n / Encargado</label>
                            <input type="text" name="encargado" class="form-control form-control-sm" value="{{ old('encargado', $nota->encargado) }}">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">Contacto</label>
                                <input type="text" name="contacto" class="form-control form-control-sm" value="{{ old('contacto', $nota->contacto) }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Correo</label>
                                <input type="text" name="contactocorreo" class="form-control form-control-sm" value="{{ old('contactocorreo', $nota->contactocorreo) }}">
                            </div>
                        </div>
                        <div class="row g-2 mt-0">
                            <div class="col-6">
                                <label class="form-label">Celular</label>
                                <input type="text" name="celular" class="form-control form-control-sm" value="{{ old('celular', $nota->celular) }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label">RUT empresa</label>
                                <input type="text" name="rutempresa" class="form-control form-control-sm" value="{{ old('rutempresa', $nota->rutempresa) }}">
                            </div>
                        </div>
                        <div class="row g-2 mt-0">
                            <div class="col-4">
                                <label class="form-label">D&iacute;as h&aacute;biles</label>
                                <input type="number" name="diashabiles" class="form-control form-control-sm" value="{{ old('diashabiles', $nota->diashabiles ?? 2) }}" min="0">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Factor venta</label>
                                <input type="text" name="factor_precio_venta" class="form-control form-control-sm" value="{{ old('factor_precio_venta', $nota->factor_precio_venta ?? config('cotiz.factor_precio_venta')) }}">
                            </div>
                            <div class="col-4">
                                <label class="form-label">O. compra</label>
                                <input type="text" name="ocompra" class="form-control form-control-sm" value="{{ old('ocompra', $nota->ocompra) }}">
                            </div>
                        </div>
                        <div class="mb-3 mt-2">
                            <label class="form-label">Fecha entrega</label>
                            <input type="date" name="fechaentrega" class="form-control form-control-sm" value="{{ old('fechaentrega', $nota->fechaentrega?->format('Y-m-d')) }}">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">Guardar cabecera</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">Agregar producto</div>
                <div class="card-body">
                    <div class="mb-2">
                        <input type="text" id="buscar-producto" class="form-control form-control-sm" placeholder="Buscar por c&oacute;digo o nombre..." autocomplete="off">
                        <div id="resultados-producto" class="list-group mt-1 position-absolute z-3 shadow-sm" style="max-height:220px;overflow:auto;display:none;min-width:280px;"></div>
                    </div>
                    <form method="post" action="{{ route('admin.cotizaciones.lineas.store', $nota->nronota) }}" class="row g-2 align-items-end" id="form-linea">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label">C&oacute;digo</label>
                            <input type="text" name="prod_item" id="prod_item" class="form-control form-control-sm" required readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cantidad</label>
                            <input type="number" name="cantidad" class="form-control form-control-sm" value="1" min="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Precio venta</label>
                            <input type="number" name="prod_valor" id="prod_valor" class="form-control form-control-sm" min="0" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success btn-sm w-100">Agregar</button>
                        </div>
                        <input type="hidden" name="prod_valor_costo" id="prod_valor_costo">
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Detalle</span>
                    <span class="badge text-bg-primary">Total: ${{ number_format($total, 0, ',', '.') }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>C&oacute;digo</th>
                                <th>Producto</th>
                                <th class="text-end">Cant.</th>
                                <th class="text-end">Precio</th>
                                <th class="text-end">Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($lineas as $row)
                                @php $linea = $row['linea']; @endphp
                                <tr @class(['table-warning' => $row['repetidos'] > 1])>
                                    <td>{{ $linea->orden }}</td>
                                    <td>{{ $linea->prod_item }}</td>
                                    <td>{{ $row['prod_nombre'] }}</td>
                                    <td class="text-end">{{ $linea->cantidad }}</td>
                                    <td class="text-end">${{ number_format($linea->prod_valor, 0, ',', '.') }}</td>
                                    <td class="text-end">${{ number_format($row['total'], 0, ',', '.') }}</td>
                                    <td class="text-end">
                                        <form method="post" action="{{ route('admin.cotizaciones.lineas.destroy', $nota->nronota) }}" onsubmit="return confirm('Eliminar l\u00ednea?');">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="prod_item" value="{{ $linea->prod_item }}">
                                            <input type="hidden" name="orden" value="{{ $linea->orden }}">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1">&times;</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-muted text-center py-3">Sin l&iacute;neas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const input = document.getElementById('buscar-producto');
    const box = document.getElementById('resultados-producto');
    const url = @json(route('admin.productos.buscar'));
    let timer = null;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { box.style.display = 'none'; return; }
        timer = setTimeout(async () => {
            const res = await fetch(url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            box.innerHTML = '';
            (json.data || []).forEach(p => {
                const a = document.createElement('button');
                a.type = 'button';
                a.className = 'list-group-item list-group-item-action py-1 small text-start';
                a.textContent = p.prod_item + ' — ' + (p.prod_nombre || '');
                a.addEventListener('click', () => {
                    document.getElementById('prod_item').value = p.prod_item;
                    document.getElementById('prod_valor').value = p.prod_valor || 0;
                    document.getElementById('prod_valor_costo').value = p.prod_valor_costo || 0;
                    input.value = p.prod_nombre || p.prod_item;
                    box.style.display = 'none';
                });
                box.appendChild(a);
            });
            box.style.display = json.data?.length ? 'block' : 'none';
        }, 250);
    });

    document.addEventListener('click', e => {
        if (!box.contains(e.target) && e.target !== input) box.style.display = 'none';
    });
})();
</script>
@endpush
