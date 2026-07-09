<div class="modal fade" id="modal-comparar-mp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6" id="modal-comparar-titulo">Comparar precios</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2" id="modal-comparar-body">
                <p class="text-muted small mb-0">Cargando…</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-detalle-mp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6" id="modal-detalle-titulo">Detalle Mercado Público</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2" id="modal-detalle-body">
                <p class="text-muted small mb-0">Cargando…</p>
            </div>
        </div>
    </div>
</div>

@push('head')
<style>
    @keyframes mp-cambio-destello {
        0%, 100% { background-color: transparent; color: inherit; }
        20% { background-color: #ffc107; color: #212529; }
        40% { background-color: #fff3cd; color: #212529; }
        60% { background-color: #ffc107; color: #212529; }
        80% { background-color: #fff3cd; color: #212529; }
    }
    .mp-cambio-destello {
        animation: mp-cambio-destello 1.4s ease-out;
        border-radius: 0.25rem;
    }
    td.mp-cambio-destello {
        display: table-cell;
    }
    .consulta-mp-mensaje.mp-cambio-destello {
        display: inline-block;
        padding: 0.15rem 0.35rem;
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urlDetalle = @json(url('/admin/compra-agil/resultados/detalle/__NRO__'));
    const urlConsultarBase = @json(url('/admin/compra-agil/resultados/consultar'));
    const cotizSistema = @json(config('cotiz.sistema'));
    const segLabels = {
        cerrada: 'Cerrada',
        pendiente: 'Pendiente seguimiento',
        desierta: 'Desierta',
        cancelada: 'Cancelada',
        no_encontrada: 'No existe en MP',
        sin_consultar: 'Sin consultar MP',
    };

    const fmtMonto = (n) => '$' + (Number(n) || 0).toLocaleString('es-CL');
    const fmtFecha = (iso) => {
        if (!iso) return '—';
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
    };

    function labelSeguimiento(codigo) {
        return segLabels[codigo] || codigo || '—';
    }

    function fmtGlosa(codigo, glosa) {
        return glosa || codigo || '—';
    }

    function destellarCambio(el) {
        if (!el) return;
        el.classList.remove('mp-cambio-destello');
        void el.offsetWidth;
        el.classList.add('mp-cambio-destello');
        el.addEventListener('animationend', function onEnd() {
            el.classList.remove('mp-cambio-destello');
            el.removeEventListener('animationend', onEnd);
        });
    }

    function cambioEstadoMp(r) {
        return r.estado_anterior != null && r.estado_nuevo != null && r.estado_anterior !== r.estado_nuevo;
    }

    function cambioSeguimiento(r) {
        return r.resultado_anterior != null && r.resultado_propio != null && r.resultado_anterior !== r.resultado_propio;
    }

    function feedbackRow(nronota) {
        return document.querySelector('tr.consulta-mp-feedback[data-nronota="' + nronota + '"]');
    }

    function dataRowPendiente(nronota) {
        return document.querySelector('tr.pendiente-data-row[data-nronota="' + nronota + '"]');
    }

    function dataRowNovedad(nronota) {
        return document.querySelector('tr.novedad-data-row[data-nronota="' + nronota + '"]');
    }

    function dataRowTodas(nronota) {
        return document.querySelector('tr.todas-data-row[data-nronota="' + nronota + '"]');
    }

    function dataRowResultadoUltimo(nronota) {
        return document.querySelector('tr.resultado-ultimo-data-row[data-nronota="' + nronota + '"]');
    }

    function badgeSeguimientoHtml(codigo) {
        const labels = {
            cerrada: ['success', 'Cerrada'],
            pendiente: ['warning', 'Pendiente seguimiento'],
            desierta: ['secondary', 'Desierta'],
            cancelada: ['secondary', 'Cancelada'],
            no_encontrada: ['dark', 'No existe en MP'],
            sin_consultar: ['info', 'Sin consultar MP'],
        };
        const info = labels[codigo] || ['secondary', codigo || '—'];
        return '<span class="badge text-bg-' + info[0] + '">' + info[1] + '</span>';
    }

    function mostrarProgresoConsulta(nronota) {
        const fb = feedbackRow(nronota);
        if (!fb) return false;
        fb.classList.remove('d-none');
        const bar = fb.querySelector('.consulta-mp-progress-bar');
        const msg = fb.querySelector('.consulta-mp-mensaje');
        if (bar) {
            bar.style.width = '35%';
            bar.classList.add('progress-bar-animated', 'progress-bar-striped');
            bar.classList.remove('bg-success', 'bg-danger');
        }
        if (msg) {
            msg.className = 'consulta-mp-mensaje small mt-1 text-muted';
            msg.textContent = 'Consultando Mercado Público…';
        }
        return true;
    }

    function finalizarProgresoConsulta(nronota, ok) {
        const fb = feedbackRow(nronota);
        if (!fb) return;
        const bar = fb.querySelector('.consulta-mp-progress-bar');
        if (bar) {
            bar.style.width = '100%';
            bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
            bar.classList.add(ok ? 'bg-success' : 'bg-danger');
        }
    }

    function fmtTiempoRespuestaMs(ms) {
        if (ms == null || ms < 0 || Number.isNaN(ms)) {
            return '';
        }
        if (ms < 1000) {
            return ms + ' ms';
        }
        const seg = ms / 1000;
        return (seg >= 10 ? Math.round(seg) : seg.toFixed(1).replace('.', ',')) + ' s';
    }

    function sufijoTiempoRespuesta(r, msCliente) {
        const total = r && r.ms_total != null ? r.ms_total : msCliente;
        if (total == null) {
            return '';
        }
        let txt = ' · Tiempo: ' + fmtTiempoRespuestaMs(total);
        if (r && r.ms_api != null && r.ms_guardado != null && r.ms_guardado > 50) {
            txt += ' (API: ' + fmtTiempoRespuestaMs(r.ms_api)
                + ', guardado: ' + fmtTiempoRespuestaMs(r.ms_guardado) + ')';
        } else if (r && r.ms_api != null && Math.abs(r.ms_api - total) > 50) {
            txt += ' (API: ' + fmtTiempoRespuestaMs(r.ms_api) + ')';
        }
        return txt;
    }

    function mensajeConsultaResultado(r) {
        const antGlosa = fmtGlosa(r.estado_anterior, r.estado_anterior_glosa);
        const nueGlosa = fmtGlosa(r.estado_nuevo, r.estado_glosa);
        const antSeg = labelSeguimiento(r.resultado_anterior);
        const nueSeg = labelSeguimiento(r.resultado_propio);

        if (r.cambio) {
            let txt = 'Cambio detectado: ' + antGlosa + ' → ' + nueGlosa;
            if (r.resultado_anterior !== r.resultado_propio) {
                txt += ' · Seguimiento: ' + antSeg + ' → ' + nueSeg;
            } else if (r.resultado_anterior == null && r.resultado_propio) {
                txt += ' · Seguimiento: ' + nueSeg;
            }
            return txt + sufijoTiempoRespuesta(r);
        }

        return 'Sin cambios de estado (' + nueGlosa + ' · ' + nueSeg + ')' + sufijoTiempoRespuesta(r);
    }

    function cambioSeguimiento(r) {
        return r.resultado_anterior != null
            && r.resultado_propio != null
            && r.resultado_anterior !== r.resultado_propio;
    }

    function crearBotonDetalle(nronota) {
        const det = document.createElement('button');
        det.type = 'button';
        det.className = 'btn btn-outline-secondary btn-sm btn-detalle-mp';
        det.dataset.nronota = String(nronota);
        det.textContent = 'Detalle';
        return det;
    }

    function crearBotonComparar(nronota) {
        const cmp = document.createElement('button');
        cmp.type = 'button';
        cmp.className = 'btn btn-outline-primary btn-sm btn-comparar-mp';
        cmp.dataset.nronota = String(nronota);
        cmp.title = 'Comparar precios';
        cmp.innerHTML = '<i class="bi bi-arrow-left-right"></i> Comparar';
        return cmp;
    }

    function actualizarAccionesFila(row, nronota, r) {
        const acciones = row.querySelector('.cell-acciones');
        if (!acciones) return;

        const btnConsultar = acciones.querySelector('.btn-consultar-mp-individual');
        if (btnConsultar && r.resultado_propio !== 'pendiente') {
            btnConsultar.remove();
        } else if (btnConsultar) {
            btnConsultar.disabled = false;
            btnConsultar.dataset.consultando = '0';
        }

        const puedeComparar = r.razon_social_ganador
            || r.estado_nuevo === 'proveedor_seleccionado'
            || r.estado_nuevo === 'cerrada';

        if (puedeComparar && !acciones.querySelector('.btn-comparar-mp')) {
            const cmp = crearBotonComparar(nronota);
            const det = acciones.querySelector('.btn-detalle-mp');
            if (det) {
                acciones.insertBefore(cmp, det);
            } else {
                acciones.appendChild(cmp);
            }
        }

        if (r.resultado_propio && r.resultado_propio !== 'sin_consultar' && !acciones.querySelector('.btn-detalle-mp')) {
            acciones.appendChild(crearBotonDetalle(nronota));
        }
    }

    function actualizarFilaTablaComun(row, nronota, r) {
        if (!row) return;

        const estadoCell = row.querySelector('.cell-estado-mp');
        if (estadoCell) {
            const anterior = estadoCell.textContent.trim();
            const nuevo = r.estado_glosa || r.estado_nuevo || '—';
            estadoCell.textContent = nuevo;
            if (cambioEstadoMp(r) || anterior === '—' || anterior === '') {
                destellarCambio(estadoCell);
            }
        }

        const provCell = row.querySelector('.cell-proveedor');
        if (provCell && r.razon_social_ganador) {
            provCell.textContent = r.razon_social_ganador.length > 30
                ? r.razon_social_ganador.substring(0, 30) + '…'
                : r.razon_social_ganador;
        }

        const montoCell = row.querySelector('.cell-monto');
        if (montoCell && r.monto_total_ganador) {
            montoCell.textContent = fmtMonto(r.monto_total_ganador);
        }

        const consultadoCell = row.querySelector('.cell-consultado');
        if (consultadoCell) {
            const now = new Date();
            consultadoCell.textContent = now.toLocaleDateString('es-CL') + ' ' + now.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
        }

        const segCell = row.querySelector('.cell-seguimiento');
        if (segCell && r.resultado_propio) {
            const eraSinConsultar = segCell.textContent.trim().includes('Sin consultar MP');
            segCell.innerHTML = badgeSeguimientoHtml(r.resultado_propio);
            if (cambioSeguimiento(r) || eraSinConsultar) {
                destellarCambio(segCell.querySelector('.badge') || segCell);
            }
        }

        actualizarAccionesFila(row, nronota, r);

        if (r.cambio) {
            row.classList.add('table-info');
        }
    }

    function actualizarFilaPendiente(nronota, r) {
        actualizarFilaTablaComun(dataRowPendiente(nronota), nronota, r);
    }

    function actualizarFilaTodas(nronota, r) {
        actualizarFilaTablaComun(dataRowTodas(nronota), nronota, r);
    }

    function actualizarFilaNovedad(nronota, r) {
        const row = dataRowNovedad(nronota);
        if (!row) return;

        const cambioCell = row.querySelector('.cell-cambio-estado');
        if (cambioCell) {
            const ant = fmtGlosa(r.estado_anterior, r.estado_anterior_glosa);
            const nue = fmtGlosa(r.estado_nuevo, r.estado_glosa);
            cambioCell.innerHTML = ant + ' <i class="bi bi-arrow-right"></i> <strong>' + nue + '</strong>';
            if (cambioEstadoMp(r)) {
                destellarCambio(cambioCell);
            }
        }

        const provCell = row.querySelector('.cell-proveedor');
        if (provCell && r.razon_social_ganador) {
            let html = r.razon_social_ganador;
            if (r.rut_ganador) {
                html += '<br><span class="text-muted">' + r.rut_ganador + '</span>';
            }
            provCell.innerHTML = html;
        }

        const montoCell = row.querySelector('.cell-monto');
        if (montoCell && r.monto_total_ganador) {
            montoCell.textContent = fmtMonto(r.monto_total_ganador);
        }

        const ocCell = row.querySelector('.cell-oc');
        if (ocCell && r.id_orden_compra) {
            ocCell.textContent = String(r.id_orden_compra);
        }

        const segCell = row.querySelector('.cell-seguimiento');
        if (segCell && r.resultado_propio) {
            const eraSinConsultar = segCell.textContent.trim().includes('Sin consultar MP');
            segCell.innerHTML = badgeSeguimientoHtml(r.resultado_propio);
            if (cambioSeguimiento(r) || eraSinConsultar) {
                destellarCambio(segCell.querySelector('.badge') || segCell);
            }
        }

        actualizarAccionesFila(row, nronota, r);

        if (r.cambio) {
            row.classList.add('table-info');
        }
    }

    function esFilaResultadoUltimo(row) {
        return !!row && (
            row.classList.contains('resultado-ultimo-data-row')
            || row.querySelector('.cell-error-detalle') != null
        );
    }

    function filaDatosDesdeHint(dataRowHint, nronota) {
        if (dataRowHint && dataRowHint.tagName === 'TR' && !dataRowHint.classList.contains('consulta-mp-feedback')) {
            return dataRowHint;
        }
        const fb = feedbackRow(nronota);
        if (fb && fb.previousElementSibling && fb.previousElementSibling.tagName === 'TR') {
            return fb.previousElementSibling;
        }
        return dataRowResultadoUltimo(nronota);
    }

    function puedeCompararNota(r) {
        const estadosComparables = ['proveedor_seleccionado', 'cerrada', 'oc_emitida'];
        return !!(r.razon_social_ganador
            || r.resultado_propio === 'cerrada'
            || r.finalizado
            || estadosComparables.includes(r.estado_nuevo));
    }

    function actualizarFilaResultadoUltimo(nronota, r, rowHint) {
        const row = filaDatosDesdeHint(rowHint, nronota);
        if (!row) return;

        row.classList.remove('table-light');
        if (r.cambio) {
            row.classList.add('table-info');
        }

        const resultadoCell = row.querySelector('.cell-resultado');
        if (resultadoCell) {
            const codigoSeguimiento = r.resultado_propio
                || (r.finalizado ? 'cerrada' : 'pendiente');
            let html = badgeSeguimientoHtml(codigoSeguimiento);
            if (r.cambio) {
                html += ' <span class="badge text-bg-info ms-1">Cambio</span>';
            }
            resultadoCell.innerHTML = html;
            destellarCambio(resultadoCell.querySelector('.badge') || resultadoCell);
        }

        const errorCell = row.querySelector('.cell-error-detalle');
        if (errorCell) {
            errorCell.innerHTML = '<span class="text-muted">—</span>';
        }

        const estadoCell = row.querySelector('.cell-estado-mp');
        if (estadoCell) {
            const nuevo = r.estado_glosa || r.estado_nuevo || '—';
            estadoCell.textContent = nuevo;
            destellarCambio(estadoCell);
        }

        const provCell = row.querySelector('.cell-proveedor');
        if (provCell) {
            if (r.razon_social_ganador) {
                let html = r.razon_social_ganador;
                if (r.rut_ganador) {
                    html += '<br><span class="text-muted">' + r.rut_ganador + '</span>';
                }
                provCell.innerHTML = html;
            } else {
                provCell.textContent = '—';
            }
        }

        actualizarAccionesFilaResultadoUltimo(row, nronota, r);
    }

    function actualizarAccionesFilaResultadoUltimo(row, nronota, r) {
        const acciones = row.querySelector('.cell-acciones');
        if (!acciones) return;

        acciones.querySelectorAll('.btn-consultar-mp-individual').forEach(function (el) {
            el.remove();
        });

        if (puedeCompararNota(r) && !acciones.querySelector('.btn-comparar-mp')) {
            acciones.appendChild(crearBotonComparar(nronota));
        }

        const codigoSeguimiento = r.resultado_propio || (r.finalizado ? 'cerrada' : 'pendiente');
        if (codigoSeguimiento !== 'sin_consultar' && !acciones.querySelector('.btn-detalle-mp')) {
            acciones.appendChild(crearBotonDetalle(nronota));
        }
    }

    function actualizarFilaConsultaMp(nronota, r, dataRowHint) {
        const rowHint = filaDatosDesdeHint(dataRowHint, nronota);

        if (esFilaResultadoUltimo(rowHint)) {
            actualizarFilaResultadoUltimo(nronota, r, rowHint);
            return;
        }
        if (dataRowPendiente(nronota)) {
            actualizarFilaPendiente(nronota, r);
        } else if (dataRowTodas(nronota)) {
            actualizarFilaTodas(nronota, r);
        } else {
            actualizarFilaNovedad(nronota, r);
        }
    }

    async function consultarMercadoPublico(btn, nronota) {
        if (btn.dataset.consultando === '1') {
            return;
        }

        btn.dataset.consultando = '1';
        btn.disabled = true;
        const tieneFilaProgreso = mostrarProgresoConsulta(nronota);
        const tInicio = performance.now();

        try {
            const res = await fetch(urlConsultarBase + '/' + encodeURIComponent(nronota), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            const data = await res.json().catch(function () { return {}; });
            const msCliente = Math.round(performance.now() - tInicio);

            if (!res.ok) {
                const errMsg = (data.error || 'Error al consultar Mercado Público.')
                    + sufijoTiempoRespuesta(data, data.ms_total != null ? data.ms_total : msCliente);
                if (tieneFilaProgreso) {
                    finalizarProgresoConsulta(nronota, false);
                    const msg = feedbackRow(nronota)?.querySelector('.consulta-mp-mensaje');
                    if (msg) {
                        msg.className = 'consulta-mp-mensaje small mt-1 text-danger';
                        msg.textContent = errMsg;
                    }
                } else if (window.AdminDialog) {
                    AdminDialog.alert(errMsg, { title: 'Consultar MP', type: 'danger' });
                } else {
                    alert(errMsg);
                }
                btn.disabled = false;
                btn.dataset.consultando = '0';
                return;
            }

            const r = data.resultado || {};
            r.ms_total = r.ms_total != null ? r.ms_total : msCliente;
            if (tieneFilaProgreso) {
                finalizarProgresoConsulta(nronota, true);
                const msg = feedbackRow(nronota)?.querySelector('.consulta-mp-mensaje');
                if (msg) {
                    msg.className = 'consulta-mp-mensaje small mt-1 fw-semibold ' + (r.cambio ? 'text-success' : 'text-secondary');
                    msg.textContent = mensajeConsultaResultado(r);
                    if (r.cambio) {
                        destellarCambio(msg);
                    }
                }
                actualizarFilaConsultaMp(nronota, r, btn.closest('tr'));
            } else {
                window.location.reload();
            }
        } catch (err) {
            const msCliente = Math.round(performance.now() - tInicio);
            const errRed = 'Error de red al consultar Mercado Público.' + sufijoTiempoRespuesta(null, msCliente);
            if (tieneFilaProgreso) {
                finalizarProgresoConsulta(nronota, false);
                const msg = feedbackRow(nronota)?.querySelector('.consulta-mp-mensaje');
                if (msg) {
                    msg.className = 'consulta-mp-mensaje small mt-1 text-danger';
                    msg.textContent = errRed;
                }
            } else if (window.AdminDialog) {
                AdminDialog.alert(errRed, { title: 'Consultar MP', type: 'danger' });
            } else {
                alert(errRed);
            }
            btn.disabled = false;
        }

        btn.dataset.consultando = '0';
    }

    document.addEventListener('click', async (ev) => {
        const btnConsultar = ev.target.closest('.btn-consultar-mp-individual');
        if (btnConsultar) {
            ev.preventDefault();
            ev.stopPropagation();
            const nronota = btnConsultar.dataset.nronota;
            if (nronota) {
                await consultarMercadoPublico(btnConsultar, nronota);
            }
            return;
        }

        const btnComp = ev.target.closest('.btn-comparar-mp');
        if (btnComp) {
            const nronota = btnComp.dataset.nronota;
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-comparar-mp'));
            const body = document.getElementById('modal-comparar-body');
            document.getElementById('modal-comparar-titulo').textContent = 'Comparar precios — Nota ' + nronota;
            body.innerHTML = '<p class="text-muted small">Cargando…</p>';
            modal.show();
            try {
                const res = await fetch(urlDetalle.replace('__NRO__', nronota), { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Error');

                const ofertas = data.ofertas || [];
                const ganador = ofertas.find(o => o.proveedor_seleccionado);
                const propio = ofertas.find(o => o.es_propio);
                const s = data.seguimiento;

                let html = `<p class="small mb-2"><strong>${s.codigo_proceso}</strong> · ${s.organismo || ''}<br>`;
                html += `Estado: ${s.estado_mp_glosa || s.estado_mp_codigo || '—'} · Monto total: ${fmtMonto(s.monto_total_ganador)}</p>`;

                if (!ganador && !propio) {
                    html += '<div class="alert alert-warning small py-2">No se encontraron ofertas del proveedor seleccionado ni de ' + cotizSistema + ' para esta nota.</div>';
                    body.innerHTML = html;
                    return;
                }

                html += '<div class="row gx-3 mb-2">';
                html += '<div class="col-md-6"><div class="border rounded p-2 h-100' + (ganador ? ' border-success' : '') + '">';
                html += '<p class="small fw-semibold mb-1 text-success"><i class="bi bi-trophy-fill"></i> Prov. seleccionado</p>';
                if (ganador) {
                    html += `<p class="small mb-0">${ganador.razon_social || '—'} <span class="text-muted">(${ganador.rut_proveedor || '—'})</span><br>Total: ${fmtMonto(ganador.monto_total)}</p>`;
                } else {
                    html += '<p class="small text-muted mb-0">Sin proveedor seleccionado</p>';
                }
                html += '</div></div>';
                html += '<div class="col-md-6"><div class="border rounded p-2 h-100' + (propio ? ' border-primary' : '') + '">';
                html += '<p class="small fw-semibold mb-1 text-primary"><i class="bi bi-building"></i> ' + cotizSistema + '</p>';
                if (propio) {
                    html += `<p class="small mb-0">${propio.razon_social || '—'} <span class="text-muted">(${propio.rut_proveedor || '—'})</span><br>Total: ${fmtMonto(propio.monto_total)}`;
                    if (propio.inadmisible) html += ' · <span class="text-danger">Inadmisible</span>';
                    html += '</p>';
                } else {
                    html += '<p class="small text-muted mb-0">No participó en esta nota</p>';
                }
                html += '</div></div></div>';

                const lineasGanador = ganador?.lineas || [];
                const lineasPropio = propio?.lineas || [];

                const propioMap = {};
                lineasPropio.forEach((l, i) => {
                    const key = l.codigo_producto || ('__idx__' + i);
                    propioMap[key] = l;
                });

                const maxLineas = Math.max(lineasGanador.length, lineasPropio.length);
                if (maxLineas === 0) {
                    html += '<p class="small text-muted">Sin detalle de productos para comparar.</p>';
                    body.innerHTML = html;
                    return;
                }

                html += '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">';
                html += '<thead class="table-light"><tr>';
                html += '<th>Producto</th><th>Cant.</th>';
                html += '<th class="text-end table-success">P.Unit. Prov. sel.</th>';
                html += '<th class="text-end table-primary">P.Unit. ' + cotizSistema + '</th>';
                html += '<th class="text-end">Diferencia</th>';
                html += '</tr></thead><tbody>';

                const matched = new Set();
                lineasGanador.forEach((lg, i) => {
                    const key = lg.codigo_producto || ('__idx__' + i);
                    const lp = propioMap[key] || lineasPropio[i] || null;
                    if (lp && lp.codigo_producto) matched.add(lp.codigo_producto);

                    const puG = lg.precio_unitario ?? null;
                    const puP = lp?.precio_unitario ?? null;
                    let diffHtml = '—';
                    if (puG !== null && puP !== null && puG > 0) {
                        const pct = ((puP - puG) / puG * 100).toFixed(1);
                        const num = parseFloat(pct);
                        const cls = num > 0 ? 'text-danger' : (num < 0 ? 'text-success' : 'text-muted');
                        diffHtml = `<span class="${cls}">${num > 0 ? '+' : ''}${pct}%</span>`;
                    }

                    html += '<tr>';
                    html += `<td class="small">${lg.descripcion || lg.codigo_producto || '—'}</td>`;
                    html += `<td>${lg.cantidad ?? '—'}</td>`;
                    html += `<td class="text-end">${puG !== null ? fmtMonto(puG) : '—'}</td>`;
                    html += `<td class="text-end">${puP !== null ? fmtMonto(puP) : '—'}</td>`;
                    html += `<td class="text-end">${diffHtml}</td>`;
                    html += '</tr>';
                });

                lineasPropio.forEach(lp => {
                    if (lp.codigo_producto && matched.has(lp.codigo_producto)) return;
                    if (!lp.codigo_producto) return;
                    html += '<tr>';
                    html += `<td class="small">${lp.descripcion || lp.codigo_producto || '—'}</td>`;
                    html += `<td>${lp.cantidad ?? '—'}</td>`;
                    html += `<td class="text-end text-muted">—</td>`;
                    html += `<td class="text-end">${fmtMonto(lp.precio_unitario)}</td>`;
                    html += `<td class="text-end">—</td>`;
                    html += '</tr>';
                });

                html += '</tbody></table></div>';
                body.innerHTML = html;
            } catch (e) {
                body.innerHTML = `<p class="text-danger small">${e.message}</p>`;
            }
            return;
        }

        const btn = ev.target.closest('.btn-detalle-mp');
        if (!btn) return;
        const nronota = btn.dataset.nronota;
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-detalle-mp'));
        const body = document.getElementById('modal-detalle-body');
        document.getElementById('modal-detalle-titulo').textContent = 'Nota ' + nronota;
        body.innerHTML = '<p class="text-muted small">Cargando…</p>';
        modal.show();
        try {
            const res = await fetch(urlDetalle.replace('__NRO__', nronota), { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Error');
            const s = data.seguimiento;
            let html = `<p class="small mb-2"><strong>${s.codigo_proceso}</strong> · ${s.estado_mp_glosa || s.estado_mp_codigo}<br>
                Prov. seleccionado: ${s.razon_social_ganador || '—'} ${s.rut_ganador ? '(' + s.rut_ganador + ')' : ''}<br>
                Seguimiento: ${({ cerrada: 'Cerrada', pendiente: 'Pendiente seguimiento', desierta: 'Desierta', cancelada: 'Cancelada' }[s.resultado_propio]) || s.resultado_propio || '—'} · Monto: ${fmtMonto(s.monto_total_ganador)}${s.id_orden_compra ? '<br>OC: <strong>' + s.id_orden_compra + '</strong>' : ''}</p>`;

            const tieneFechas = s.fecha_publicacion || s.fecha_cierre || s.fecha_ultimo_cambio || s.fecha_cancelacion;
            if (tieneFechas) {
                html += '<h3 class="h6 mb-1">Fechas Mercado Público</h3>';
                html += '<dl class="row small mb-2 gy-1">';
                html += `<dt class="col-sm-4 text-muted">Publicación</dt><dd class="col-sm-8 mb-0">${fmtFecha(s.fecha_publicacion)}</dd>`;
                html += `<dt class="col-sm-4 text-muted">Cierre</dt><dd class="col-sm-8 mb-0">${fmtFecha(s.fecha_cierre)}</dd>`;
                html += `<dt class="col-sm-4 text-muted">Últ. cambio</dt><dd class="col-sm-8 mb-0">${fmtFecha(s.fecha_ultimo_cambio)}</dd>`;
                if (s.fecha_cancelacion) {
                    html += `<dt class="col-sm-4 text-muted">Cancelación</dt><dd class="col-sm-8 mb-0">${fmtFecha(s.fecha_cancelacion)}</dd>`;
                }
                html += '</dl>';
            }

            const tieneConvocatoria = s.convocatoria_descripcion || s.fecha_cierre_primer_llamado || s.fecha_cierre_segundo_llamado || s.convocatoria_estado != null;
            if (tieneConvocatoria) {
                html += '<h3 class="h6 mb-1">Convocatoria</h3>';
                html += '<dl class="row small mb-2 gy-1">';
                if (s.convocatoria_descripcion) {
                    html += `<dt class="col-sm-4 text-muted">Descripción</dt><dd class="col-sm-8 mb-0">${s.convocatoria_descripcion}</dd>`;
                }
                if (s.convocatoria_estado != null && s.convocatoria_estado !== '') {
                    html += `<dt class="col-sm-4 text-muted">Estado</dt><dd class="col-sm-8 mb-0">${s.convocatoria_estado}</dd>`;
                }
                html += `<dt class="col-sm-4 text-muted">Cierre 1er llamado</dt><dd class="col-sm-8 mb-0">${fmtFecha(s.fecha_cierre_primer_llamado)}</dd>`;
                html += `<dt class="col-sm-4 text-muted">Cierre 2do llamado</dt><dd class="col-sm-8 mb-0">${fmtFecha(s.fecha_cierre_segundo_llamado)}</dd>`;
                html += '</dl>';
            }

            html += '<h3 class="h6">Ofertas recibidas</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Proveedor</th><th>RUT</th><th class="text-end">Monto</th><th></th></tr></thead><tbody>';
            (data.ofertas || []).forEach(o => {
                html += `<tr class="${o.proveedor_seleccionado ? 'table-success' : ''}${o.es_propio ? ' fw-semibold' : ''}">
                    <td>${o.razon_social || '—'}</td>
                    <td class="small">${o.rut_proveedor || '—'}</td>
                    <td class="text-end">${fmtMonto(o.monto_total)}</td>
                    <td class="small">${o.proveedor_seleccionado ? 'Seleccionado' : ''}${o.es_propio ? ' · Propio' : ''}${o.inadmisible ? ' · Inadm.' : ''}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            html += '<h3 class="h6 mt-3">Detalle por proveedor</h3>';
            (data.ofertas || []).forEach(o => {
                const badges = [
                    o.proveedor_seleccionado ? 'Seleccionado' : '',
                    o.es_propio ? 'Propio' : '',
                    o.inadmisible ? 'Inadmisible' : '',
                ].filter(Boolean).join(' · ');
                const rowClass = o.proveedor_seleccionado ? ' border border-success rounded p-2 mb-2' : ' border rounded p-2 mb-2';
                html += `<div class="${rowClass.trim()}">`;
                html += `<p class="small fw-semibold mb-1">${o.razon_social || '—'} <span class="text-muted fw-normal">(${o.rut_proveedor || '—'})</span>`;
                if (badges) {
                    html += ` · ${badges}`;
                }
                html += ` · Total: ${fmtMonto(o.monto_total)}</p>`;
                if (!o.lineas || !o.lineas.length) {
                    html += '<p class="small text-muted mb-0">Sin detalle de productos en MP.</p>';
                } else {
                    html += '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Cód. MP</th><th>Producto</th><th>Cant.</th><th class="text-end">P.unit.</th><th class="text-end">Total</th></tr></thead><tbody>';
                    o.lineas.forEach(l => {
                        html += `<tr><td class="small font-monospace">${l.codigo_producto || '—'}</td><td class="small">${l.descripcion || '—'}</td><td>${l.cantidad ?? '—'}</td><td class="text-end">${fmtMonto(l.precio_unitario)}</td><td class="text-end">${fmtMonto(l.monto_total)}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                }
                html += '</div>';
            });
            body.innerHTML = html;
        } catch (e) {
            body.innerHTML = `<p class="text-danger small">${e.message}</p>`;
        }
    });
})();
</script>
@endpush
