{{-- «Cotizar con IA»: solo usuarios de CotizarIaService::USUARIOS_PERMITIDOS. --}}
<style>
    #cotizar-ia-lineas tr.cotizar-ia-fila-prorrateo > td {
        background-color: #fff4e6;
    }
</style>
<div class="modal fade" id="modal-cotizar-ia" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="modal-cotizar-ia-label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6 d-flex flex-wrap align-items-center gap-2" id="modal-cotizar-ia-label">
                    <span><i class="bi bi-stars"></i> Cotizar con IA</span>
                    <span class="badge text-bg-secondary font-monospace fw-normal" id="cotizar-ia-codigo" hidden></span>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body py-2">
                <div id="cotizar-ia-cargando" class="py-4 px-3 d-none" role="status" aria-live="polite">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="spinner-border spinner-border-sm text-success flex-shrink-0" aria-hidden="true"></div>
                        <strong class="small" id="cotizar-ia-etapa">Iniciando&hellip;</strong>
                        <span class="ms-auto small text-muted font-monospace" id="cotizar-ia-tiempo" title="Tiempo transcurrido">0:00</span>
                    </div>
                    <div class="progress mb-1" style="height: 6px;">
                        <div class="progress-bar bg-success" id="cotizar-ia-barra" style="width: 3%;"></div>
                    </div>
                    <div class="d-flex small text-muted">
                        <span id="cotizar-ia-detalle"></span>
                        <span class="ms-auto" id="cotizar-ia-paso"></span>
                    </div>
                    <p class="small text-muted mt-2 mb-0">Puede tardar varios minutos si la cotizaci&oacute;n tiene muchas l&iacute;neas. No se graba nada hasta que confirme.</p>
                </div>
                <div id="cotizar-ia-error" class="alert alert-danger py-2 small d-none" role="alert"></div>
                <div id="cotizar-ia-creadas" class="alert alert-success py-2 small d-none" role="status"></div>
                <div id="cotizar-ia-resultado" class="d-none">
                    <div class="border rounded p-2 mb-2 small">
                        <strong>Productos tomados de:</strong> <span id="cotizar-ia-fuente" class="badge text-bg-dark"></span>
                        <div id="cotizar-ia-motivo" class="text-muted mt-1"></div>
                        <div id="cotizar-ia-adjuntos" class="text-muted mt-1"></div>
                    </div>
                    <div id="cotizar-ia-uso" class="border rounded p-2 mb-2 small bg-light d-none">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <strong><i class="bi bi-cpu"></i> Consumo de IA</strong>
                            <span>Tokens: <strong class="tabular-nums" id="cotizar-ia-uso-tokens"></strong></span>
                            <span>Costo: <strong class="tabular-nums" id="cotizar-ia-uso-costo"></strong></span>
                            <span class="text-muted" id="cotizar-ia-uso-referencial"></span>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-auto" data-bs-toggle="collapse" data-bs-target="#cotizar-ia-uso-detalle" aria-expanded="false" aria-controls="cotizar-ia-uso-detalle">Ver detalle</button>
                        </div>
                        <div class="collapse mt-2" id="cotizar-ia-uso-detalle">
                            <table class="table table-sm table-bordered mb-1 bg-white">
                                <thead class="table-light">
                                    <tr>
                                        <th>Etapa</th>
                                        <th class="text-end">Llamadas</th>
                                        <th class="text-end">Entrada</th>
                                        <th class="text-end">Salida</th>
                                        <th class="text-end">Pensamiento</th>
                                        <th class="text-end">Costo</th>
                                    </tr>
                                </thead>
                                <tbody id="cotizar-ia-uso-etapas"></tbody>
                            </table>
                            <div class="text-muted" id="cotizar-ia-uso-nota"></div>
                        </div>
                    </div>
                    <div id="cotizar-ia-avisos" class="alert alert-warning py-2 small d-none" role="status"></div>
                    <div id="cotizar-ia-separar" class="alert alert-info py-2 small d-none" role="status">
                        <div class="fw-semibold mb-1"><i class="bi bi-diagram-3"></i> El comprador pide cotizaciones separadas por solicitante</div>
                        <ol class="mb-1 ps-3" id="cotizar-ia-separar-lista"></ol>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cotizar-ia-separar-check" checked>
                            <label class="form-check-label" for="cotizar-ia-separar-check" id="cotizar-ia-separar-label"></label>
                        </div>
                    </div>
                    <p class="small mb-2" id="cotizar-ia-resumen"></p>
                    <div class="d-flex flex-wrap align-items-center gap-2 small mb-2">
                        <label class="fw-semibold mb-0" for="cotizar-ia-factor">Factor de venta</label>
                        <input type="number" class="form-control form-control-sm tabular-nums" id="cotizar-ia-factor" style="width: 6rem;" min="1" max="5" step="0.01">
                        <span class="text-muted" id="cotizar-ia-region"></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle small mb-2">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-end" style="width: 3rem;">#</th>
                                    <th>Solicitado</th>
                                    <th class="text-end" style="width: 5rem;">Cant.</th>
                                    <th>V&iacute;nculo propuesto</th>
                                    <th class="text-end" style="width: 6.5rem;" title="Costo neto por unidad o por pack seg&uacute;n prorrateo">Costo</th>
                                    <th class="text-center" style="width: 5rem;" title="Fila naranja: puede prorratear pack. Marque para cotizar por unidad.">Prorr.</th>
                                    <th class="text-end" style="width: 6.5rem;" title="Precio neto (costo &times; factor)">Precio venta</th>
                                    <th class="text-end" style="width: 7rem;">Total venta</th>
                                    <th class="text-center" style="width: 4rem;" title="Desmarque para dejar la l&iacute;nea pendiente sin v&iacute;nculo">Usar</th>
                                </tr>
                            </thead>
                            <tbody id="cotizar-ia-lineas"></tbody>
                            <tfoot>
                                <tr class="table-light fw-semibold">
                                    <td colspan="7" class="text-end">Total venta neto (l&iacute;neas marcadas)</td>
                                    <td class="text-end tabular-nums" id="cotizar-ia-total"></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="form-check small d-none" id="cotizar-ia-wrap-reemplazar">
                        <input class="form-check-input" type="checkbox" id="cotizar-ia-reemplazar" checked>
                        <label class="form-check-label" for="cotizar-ia-reemplazar" id="cotizar-ia-reemplazar-label"></label>
                    </div>
                    <p class="small text-muted mb-0 mt-1">
                        Las l&iacute;neas con referencia web quedan pendientes (NOK) con el costo neto (precio publicado sin IVA) y la URL en la observaci&oacute;n interna; el precio de venta es costo &times; factor.
                        En los productos del maestro manda el precio (es el de la Metropolitana): el costo es referencial = precio / {{ number_format((float) config('cotiz.factor_precio_venta_rm', 1.22), 2, ',', '.') }}; en la Metropolitana se cobra el precio del maestro y en otras regiones costo &times; factor.
                        Los v&iacute;nculos por IA que agregue se guardan como aprendizaje.
                    </p>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-success btn-sm d-none" id="btn-cotizar-ia-aplicar">
                    <i class="bi bi-check2-circle"></i> <span id="btn-cotizar-ia-aplicar-texto">Agregar a la cotizaci&oacute;n</span>
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const btnAbrir = document.getElementById('btn-cotizar-ia');
    const modalEl = document.getElementById('modal-cotizar-ia');
    if (!btnAbrir || !modalEl || typeof bootstrap === 'undefined') {
        return;
    }

    const urlPreviewTpl = @json(route('admin.cotizaciones.cotizar-ia.preview', 999999999));
    const urlAplicarTpl = @json(route('admin.cotizaciones.cotizar-ia.aplicar', 999999999));
    const urlProgresoTpl = @json(route('admin.cotizaciones.cotizar-ia.progreso', str_repeat('0', 32)));
    const nronotaActual = () => String(parseInt(document.getElementById('nronota')?.value || '0', 10) || 0);
    const urlCon = (tpl) => tpl.replace('999999999', nronotaActual());
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    const el = (id) => document.getElementById(id);
    const cargando = el('cotizar-ia-cargando');
    const errorBox = el('cotizar-ia-error');
    const resultado = el('cotizar-ia-resultado');
    const btnAplicar = el('btn-cotizar-ia-aplicar');
    const tbody = el('cotizar-ia-lineas');
    const numero = new Intl.NumberFormat('es-CL');

    const FUENTES = {
        cotizacion: 'Productos de la cotizaci\u00f3n MP',
        adjunto: 'Adjunto',
        ambos: 'Cotizaci\u00f3n MP + adjunto',
    };
    const ORIGENES = {
        frase_maeprod: ['Frase', 'text-bg-primary'],
        aprendido_exacto: ['Aprendido', 'text-bg-info'],
        ia: ['IA', 'text-bg-success'],
        ia_foto: ['IA \u00b7 foto', 'text-bg-success'],
    };

    const REGION_METROPOLITANA = @json(\App\Services\CompraAgilRegionScope::REGION_METROPOLITANA);
    const FACTOR_RM_TEXTO = {{ \Illuminate\Support\Js::from(number_format((float) config('cotiz.factor_precio_venta_rm', 1.22), 2, ',', '.')) }};
    const FACTOR_RM = {{ \Illuminate\Support\Js::from(round((float) config('cotiz.factor_precio_venta_rm', 1.22), 2)) }};

    let token = null;
    let factorInicial = null;
    let grupos = [];
    let destinoAlCerrar = null;
    let enCurso = false;
    let relojId = null;
    let sondeoId = null;
    let inicio = 0;

    function formatoTiempo(ms) {
        const seg = Math.max(0, Math.floor(ms / 1000));
        return Math.floor(seg / 60) + ':' + String(seg % 60).padStart(2, '0');
    }

    function nuevoProgresoId() {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    }

    function mostrarCodigoCotizacion(codigo) {
        const badge = el('cotizar-ia-codigo');
        if (!badge) {
            return;
        }
        const cod = String(codigo || '').trim().toUpperCase();
        if (cod === '') {
            badge.textContent = '';
            badge.hidden = true;
            return;
        }
        badge.textContent = cod;
        badge.hidden = false;
    }

    function pintarProgreso(p) {
        if (!p) {
            return;
        }
        el('cotizar-ia-etapa').textContent = p.etapa || 'Procesando\u2026';
        el('cotizar-ia-detalle').textContent = p.detalle || '';
        const total = p.total || 1;
        el('cotizar-ia-paso').textContent = 'Etapa ' + p.paso + ' de ' + total;
        el('cotizar-ia-barra').style.width = Math.max(3, Math.round(((p.paso - 0.5) / total) * 100)) + '%';
    }

    const PROGRESO_LISTO = @json(\App\Services\CotizarIaService::PROGRESO_LISTO);
    const PROGRESO_ERROR = @json(\App\Services\CotizarIaService::PROGRESO_ERROR);
    const SIN_PROGRESO_MAX_MS = 60000;
    const ESPERA_MAX_MS = 25 * 60000;

    /** Sondea el progreso; resuelve con el resultado cuando el servidor termina la vista previa. */
    function iniciarSeguimiento(progresoId) {
        inicio = Date.now();
        el('cotizar-ia-etapa').textContent = 'Iniciando\u2026';
        el('cotizar-ia-detalle').textContent = '';
        el('cotizar-ia-paso').textContent = '';
        el('cotizar-ia-barra').style.width = '3%';
        el('cotizar-ia-tiempo').textContent = '0:00';
        relojId = setInterval(() => {
            el('cotizar-ia-tiempo').textContent = formatoTiempo(Date.now() - inicio);
        }, 1000);
        const url = urlProgresoTpl.replace('0'.repeat(32), progresoId);
        let consultando = false;
        let ultimoConProgreso = Date.now();
        return new Promise((resolve, reject) => {
            sondeoId = setInterval(async () => {
                if (consultando) {
                    return;
                }
                if (Date.now() - inicio > ESPERA_MAX_MS) {
                    reject(new Error('La IA no termin\u00f3 a tiempo. Intente nuevamente.'));
                    return;
                }
                consultando = true;
                try {
                    const resp = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!resp.ok) {
                        return;
                    }
                    const p = (await resp.json()).progreso;
                    if (!p) {
                        if (Date.now() - ultimoConProgreso > SIN_PROGRESO_MAX_MS) {
                            reject(new Error('Se perdi\u00f3 el seguimiento de la cotizaci\u00f3n con IA (el servidor pudo reiniciarse). Intente nuevamente.'));
                        }
                        return;
                    }
                    ultimoConProgreso = Date.now();
                    if (p.estado === PROGRESO_LISTO) {
                        resolve(p.resultado);
                    } else if (p.estado === PROGRESO_ERROR) {
                        reject(new Error(p.error || 'No se pudo cotizar con IA.'));
                    } else {
                        pintarProgreso(p);
                    }
                } catch (e) {
                    // fallos puntuales de red en el sondeo se ignoran; el siguiente intento reintenta
                } finally {
                    consultando = false;
                }
            }, 1500);
        });
    }

    function detenerSeguimiento() {
        clearInterval(relojId);
        clearInterval(sondeoId);
        relojId = null;
        sondeoId = null;
        return formatoTiempo(Date.now() - inicio);
    }

    function esc(valor) {
        const div = document.createElement('div');
        div.textContent = valor == null ? '' : String(valor);
        return div.innerHTML;
    }

    function mostrarError(mensaje) {
        errorBox.textContent = mensaje;
        errorBox.classList.remove('d-none');
    }

    function estado(cargandoVisible) {
        cargando.classList.toggle('d-none', !cargandoVisible);
        btnAbrir.disabled = cargandoVisible;
    }

    async function postJson(url, body) {
        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body || {}),
        });
        let data = {};
        try {
            data = await resp.json();
        } catch (e) {
            data = {};
        }
        if (!resp.ok) {
            throw new Error(data.error || data.message || ('Error HTTP ' + resp.status));
        }
        return data;
    }

    const STOCK_PRISA = {
        disponible: 'text-bg-success',
        ultimas_unidades: 'text-bg-warning',
        sin_stock: 'text-bg-danger',
        desconocido: 'text-bg-secondary',
    };

    function badgeStockPrisa(stock) {
        if (!stock) {
            return '';
        }
        const txt = 'Prisa: ' + stock.etiqueta;
        const badge = '<span class="badge ' + (STOCK_PRISA[stock.estado] || 'text-bg-secondary') + ' ms-1">' + esc(txt) + '</span>';
        return stock.url
            ? '<a href="' + esc(stock.url) + '" target="_blank" rel="noopener noreferrer" class="text-decoration-none" title="Ver en Prisa">' + badge + '</a>'
            : badge;
    }

    function notaStock(linea) {
        return (linea.medida_nota ? '<div class="text-warning-emphasis">' + esc(linea.medida_nota) + '</div>' : '')
            + (linea.stock_nota ? '<div class="text-danger">' + esc(linea.stock_nota) + '</div>' : '');
    }

    function celdaVinculo(linea) {
        if (linea.estado === 'vinculado' && linea.producto) {
            const [txt, cls] = ORIGENES[linea.origen] || ['Maestro', 'text-bg-secondary'];
            return '<span class="badge ' + cls + ' me-1">' + esc(txt) + '</span>'
                + '<span class="font-monospace">' + esc(linea.producto.prod_item) + '</span> '
                + esc(linea.producto.prod_nombre)
                + (linea.producto.unidades > 1
                    ? ' <span class="badge text-bg-info" title="Se cotizan ' + esc(linea.producto.unidades) + ' unidades del maestro por cada una solicitada">x' + esc(linea.producto.unidades) + ' un.</span>'
                    : '')
                + (linea.puede_prorratear
                    ? ' <span class="badge text-bg-info cotizar-ia-badge-prorrateo d-none" title="Precio prorrateado del pack de ' + esc(linea.pack_tamano) + ' por unidad solicitada">prorrateado pack ' + esc(linea.pack_tamano) + '</span>'
                    : '')
                + badgeStockPrisa(linea.stock_prisa)
                + (linea.producto.foto
                    ? '<div class="text-success small">Elegido por foto: se ve ' + esc(linea.producto.foto) + '</div>'
                    : '')
                + notaStock(linea);
        }
        if (linea.estado === 'referencia_web' && linea.referencia) {
            const ref = linea.referencia;
            const pack = ref.unidades_por_pack > 1 ? ' <span class="text-muted">(pack ' + esc(ref.unidades_por_pack) + ' un.)</span>' : '';
            let stock = '';
            if (ref.stock_verificado === true) {
                stock = ' <span class="badge text-bg-success">Stock: ' + esc(ref.stock) + (ref.unidades_por_pack > 1 ? ' packs' : '') + '</span>';
            } else if (ref.stock_verificado === false) {
                stock = ' <span class="badge text-bg-warning" title="La publicación no muestra stock; revíselo con «ver»">Stock no verificado</span>';
            }
            const solicitud = ref.unidades_solicitud > 1
                ? ' <span class="badge text-bg-info" title="La l\u00ednea pide un pack de ' + esc(ref.unidades_solicitud) + '; el costo es el de esas unidades">pack de ' + esc(ref.unidades_solicitud) + ' solicitado</span>'
                : '';
            const prorrateo = linea.puede_prorratear
                ? ' <span class="badge text-bg-info cotizar-ia-badge-prorrateo d-none" title="Costo unitario = precio del pack prorrateado">prorrateado pack ' + esc(linea.pack_tamano) + '</span>'
                : '';
            return '<span class="badge text-bg-warning me-1">' + esc(ref.sitio) + '</span>'
                + esc(ref.titulo) + pack + prorrateo + solicitud
                + ' <a href="' + esc(ref.url) + '" target="_blank" rel="noopener noreferrer">ver</a>'
                + stock
                + '<div class="text-muted">$' + numero.format(ref.precio_clp) + ' c/IVA</div>'
                + notaStock(linea);
        }
        return '<span class="badge text-bg-secondary">Sin v\u00ednculo</span>' + notaStock(linea);
    }

    function costoLinea(linea) {
        if (linea.estado === 'pendiente') {
            return '';
        }
        if (linea.costo > 0 && linea.costo_estimado) {
            return '$' + numero.format(linea.costo)
                + ' <span class="text-muted" title="Costo referencial: precio del maestro / ' + esc(FACTOR_RM_TEXTO) + ' (factor Metropolitana)">(ref.)</span>';
        }
        if (linea.costo > 0) {
            return '$' + numero.format(linea.costo);
        }
        return '<span class="text-danger" title="El producto no tiene costo ni precio en el maestro">sin costo</span>';
    }

    function metricasFila(fila, factor) {
        const cantidadAgile = parseInt(fila.dataset.cantidad, 10) || 0;
        const puede = fila.dataset.puedeProrratear === '1';
        const prorratear = puede && fila.querySelector('.cotizar-ia-prorratear')?.checked;
        const pack = parseInt(fila.dataset.packTamano, 10) || 1;
        const solicitud = parseInt(fila.dataset.unidadesSolicitud, 10) || 1;
        let costo;
        let precioRm;
        let cantidadFacturar = cantidadAgile;
        if (puede && prorratear) {
            costo = parseInt(fila.dataset.costoProrrateado, 10) || 0;
            precioRm = parseInt(fila.dataset.precioRmProrrateado, 10) || 0;
        } else if (puede) {
            costo = parseInt(fila.dataset.costoPack, 10) || 0;
            precioRm = parseInt(fila.dataset.precioRmPack, 10) || 0;
            cantidadFacturar = Math.ceil(cantidadAgile * solicitud / pack);
        } else {
            costo = parseInt(fila.dataset.costo, 10) || 0;
            precioRm = parseInt(fila.dataset.precioRm, 10) || 0;
        }
        let venta = 0;
        if (factor !== null) {
            if (precioRm > 0 && Math.abs(factor - FACTOR_RM) < 0.001) {
                venta = precioRm;
            } else if (costo > 0) {
                venta = Math.round(costo * factor);
            } else {
                venta = parseInt(fila.dataset.venta, 10) || 0;
            }
        }
        return { costo, venta, cantidadFacturar, prorratear: !!prorratear, puede };
    }

    function actualizarFilaProrrateo(fila) {
        const puede = fila.dataset.puedeProrratear === '1';
        const prorratear = puede && fila.querySelector('.cotizar-ia-prorratear')?.checked;
        fila.classList.toggle('cotizar-ia-fila-prorrateo', puede);
        fila.querySelectorAll('.cotizar-ia-badge-prorrateo').forEach((badge) => {
            badge.classList.toggle('d-none', !prorratear);
        });
        const estado = fila.querySelector('.cotizar-ia-prorrateo-estado');
        if (estado) {
            estado.textContent = prorratear ? 'S\u00ed' : 'No';
            estado.classList.toggle('fw-semibold', !!prorratear);
            estado.classList.toggle('text-body', !!prorratear);
            estado.classList.toggle('text-muted', !prorratear);
        }
    }

    function textoCostoCelda(fila, metricas) {
        if (fila.dataset.estado === 'pendiente') {
            return '';
        }
        const ref = fila.dataset.costoEstimado === '1';
        let html = metricas.costo > 0 ? '$' + numero.format(metricas.costo) : '<span class="text-danger">sin costo</span>';
        if (metricas.costo > 0 && ref) {
            html += ' <span class="text-muted" title="Costo referencial">(ref.)</span>';
        }
        if (metricas.puede && !metricas.prorratear && metricas.costo > 0) {
            html += '<div class="text-muted" style="font-size:0.7rem">por pack</div>';
        }
        if (metricas.puede && !metricas.prorratear && metricas.cantidadFacturar !== parseInt(fila.dataset.cantidad, 10)) {
            html += '<div class="text-muted" style="font-size:0.7rem">' + metricas.cantidadFacturar + ' packs</div>';
        }
        return html;
    }

    function factorActual() {
        const f = parseFloat(el('cotizar-ia-factor').value);
        return Number.isFinite(f) && f >= 1 && f <= 5 ? f : null;
    }

    function recalcularVenta() {
        const factor = factorActual();
        let total = 0;
        tbody.querySelectorAll('tr[data-indice]').forEach((fila) => {
            actualizarFilaProrrateo(fila);
            const conPrecio = fila.dataset.estado !== 'pendiente' && factor !== null;
            const m = metricasFila(fila, factor);
            const celdaCosto = fila.querySelector('.cotizar-ia-costo');
            if (celdaCosto) {
                celdaCosto.innerHTML = conPrecio ? textoCostoCelda(fila, m) : '';
            }
            fila.querySelector('.cotizar-ia-venta').textContent = conPrecio && m.venta > 0 ? '$' + numero.format(m.venta) : '';
            const sub = conPrecio && m.venta > 0 ? m.venta * m.cantidadFacturar : 0;
            fila.querySelector('.cotizar-ia-subtotal').textContent = sub > 0 ? '$' + numero.format(sub) : '';
            const usar = fila.querySelector('.cotizar-ia-usar');
            if (conPrecio && usar && usar.checked) {
                total += sub;
            }
        });
        el('cotizar-ia-total').textContent = factor === null ? 'Factor inv\u00e1lido' : '$' + numero.format(total);
    }

    function filaLinea(linea) {
        const conVinculo = linea.estado !== 'pendiente';
        const fuente = linea.fuente === 'adjunto' ? ' <span class="badge text-bg-light border">adjunto</span>' : '';
        const puedeProrr = !!linea.puede_prorratear;
        return '<tr' + (puedeProrr ? ' class="cotizar-ia-fila-prorrateo"' : '') + ' data-indice="' + linea.indice + '" data-estado="' + esc(linea.estado) + '" data-cantidad="' + (parseInt(linea.cantidad, 10) || 0)
            + '" data-costo="' + (parseInt(linea.costo, 10) || 0) + '" data-venta="' + (parseInt(linea.precio_venta, 10) || 0)
            + '" data-precio-rm="' + (parseInt(linea.precio_rm, 10) || 0)
            + '" data-costo-estimado="' + (linea.costo_estimado ? '1' : '0')
            + '" data-puede-prorratear="' + (puedeProrr ? '1' : '0')
            + '" data-pack-tamano="' + (parseInt(linea.pack_tamano, 10) || 0)
            + '" data-unidades-solicitud="' + (parseInt(linea.unidades_solicitud, 10) || 1)
            + '" data-costo-prorrateado="' + (parseInt(linea.costo_prorrateado, 10) || 0)
            + '" data-costo-pack="' + (parseInt(linea.costo_pack, 10) || 0)
            + '" data-precio-rm-prorrateado="' + (parseInt(linea.precio_rm_prorrateado, 10) || 0)
            + '" data-precio-rm-pack="' + (parseInt(linea.precio_rm_pack, 10) || 0) + '">'
            + '<td class="text-end tabular-nums">' + (linea.indice + 1) + '</td>'
            + '<td>' + esc(linea.descripcion) + fuente + '</td>'
            + '<td class="text-end tabular-nums">' + numero.format(linea.cantidad) + '</td>'
            + '<td>' + celdaVinculo(linea) + '</td>'
            + '<td class="text-end tabular-nums cotizar-ia-costo">' + costoLinea(linea) + '</td>'
            + '<td class="text-center">'
            + (puedeProrr
                ? '<div class="d-flex flex-column align-items-center gap-1">'
                    + '<span class="text-muted" style="font-size:0.65rem;line-height:1">Prorratea</span>'
                    + '<input type="checkbox" class="form-check-input cotizar-ia-prorratear m-0" data-indice="' + linea.indice + '" aria-label="Prorratear pack en l\u00ednea ' + (linea.indice + 1) + '">'
                    + '<span class="cotizar-ia-prorrateo-estado text-muted" style="font-size:0.7rem">No</span>'
                    + '</div>'
                : '')
            + '</td>'
            + '<td class="text-end tabular-nums cotizar-ia-venta"></td>'
            + '<td class="text-end tabular-nums cotizar-ia-subtotal"></td>'
            + '<td class="text-center">'
            + (conVinculo
                ? '<input type="checkbox" class="form-check-input cotizar-ia-usar" data-indice="' + linea.indice + '" checked aria-label="Usar v\u00ednculo de la l\u00ednea ' + (linea.indice + 1) + '">'
                : '')
            + '</td>'
            + '</tr>';
    }

    function separarActivo() {
        return grupos.length > 1 && el('cotizar-ia-separar-check').checked;
    }

    function actualizarBotonAplicar() {
        el('btn-cotizar-ia-aplicar-texto').textContent = separarActivo()
            ? 'Crear ' + grupos.length + ' cotizaciones'
            : 'Agregar a la cotizaci\u00f3n';
    }

    function mostrarCreadas(cotizaciones) {
        const creadas = el('cotizar-ia-creadas');
        creadas.innerHTML = '<div class="fw-semibold mb-1"><i class="bi bi-check2-circle"></i> Se crearon '
            + cotizaciones.length + ' cotizaciones:</div><ul class="mb-0 ps-3">'
            + cotizaciones.map((c) => '<li><a href="' + esc(c.edit_url) + '">#' + esc(c.nronota) + '</a> \u2014 '
                + esc(c.solicitante) + ' (' + esc(c.agregadas) + ' l\u00ednea(s))</li>').join('')
            + '</ul>';
        creadas.classList.remove('d-none');
        resultado.classList.add('d-none');
        btnAplicar.classList.add('d-none');
    }

    function pesos(valor) {
        return '$' + numero.format(Math.round(Number(valor) || 0));
    }

    function pintarUso(uso) {
        const box = el('cotizar-ia-uso');
        if (!uso || !uso.llamadas) {
            box.classList.add('d-none');
            return;
        }
        el('cotizar-ia-uso-tokens').textContent = numero.format(uso.total_tokens || 0);
        el('cotizar-ia-uso-costo').textContent = pesos(uso.costo_clp);
        const gratis = (uso.llamadas || 0) - (uso.llamadas_pago || 0);
        el('cotizar-ia-uso-referencial').textContent = uso.llamadas + ' llamada(s): '
            + (uso.llamadas_pago || 0) + ' pagada(s), ' + gratis + ' gratuita(s)'
            + (gratis > 0 ? ' \u00b7 si todo fuera pagado: ' + pesos(uso.costo_referencial_clp) : '');
        el('cotizar-ia-uso-etapas').innerHTML = (uso.etapas || []).map((e) => '<tr>'
            + '<td>' + esc(e.etapa) + '</td>'
            + '<td class="text-end tabular-nums">' + numero.format(e.llamadas) + '</td>'
            + '<td class="text-end tabular-nums">' + numero.format(e.entrada) + '</td>'
            + '<td class="text-end tabular-nums">' + numero.format(e.salida) + '</td>'
            + '<td class="text-end tabular-nums">' + numero.format(e.pensamiento) + '</td>'
            + '<td class="text-end tabular-nums">' + pesos(e.costo_clp)
            + (e.costo_referencial_clp > e.costo_clp ? ' <span class="text-muted">(ref. ' + pesos(e.costo_referencial_clp) + ')</span>' : '')
            + '</td>'
            + '</tr>').join('');
        el('cotizar-ia-uso-nota').textContent = 'Costo estimado con las tarifas configuradas y d\u00f3lar a '
            + pesos(uso.usd_clp) + '. La cuenta gratuita de Gemini no se cobra; el pensamiento se cobra como salida.';
        box.classList.remove('d-none');
    }

    function pintar(data) {
        token = data.token;
        mostrarCodigoCotizacion(data.codigo);
        pintarUso(data.uso_ia);
        el('cotizar-ia-fuente').textContent = FUENTES[data.fuente] || data.fuente;
        el('cotizar-ia-motivo').textContent = data.fuente_motivo || '';
        el('cotizar-ia-adjuntos').textContent = (data.adjuntos_usados || []).length
            ? 'Adjuntos usados: ' + data.adjuntos_usados.join(', ')
            : ((data.adjuntos_disponibles || []).length ? 'Adjuntos revisados: ' + data.adjuntos_disponibles.join(', ') : '');

        const avisos = data.avisos || [];
        const avisosEl = el('cotizar-ia-avisos');
        avisosEl.innerHTML = avisos.map((a) => '<div>' + esc(a) + '</div>').join('');
        avisosEl.classList.toggle('d-none', avisos.length === 0);

        const r = data.resumen || {};
        el('cotizar-ia-resumen').textContent = (r.total || 0) + ' l\u00ednea(s): '
            + (r.vinculados || 0) + ' vinculadas (' + (r.por_ia || 0) + ' por IA), '
            + (r.referencias_web || 0) + ' con referencia web, '
            + (r.pendientes || 0) + ' sin v\u00ednculo.';

        const lineas = data.lineas || [];
        grupos = data.separar ? (data.grupos || []) : [];
        if (grupos.length > 1) {
            const porIndice = new Map(lineas.map((l) => [l.indice, l]));
            tbody.innerHTML = grupos.map((g, k) => '<tr class="table-info">'
                + '<td colspan="8"><strong>Cotizaci\u00f3n ' + (k + 1) + (k === 0 ? ' (esta)' : ' (copia)') + ':</strong> '
                + esc(g.solicitante) + ' <span class="text-muted">\u2014 ' + g.indices.length + ' l\u00ednea(s)</span></td>'
                + '</tr>'
                + g.indices.map((i) => porIndice.get(i)).filter(Boolean).map(filaLinea).join('')).join('');
            el('cotizar-ia-separar-lista').innerHTML = grupos.map((g, k) => '<li><strong>' + esc(g.solicitante) + '</strong> \u2014 '
                + g.indices.length + ' l\u00ednea(s)' + (k === 0 ? ' <span class="text-muted">(en esta cotizaci\u00f3n)</span>' : ' <span class="text-muted">(copia nueva)</span>')
                + '</li>').join('');
            el('cotizar-ia-separar-label').textContent = 'Separar en ' + grupos.length + ' cotizaciones: esta y '
                + (grupos.length - 1) + ' copia(s) con el mismo c\u00f3digo. El solicitante queda en \u00abObs. ejecutivo\u00bb.';
            el('cotizar-ia-separar-check').checked = true;
        } else {
            tbody.innerHTML = lineas.map(filaLinea).join('');
        }
        el('cotizar-ia-separar').classList.toggle('d-none', grupos.length < 2);
        actualizarBotonAplicar();

        const venta = data.venta || {};
        factorInicial = Number(venta.factor) || null;
        el('cotizar-ia-factor').value = factorInicial ? factorInicial.toFixed(2) : '';
        el('cotizar-ia-region').innerHTML = venta.region
            ? 'Regi\u00f3n del organismo: <strong>' + esc(venta.nombre_region) + '</strong> ('
                + (venta.region === REGION_METROPOLITANA ? 'factor Metropolitana' : 'factor regiones') + ')'
            : '<span class="text-danger">Regi\u00f3n del organismo desconocida: se usa el factor de la cotizaci\u00f3n; rev\u00edselo.</span>';
        recalcularVenta();

        const actuales = data.lineas_actuales_agile || 0;
        el('cotizar-ia-wrap-reemplazar').classList.toggle('d-none', actuales === 0);
        el('cotizar-ia-reemplazar').checked = actuales > 0;
        el('cotizar-ia-reemplazar-label').textContent = 'Reemplazar las ' + actuales
            + ' l\u00ednea(s) importadas actuales (con ID Agile). Las l\u00edneas agregadas a mano se mantienen.';

        resultado.classList.remove('d-none');
        btnAplicar.classList.toggle('d-none', (data.lineas || []).length === 0);
        btnAplicar.disabled = false;
    }

    btnAbrir.addEventListener('click', async () => {
        if (enCurso) {
            return;
        }
        const codigo = String(document.getElementById('encargado')?.value || '').trim().toUpperCase();
        if (!codigo) {
            mostrarCodigoCotizacion('');
            mostrarError('Ingrese el n\u00famero de cotizaci\u00f3n de Mercado P\u00fablico.');
            resultado.classList.add('d-none');
            btnAplicar.classList.add('d-none');
            modal.show();
            return;
        }
        mostrarCodigoCotizacion(codigo);
        enCurso = true;
        token = null;
        grupos = [];
        errorBox.classList.add('d-none');
        el('cotizar-ia-creadas').classList.add('d-none');
        resultado.classList.add('d-none');
        btnAplicar.classList.add('d-none');
        modal.show();
        estado(true);
        const progresoId = nuevoProgresoId();
        const terminado = iniciarSeguimiento(progresoId);
        try {
            const inicial = await postJson(urlCon(urlPreviewTpl), { codigo, progreso_id: progresoId, async: true });
            const data = inicial.token ? inicial : await terminado;
            const tiempo = detenerSeguimiento();
            pintar(data);
            el('cotizar-ia-resumen').textContent += ' Tiempo: ' + tiempo + '.';
        } catch (e) {
            const tiempo = detenerSeguimiento();
            mostrarError((e.message || 'No se pudo cotizar con IA.') + ' (tras ' + tiempo + ')');
        } finally {
            estado(false);
            enCurso = false;
        }
    });

    btnAplicar.addEventListener('click', async () => {
        if (!token || enCurso) {
            return;
        }
        const factor = factorActual();
        if (factor === null) {
            mostrarError('Ingrese un factor de venta entre 1 y 5.');
            return;
        }
        enCurso = true;
        btnAplicar.disabled = true;
        errorBox.classList.add('d-none');
        const rechazados = Array.from(tbody.querySelectorAll('.cotizar-ia-usar'))
            .filter((cb) => !cb.checked)
            .map((cb) => parseInt(cb.dataset.indice, 10));
        const reemplazar = !el('cotizar-ia-wrap-reemplazar').classList.contains('d-none')
            && el('cotizar-ia-reemplazar').checked;
        const separar = separarActivo();
        try {
            const prorratear = Array.from(tbody.querySelectorAll('.cotizar-ia-prorratear'))
                .filter((cb) => cb.checked)
                .map((cb) => parseInt(cb.dataset.indice, 10));
            const cuerpo = { token, rechazados, reemplazar, separar, prorratear };
            if (factorInicial === null || Math.abs(factor - factorInicial) >= 0.005) {
                cuerpo.factor = Math.round(factor * 100) / 100;
            }
            const data = await postJson(urlCon(urlAplicarTpl), cuerpo);
            const cotizaciones = data.cotizaciones || [];
            if (cotizaciones.length > 1) {
                token = null;
                enCurso = false;
                destinoAlCerrar = cotizaciones[0].edit_url;
                mostrarCreadas(cotizaciones);
                return;
            }
            if (data.edit_url) {
                window.location.href = data.edit_url;
            } else {
                window.location.reload();
            }
        } catch (e) {
            mostrarError(e.message || 'No se pudieron agregar las l\u00edneas.');
            btnAplicar.disabled = false;
            enCurso = false;
        }
    });

    el('cotizar-ia-separar-check').addEventListener('change', actualizarBotonAplicar);
    el('cotizar-ia-factor').addEventListener('input', recalcularVenta);
    tbody.addEventListener('change', (e) => {
        if (e.target.classList.contains('cotizar-ia-usar') || e.target.classList.contains('cotizar-ia-prorratear')) {
            recalcularVenta();
        }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        if (destinoAlCerrar) {
            window.location.href = destinoAlCerrar;
        }
    });
})();
</script>
@endpush
