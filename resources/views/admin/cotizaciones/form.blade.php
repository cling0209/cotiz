@extends('layouts.admin')

@section('title', 'Cotización '.$nota->nronota)

@push('head')
<link href="{{ asset('css/cotizacion-form.css') }}?v=rep" rel="stylesheet">
@endpush

@section('content')
@php
    $factorValor = (float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta'));
    $factorMostrado = number_format($factorValor, 2, ',', '');
    $factorInput = old('factor_precio_venta', $factorMostrado);
@endphp

<div class="cotizacion-ingreso">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h1 class="h5 mb-0">Ingreso Cotizaci&oacute;n #{{ $nota->nronota }}</h1>
        <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Listado</a>
    </div>

    @if($requiereNumeroCotizacion)
        <div class="alert alert-danger py-2 mb-2" role="alert">
            Debe ingresar el <strong>n&uacute;mero de cotizaci&oacute;n</strong> y pulsar <strong>Guardar n&uacute;mero</strong> antes de agregar productos u otras acciones.
        </div>
    @endif

    @if($hayPrecioAntiguo)
        <div class="alert alert-warning py-2 alert-precio-antiguo mb-2">
            Hay productos con precio de actualizaci&oacute;n anterior a {{ $umbralPrecioMeses }} mes(es) (fechas en rojo).
        </div>
    @endif

    <form method="post" action="{{ route('admin.cotizaciones.update', $nota->nronota) }}" id="form-cotizacion">
        @csrf
        @method('PUT')

        <fieldset class="cotiz-cabecera">
            <table id="tabla_datos1">
                <tr>
                    <th>Cliente</th>
                    <td><input type="text" name="empresa" id="empresa" maxlength="100" value="{{ old('empresa', $nota->empresa) }}"></td>
                    <th>
                        Cotizaci&oacute;n
                        @if($requiereNumeroCotizacion)
                            <span class="text-danger"> *</span>
                        @endif
                    </th>
                    <td>
                        <input
                            type="text"
                            name="encargado"
                            id="encargado"
                            maxlength="100"
                            value="{{ old('encargado', $nota->encargado) }}"
                            required
                            @class([
                                'cotiz-campo-numero-cotiz' => $requiereNumeroCotizacion || $errors->has('encargado'),
                                'is-invalid' => $errors->has('encargado'),
                            ])
                            @if($requiereNumeroCotizacion) autofocus @endif
                            placeholder="{{ $requiereNumeroCotizacion ? 'Nº cotización obligatorio' : '' }}"
                        >
                        @error('encargado')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </td>
                    <th>Celular</th>
                    <td><input type="text" name="celular" id="celular" maxlength="15" value="{{ old('celular', $nota->celular) }}"></td>
                </tr>
                <tr>
                    <th>Contacto</th>
                    <td><input type="text" name="contacto" id="contacto" maxlength="100" value="{{ old('contacto', $nota->contacto) }}"></td>
                    <th>E-mail</th>
                    <td><input type="text" name="contactocorreo" id="contactocorreo" maxlength="60" value="{{ old('contactocorreo', $nota->contactocorreo) }}"></td>
                    <th>Rut Empresa</th>
                    <td><input type="text" name="rutempresa" id="rutempresa" maxlength="10" value="{{ old('rutempresa', $nota->rutempresa) }}"></td>
                </tr>
                <tr>
                    <th>Monto Total</th>
                    <td><input type="text" name="montototal" id="montototal" readonly value="${{ number_format($total, 0, ',', '.') }}"></td>
                    <th>D&iacute;as H&aacute;biles</th>
                    <td><input type="number" name="diashabiles" id="diashabiles" min="0" maxlength="2" value="{{ old('diashabiles', $nota->diashabiles ?? 2) }}"></td>
                    <th>O.Compra</th>
                    <td><input type="text" name="ocompra" id="ocompra" maxlength="20" value="{{ old('ocompra', $nota->ocompra) }}"></td>
                </tr>
                <tr>
                    <th>Entrega</th>
                    <td><input type="date" name="fechaentrega" id="fechaentrega" value="{{ old('fechaentrega', $nota->fechaentrega?->format('Y-m-d')) }}"></td>
                    <th>Descripci&oacute;n</th>
                    <td colspan="3"><input type="text" name="descripcion" id="descripcion" maxlength="500" value="{{ old('descripcion', $nota->descripcion) }}" required></td>
                </tr>
            </table>

            @if($requiereNumeroCotizacion)
                <div class="cotiz-guardar-numero mt-2">
                    <button type="submit" name="accion" value="grabar" class="btn btn-primary btn-sm">Guardar n&uacute;mero</button>
                </div>
            @endif
        </fieldset>

        <div @class(['cotiz-contenido-detalle', 'cotiz-contenido-bloqueado' => $requiereNumeroCotizacion])>
            <div id="notaventa-bloque-factor" class="cotiz-cabecera-factor mb-2">
                <span class="d-inline-flex flex-wrap align-items-center column-gap-3 row-gap-2">
                    <span class="text-nowrap"><strong>&Uacute;ltimo factor guardado:</strong> <span id="factor_precio_venta_mostrado">{{ $factorMostrado }}</span></span>
                    <label for="factor_precio_venta" class="mb-0 text-nowrap"><strong>Factor Aumento Precio Venta:</strong></label>
                    <input type="text" name="factor_precio_venta" id="factor_precio_venta" size="7" maxlength="7" inputmode="decimal" autocomplete="off" title="Hasta 2 decimales (ej.: 1,30)" value="{{ $factorInput }}" @class(['is-invalid' => $errors->has('factor_precio_venta')])>
                    @error('factor_precio_venta')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                    <button type="submit" name="accion" value="aplicar_factor" class="btn btn-outline-primary btn-sm" id="btnFactorAumentoAceptar">Aplicar Nuevo Factor</button>
                </span>
            </div>

        <div class="cotiz-agregar mb-2">
            <button type="button" class="btn btn-success btn-sm" id="btn-abrir-buscar-producto">
                <i class="bi bi-plus-circle"></i> Agregar producto
            </button>
        </div>

        <div id="notaventa-tabla-detalle-wrap" data-max-orden="{{ $lineas->count() }}">
            <table id="tabla_detalle" class="table table-sm">
                <thead>
                    <tr>
                        <th class="linea-drag-col" title="Arrastrar para reordenar"></th>
                        <th>Imagen</th>
                        <th>C&oacute;digo</th>
                        <th>Cod. Softland</th>
                        <th>ID Agile</th>
                        <th>Descripci&oacute;n del producto</th>
                        <th>Fecha<br>act.&nbsp;precio</th>
                        <th>Precio Costo</th>
                        <th>Precio Unitario</th>
                        <th>Cantidad</th>
                        <th>Total</th>
                        <th>Orden</th>
                        <th>Eliminar</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lineas as $idx => $row)
                        @php $linea = $row['linea']; @endphp
                        <tr
                            @class(['linea-repetida' => $row['repetidos'] > 1])
                            data-linea="{{ $idx }}"
                            data-prod="{{ $linea->prod_item }}"
                            data-orden="{{ $linea->orden }}"
                        >
                            <td class="text-center linea-drag-cell">
                                <span class="linea-drag-handle" title="Arrastrar para reordenar" aria-label="Arrastrar fila">
                                    <svg class="linea-drag-grip" width="14" height="20" viewBox="0 0 14 20" aria-hidden="true" focusable="false">
                                        <circle cx="4" cy="4" r="2"/>
                                        <circle cx="10" cy="4" r="2"/>
                                        <circle cx="4" cy="10" r="2"/>
                                        <circle cx="10" cy="10" r="2"/>
                                        <circle cx="4" cy="16" r="2"/>
                                        <circle cx="10" cy="16" r="2"/>
                                    </svg>
                                </span>
                            </td>
                            <td class="linea-imagen-cell" onclick="event.stopPropagation();">
                                <x-product-image
                                    :maeprod="$linea->producto"
                                    :alt="$row['prod_nombre']"
                                    variant="admin-thumb"
                                    wrapperClass="imagen"
                                />
                            </td>
                            <td>
                                {{ $linea->prod_item }}
                                <input type="hidden" name="lineas[{{ $idx }}][prod_item]" value="{{ $linea->prod_item }}">
                                <input type="hidden" name="lineas[{{ $idx }}][orden]" value="{{ $linea->orden }}">
                            </td>
                            <td>
                                <input type="text" name="lineas[{{ $idx }}][prod_item_softland]" maxlength="20" value="{{ old('lineas.'.$idx.'.prod_item_softland', $row['prod_item_softland']) }}" title="C&oacute;digo Softland">
                            </td>
                            <td><span class="nv-fill">{{ $row['prod_item_agile'] }}</span></td>
                            <td><span class="nv-fill">{{ $row['prod_nombre'] }}</span></td>
                            <td>
                                <span @class(['nv-fill', 'fecha-precio-antigua' => $row['prod_valor_fecha_antigua']])>{{ $row['prod_valor_fecha'] }}</span>
                            </td>
                            <td>
                                <input type="number" name="lineas[{{ $idx }}][prod_valor_costo]" class="nv-precio-costo-sololectura" value="{{ old('lineas.'.$idx.'.prod_valor_costo', $linea->prod_valor_costo) }}" readonly tabindex="-1" title="Precio costo (solo lectura)">
                            </td>
                            <td>
                                <input type="number" name="lineas[{{ $idx }}][prod_valor]" class="linea-prod-valor" min="0" value="{{ old('lineas.'.$idx.'.prod_valor', $linea->prod_valor) }}" title="Precio unitario">
                            </td>
                            <td>
                                <input type="number" name="lineas[{{ $idx }}][cantidad]" class="linea-cantidad" min="1" value="{{ old('lineas.'.$idx.'.cantidad', $linea->cantidad) }}">
                            </td>
                            <td class="linea-total text-end">${{ number_format($row['total'], 0, ',', '.') }}</td>
                            <td class="text-center linea-orden-cell">
                                <div class="linea-orden-controls">
                                    <span class="linea-orden-num">{{ $linea->orden }}</span>
                                    <div class="linea-orden-buttons">
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm linea-orden-subir"
                                            data-prod="{{ $linea->prod_item }}"
                                            data-orden="{{ $linea->orden }}"
                                            title="Subir"
                                            @disabled($loop->first)
                                        ><i class="bi bi-chevron-up"></i></button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm linea-orden-bajar"
                                            data-prod="{{ $linea->prod_item }}"
                                            data-orden="{{ $linea->orden }}"
                                            title="Bajar"
                                            @disabled($loop->last)
                                        ><i class="bi bi-chevron-down"></i></button>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center eliminar-cell" data-prod="{{ $linea->prod_item }}" data-orden="{{ $linea->orden }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="13" class="text-muted text-center py-3">Sin l&iacute;neas. Use &laquo;Agregar producto&raquo; para incorporar items.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($lineas->isNotEmpty())
        <div id="panelMercadoPublico" class="cotiz-panel-mp">
            <p class="cotiz-panel-mp__texto mb-2">
                <strong>Mercado P&uacute;blico</strong> &mdash; <em>valor unitario</em> por l&iacute;nea (usa <strong>ID Agile</strong> del maestro; si falta, el prefijo num&eacute;rico del c&oacute;digo interno). El valor despacho en la copia es siempre <strong>0</strong> (sin despacho).
            </p>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" id="btnCopiarMP" class="btn btn-outline-secondary btn-sm">Copiar para MP (portapapeles)</button>
                <span id="mpCopiaMsg" class="cotiz-panel-mp__msg" hidden></span>
            </div>
        </div>
        @endif

        <fieldset class="cotiz-botones">
            @unless($requiereNumeroCotizacion)
                <button type="submit" name="accion" value="grabar" class="btn btn-primary btn-sm">Grabar</button>
            @endunless
            <input type="hidden" name="nronota" id="nronota" value="{{ $nota->nronota }}">
            @if($lineas->isNotEmpty())
                <a href="{{ route('admin.cotizaciones.export.pdf', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar PDF</a>
                <a href="{{ route('admin.cotizaciones.export.archivo', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Archivo</a>
                <a href="{{ route('admin.cotizaciones.export.excel', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Excel</a>
                <a href="{{ route('admin.cotizaciones.export.guia', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Gu&iacute;a</a>
                <a href="{{ route('admin.cotizaciones.export.guia-ingreso', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Gu&iacute;a Ingreso</a>
            @endif
        </fieldset>
        </div>
    </form>

    @foreach($lineas as $idx => $row)
        @php $linea = $row['linea']; @endphp
        <form method="post" action="{{ route('admin.cotizaciones.lineas.destroy', $nota->nronota) }}" class="d-none form-eliminar-linea" data-prod="{{ $linea->prod_item }}" data-orden="{{ $linea->orden }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="prod_item" value="{{ $linea->prod_item }}">
            <input type="hidden" name="orden" value="{{ $linea->orden }}">
        </form>
    @endforeach

    <div class="modal fade" id="modal-buscar-producto" tabindex="-1" aria-labelledby="modal-buscar-producto-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title fs-6" id="modal-buscar-producto-label">Buscar producto</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body py-2">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <div class="input-group input-group-sm flex-grow-1">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input
                                type="text"
                                id="modal-buscar-input"
                                class="form-control"
                                placeholder="C&oacute;digo o descripci&oacute;n del producto..."
                                autocomplete="off"
                            >
                            <button type="button" class="btn btn-primary" id="btn-modal-buscar">
                                Buscar
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <label for="modal-cantidad" class="small text-nowrap mb-0">Cantidad</label>
                            <input type="number" id="modal-cantidad" class="form-control form-control-sm" value="1" min="1" style="width:4.5rem">
                        </div>
                    </div>
                    <p id="modal-buscar-estado" class="small text-muted mb-2">Escriba c&oacute;digo o descripci&oacute;n y pulse Buscar.</p>
                    <div class="table-responsive cotiz-buscar-tabla-wrap">
                        <table class="table table-sm table-hover mb-0 cotiz-buscar-tabla" id="tabla-buscar-productos">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:56px"></th>
                                    <th style="width:90px">C&oacute;digo</th>
                                    <th>Descripci&oacute;n</th>
                                    <th style="width:80px">Familia</th>
                                    <th class="text-end" style="width:90px">Precio</th>
                                </tr>
                            </thead>
                            <tbody id="modal-buscar-resultados">
                                <tr><td colspan="5" class="text-muted text-center py-3">Sin resultados.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <span class="small text-muted me-auto">Clic en fila para agregar al detalle.</span>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script src="{{ asset('js/product-image.js') }}" defer></script>
<script>
(function () {
    const requiereNumeroCotizacion = @json($requiereNumeroCotizacion);

    function asegurarNumeroCotizacion() {
        if (!requiereNumeroCotizacion) {
            return true;
        }
        const enc = document.getElementById('encargado');
        const valor = String(enc?.value || '').trim();
        if (!valor) {
            alert('Debe ingresar el número de cotización y guardarlo antes de continuar.');
            enc?.focus();
            return false;
        }
        alert('Guarde el número de cotización con el botón «Guardar número» antes de continuar.');
        enc?.focus();
        return false;
    }

    const fmt = n => '$' + Math.round(n).toLocaleString('es-CL');
    const montototal = document.getElementById('montototal');
    const factorInput = document.getElementById('factor_precio_venta');

    function parseFactorChile(texto) {
        const t = String(texto || '').trim().replace(/\s/g, '');
        if (!t) return null;
        const norm = t.indexOf(',') >= 0 ? t.replace(/\./g, '').replace(',', '.') : t;
        if (!/^\d+(?:\.\d{1,2})?$/.test(norm)) return null;
        const f = parseFloat(norm);
        return Number.isFinite(f) && f > 0 ? f : null;
    }

    function formatFactorChile(f) {
        return f.toFixed(2).replace('.', ',');
    }

    factorInput?.addEventListener('blur', function () {
        const parsed = parseFactorChile(this.value);
        if (parsed === null) {
            if (String(this.value || '').trim() !== '') {
                this.classList.add('is-invalid');
            }
            return;
        }
        this.classList.remove('is-invalid');
        this.value = formatFactorChile(parsed);
    });

    document.getElementById('form-cotizacion')?.addEventListener('submit', function (e) {
        const submitter = e.submitter;
        if (!submitter || submitter.name !== 'accion') return;
        if (submitter.value !== 'aplicar_factor' && submitter.value !== 'grabar') return;
        if (!factorInput || String(factorInput.value || '').trim() === '') return;
        const parsed = parseFactorChile(factorInput.value);
        if (parsed === null) {
            e.preventDefault();
            factorInput.classList.add('is-invalid');
            factorInput.focus();
            alert('El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).');
            return;
        }
        factorInput.value = formatFactorChile(parsed);
    });

    function marcarLineasRepetidas() {
        const rows = document.querySelectorAll('#tabla_detalle tbody tr[data-prod]');
        const counts = {};
        rows.forEach(tr => {
            const prod = String(tr.dataset.prod || '').trim();
            if (!prod) return;
            counts[prod] = (counts[prod] || 0) + 1;
        });
        rows.forEach(tr => {
            const prod = String(tr.dataset.prod || '').trim();
            tr.classList.toggle('linea-repetida', prod && counts[prod] > 1);
        });
    }

    function recalcularMontoTotal() {
        let sum = 0;
        document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(tr => {
            const valor = parseInt(tr.querySelector('.linea-prod-valor')?.value || '0', 10);
            const cant = parseInt(tr.querySelector('.linea-cantidad')?.value || '0', 10);
            const total = valor * cant;
            const td = tr.querySelector('.linea-total');
            if (td) td.textContent = fmt(total);
            sum += total;
        });
        if (montototal) montototal.value = fmt(sum);
    }

    marcarLineasRepetidas();

    document.getElementById('tabla_detalle')?.addEventListener('input', e => {
        if (e.target.matches('.linea-prod-valor, .linea-cantidad')) {
            recalcularMontoTotal();
        }
    });

    document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(tr => {
        const elimTd = tr.querySelector('.eliminar-cell');
        if (!elimTd) return;
        const prod = elimTd.dataset.prod;
        const orden = elimTd.dataset.orden;
        const delForm = document.querySelector('.form-eliminar-linea[data-prod="' + prod + '"][data-orden="' + orden + '"]');
        if (delForm) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-danger btn-sm py-0 px-2';
            btn.textContent = 'Eliminar';
            btn.addEventListener('click', () => {
                if (confirm('¿Eliminar línea?')) delForm.submit();
            });
            elimTd.appendChild(btn);
        }
    });

    const ordenUrl = @json(route('admin.cotizaciones.lineas.orden', $nota->nronota));
    const csrfOrden = document.querySelector('meta[name="csrf-token"]')?.content;
    let ordenEnProceso = false;

    function mostrarLoaderCotiz() {
        try {
            sessionStorage.setItem('page-loader-pending', '1');
        } catch (e) {}
        if (window.PageLoader?.show) {
            window.PageLoader.show();
        }
    }

    function ocultarLoaderCotiz() {
        if (window.PageLoader?.hide) {
            window.PageLoader.hide();
        }
    }

    async function cambiarOrdenLinea(prodItem, orden, payload) {
        if (ordenEnProceso) return false;
        ordenEnProceso = true;
        mostrarLoaderCotiz();
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        try {
            const res = await fetch(ordenUrl, {
                method: 'PATCH',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfOrden,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    prod_item: prodItem,
                    orden: orden,
                    ...payload,
                }),
            });

            const json = await res.json().catch(() => ({}));

            if (res.ok && json.ok) {
                window.location.reload();
                return true;
            }

            ocultarLoaderCotiz();
            alert(json.error || json.message || 'No se pudo cambiar el orden.');
            return false;
        } catch (err) {
            ocultarLoaderCotiz();
            alert('Error de conexión al cambiar el orden.');
            return false;
        } finally {
            ordenEnProceso = false;
        }
    }

    async function moverLineaOrden(btn, direccion) {
        if (!btn || btn.disabled || ordenEnProceso) return;

        btn.closest('.linea-orden-buttons')?.querySelectorAll('button').forEach(b => { b.disabled = true; });

        const ok = await cambiarOrdenLinea(btn.dataset.prod, parseInt(btn.dataset.orden, 10), {
            direccion: direccion,
        });

        if (!ok) {
            btn.closest('.linea-orden-buttons')?.querySelectorAll('button').forEach(b => { b.disabled = false; });
        }
    }

    document.querySelectorAll('.linea-orden-subir').forEach(btn => {
        btn.addEventListener('click', () => moverLineaOrden(btn, 'up'));
    });

    document.querySelectorAll('.linea-orden-bajar').forEach(btn => {
        btn.addEventListener('click', () => moverLineaOrden(btn, 'down'));
    });

    const detalleTbody = document.querySelector('#tabla_detalle tbody');
    if (detalleTbody && detalleTbody.querySelector('tr[data-linea]') && typeof Sortable !== 'undefined') {
        Sortable.create(detalleTbody, {
            animation: 160,
            handle: '.linea-drag-handle',
            draggable: 'tr[data-linea]',
            ghostClass: 'linea-sortable-ghost',
            chosenClass: 'linea-sortable-chosen',
            dragClass: 'linea-sortable-drag',
            onEnd: async function (evt) {
                if (evt.oldIndex === evt.newIndex || evt.oldIndex == null || evt.newIndex == null) {
                    return;
                }

                const row = evt.item;
                const prodItem = row.dataset.prod;
                const orden = parseInt(row.dataset.orden, 10);
                const ordenNuevo = evt.newIndex + 1;

                const ok = await cambiarOrdenLinea(prodItem, orden, { orden_nuevo: ordenNuevo });
                if (!ok) {
                    mostrarLoaderCotiz();
                    window.location.reload();
                }
            },
        });
    }

    const lineasUrl = @json(route('admin.cotizaciones.lineas.store', $nota->nronota));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let agregandoLinea = false;

    async function agregarProducto(p) {
        if (!p?.prod_item || agregandoLinea) {
            return false;
        }

        const cantidad = document.getElementById('modal-cantidad')?.value || '1';
        const prodValor = p.prod_valor ?? 0;
        const prodValorCosto = p.prod_valor_costo ?? '';

        agregandoLinea = true;

        try {
            const body = new FormData();
            body.append('_token', csrf);
            body.append('prod_item', p.prod_item);
            body.append('cantidad', cantidad);
            body.append('prod_valor', prodValor);
            if (prodValorCosto !== '' && prodValorCosto != null) {
                body.append('prod_valor_costo', prodValorCosto);
            }

            const res = await fetch(lineasUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });

            if (res.ok) {
                mostrarLoaderCotiz();
                window.location.reload();
                return true;
            }

            const json = await res.json().catch(() => ({}));
            ocultarLoaderCotiz();
            try {
                sessionStorage.removeItem('page-loader-pending');
            } catch (e) {}
            alert(json.error || json.message || 'No se pudo agregar la línea.');
            return false;
        } catch (err) {
            ocultarLoaderCotiz();
            try {
                sessionStorage.removeItem('page-loader-pending');
            } catch (e) {}
            alert('Error de conexión al agregar producto.');
            return false;
        } finally {
            agregandoLinea = false;
        }
    }

    const buscarConfig = {
        url: @json(route('admin.productos.buscar')),
        minChars: @json((int) config('cotiz.buscar_productos_min_chars', 2)),
        limit: @json((int) config('cotiz.buscar_productos_limite', 15)),
        placeholderImg: @json(asset('images/no-image.svg')),
    };

    const modalEl = document.getElementById('modal-buscar-producto');
    const modalInput = document.getElementById('modal-buscar-input');
    const modalEstado = document.getElementById('modal-buscar-estado');
    const modalBody = document.getElementById('modal-buscar-resultados');
    const btnAbrirBuscar = document.getElementById('btn-abrir-buscar-producto');
    const btnModalBuscar = document.getElementById('btn-modal-buscar');
    const bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;
    let buscarAbort = null;
    let resultadosActuales = [];
    let filaActiva = -1;

    function fmtPrecio(n) {
        return '$' + Math.round(Number(n) || 0).toLocaleString('es-CL');
    }

    async function seleccionarProducto(p) {
        if (agregandoLinea) {
            return;
        }

        mostrarLoaderCotiz();
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        if (modalEstado) {
            modalEstado.textContent = 'Agregando producto...';
        }
        bsModal?.hide();
        agregarProducto(p);
    }

    function marcarFilaActiva(idx) {
        filaActiva = idx;
        modalBody?.querySelectorAll('tr[data-idx]').forEach(tr => {
            tr.classList.toggle('table-active', parseInt(tr.dataset.idx, 10) === idx);
        });
    }

    function renderResultados(items, meta) {
        resultadosActuales = items || [];
        filaActiva = resultadosActuales.length ? 0 : -1;

        if (!modalBody) return;

        if (!resultadosActuales.length) {
            modalBody.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">Sin resultados.</td></tr>';
            if (modalEstado) {
                modalEstado.textContent = meta?.q
                    ? 'No se encontraron productos para «' + meta.q + '».'
                    : 'Escriba código o descripción y pulse Buscar.';
            }
            return;
        }

        modalBody.innerHTML = '';
        resultadosActuales.forEach((p, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.idx = String(idx);
            tr.className = 'cotiz-buscar-fila';
            tr.tabIndex = 0;
            tr.innerHTML =
                '<td class="text-center p-1">' +
                    (p.image_url
                        ? '<img src="' + p.image_url + '" alt="" class="cotiz-buscar-thumb" onerror="this.src=\'' + buscarConfig.placeholderImg + '\'">'
                        : '<img src="' + buscarConfig.placeholderImg + '" alt="" class="cotiz-buscar-thumb">') +
                '</td>' +
                '<td class="align-middle"><code class="small">' + (p.prod_item || '') + '</code></td>' +
                '<td class="align-middle small">' + (p.prod_nombre || '') + '</td>' +
                '<td class="align-middle small text-muted">' + (p.prod_familia || '') + '</td>' +
                '<td class="align-middle text-end fw-semibold">' + fmtPrecio(p.prod_valor) + '</td>';

            tr.addEventListener('click', () => seleccionarProducto(p));
            tr.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    seleccionarProducto(p);
                }
            });
            modalBody.appendChild(tr);
        });

        if (modalEstado && meta) {
            modalEstado.textContent = meta.count + ' producto(s) — ordenados por similitud y precio (más barato primero).';
        }

        marcarFilaActiva(0);
    }

    async function ejecutarBusqueda(q) {
        if (buscarAbort) buscarAbort.abort();
        buscarAbort = new AbortController();

        if (q.length < buscarConfig.minChars) {
            renderResultados([], { q });
            if (modalEstado) {
                modalEstado.textContent = 'Escriba al menos ' + buscarConfig.minChars + ' caracteres para buscar.';
            }
            return;
        }

        if (modalEstado) modalEstado.textContent = 'Buscando...';

        try {
            const params = new URLSearchParams({
                q,
                limit: String(buscarConfig.limit),
            });
            const res = await fetch(buscarConfig.url + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: buscarAbort.signal,
            });
            const json = await res.json();
            renderResultados(json.data || [], json.meta || { q, count: (json.data || []).length });
        } catch (err) {
            if (err.name === 'AbortError') return;
            if (modalEstado) modalEstado.textContent = 'Error al buscar. Intente de nuevo.';
        }
    }

    function abrirModalBuscar() {
        if (!asegurarNumeroCotizacion()) return;
        if (!bsModal || !modalInput) return;
        modalInput.value = '';
        renderResultados([], {});
        if (modalEstado) {
            modalEstado.textContent = 'Escriba código o descripción y pulse Buscar.';
        }
        bsModal.show();
        setTimeout(() => {
            modalInput.focus();
        }, 200);
    }

    function lanzarBusquedaModal() {
        if (!modalInput) return;
        ejecutarBusqueda(modalInput.value.trim());
    }

    btnAbrirBuscar?.addEventListener('click', () => abrirModalBuscar());
    btnModalBuscar?.addEventListener('click', () => lanzarBusquedaModal());

    modalInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            lanzarBusquedaModal();
            return;
        }

        if (!resultadosActuales.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            marcarFilaActiva(Math.min(filaActiva + 1, resultadosActuales.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            marcarFilaActiva(Math.max(filaActiva - 1, 0));
        }
    });

    modalEl?.addEventListener('hidden.bs.modal', () => {
        if (buscarAbort) buscarAbort.abort();
    });

    function idAgileParaMercadoPublico(codigoInterno) {
        const s = String(codigoInterno || '').trim().replace(/\s/g, '');
        if (!s) return '';
        const m = s.match(/^(\d+)/);
        return (m && m[1]) ? m[1] : s;
    }

    function recolectarItemsMercadoPublico() {
        const items = [];
        document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(tr => {
            const codigo = String(tr.dataset.prod || '').trim();
            const idAgileCell = String(tr.querySelector('td:nth-child(5) .nv-fill')?.textContent || '').trim().replace(/\s/g, '');
            const idMp = idAgileCell || idAgileParaMercadoPublico(codigo) || codigo;
            const precioNum = parseInt(tr.querySelector('.linea-prod-valor')?.value || '0', 10) || 0;
            if (codigo) {
                items.push({ idAgile: idMp, codigoInterno: codigo, valorUnitario: precioNum });
            }
        });
        return items;
    }

    function copiarTextoPortapapeles(texto) {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
            return navigator.clipboard.writeText(texto);
        }
        return new Promise((resolve, reject) => {
            const ta = document.createElement('textarea');
            ta.value = texto;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve(true) : reject(new Error('No se pudo copiar'));
            } catch (e) {
                document.body.removeChild(ta);
                reject(e);
            }
        });
    }

    document.getElementById('btnCopiarMP')?.addEventListener('click', () => {
        const items = recolectarItemsMercadoPublico();
        if (items.length === 0) {
            alert('No hay filas válidas en la tabla de productos para copiar.');
            return;
        }
        const conPrecioCero = items.filter(it => it.valorUnitario <= 0);
        if (conPrecioCero.length > 0 && !window.confirm('Hay ítems con precio unitario 0 o vacío. ¿Desea copiar igualmente para completar después en Mercado Público?')) {
            return;
        }
        const payload = {
            fuente: 'cotiz',
            nronota: String(document.getElementById('nronota')?.value || '').trim(),
            cotizacion: String(document.getElementById('encargado')?.value || '').trim(),
            despacho: 0,
            items,
        };
        const jsonStr = JSON.stringify(payload, null, 2);
        const tsvLines = ['id_agile\tvalor_unitario', ...items.map(it => it.idAgile + '\t' + it.valorUnitario)];
        const bloque = '--- JSON ---\n' + jsonStr + '\n\n--- TSV ---\n' + tsvLines.join('\n');
        copiarTextoPortapapeles(bloque).then(() => {
            const msg = document.getElementById('mpCopiaMsg');
            if (msg) {
                msg.textContent = 'Copiado: ' + items.length + ' unitario(s).';
                msg.hidden = false;
                setTimeout(() => { msg.hidden = true; }, 5000);
            } else {
                alert('Copiado al portapapeles (' + items.length + ' ítems).');
            }
        }).catch(err => {
            alert('No se pudo copiar (use HTTPS o localhost).\n' + (err?.message || ''));
        });
    });

    @if($lineas->contains(fn ($row) => $row['repetidos'] > 1))
        alert('Existen productos que se repiten, estos están marcados con rojo, favor revisar si corresponde');
    @endif
})();
</script>
@endpush
