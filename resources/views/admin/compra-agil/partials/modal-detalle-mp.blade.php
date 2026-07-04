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

@push('scripts')
<script>
(function () {
    const urlDetalle = @json(url('/admin/compra-agil/resultados/detalle/__NRO__'));
    const fmtMonto = (n) => '$' + (Number(n) || 0).toLocaleString('es-CL');
    const fmtFecha = (iso) => {
        if (!iso) return '—';
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
    };

    document.addEventListener('click', async (ev) => {
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
                    html += '<div class="alert alert-warning small py-2">No se encontraron ofertas del ganador ni de Romulo para esta nota.</div>';
                    body.innerHTML = html;
                    return;
                }

                html += '<div class="row gx-3 mb-2">';
                html += '<div class="col-md-6"><div class="border rounded p-2 h-100' + (ganador ? ' border-success' : '') + '">';
                html += '<p class="small fw-semibold mb-1 text-success"><i class="bi bi-trophy-fill"></i> Ganador</p>';
                if (ganador) {
                    html += `<p class="small mb-0">${ganador.razon_social || '—'} <span class="text-muted">(${ganador.rut_proveedor || '—'})</span><br>Total: ${fmtMonto(ganador.monto_total)}</p>`;
                } else {
                    html += '<p class="small text-muted mb-0">Sin oferta ganadora</p>';
                }
                html += '</div></div>';
                html += '<div class="col-md-6"><div class="border rounded p-2 h-100' + (propio ? ' border-primary' : '') + '">';
                html += '<p class="small fw-semibold mb-1 text-primary"><i class="bi bi-building"></i> Romulo</p>';
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
                html += '<th class="text-end table-success">P.Unit. Ganador</th>';
                html += '<th class="text-end table-primary">P.Unit. Romulo</th>';
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
                Ganador: ${s.razon_social_ganador || '—'} ${s.rut_ganador ? '(' + s.rut_ganador + ')' : ''}<br>
                Seguimiento: ${({ cerrada: 'Cerrada', pendiente: 'Pendiente seguimiento', desierta: 'Desierta', cancelada: 'Cancelada' }[s.resultado_propio]) || s.resultado_propio || '—'} · Monto: ${fmtMonto(s.monto_total_ganador)}${s.id_orden_compra ? '<br>OC: <strong>' + s.id_orden_compra + '</strong>' : ''}</p>`;
            if (s.fecha_publicacion || s.fecha_cierre || s.fecha_ultimo_cambio || s.fecha_cancelacion) {
                html += `<p class="small text-muted mb-2">Publicación: ${fmtFecha(s.fecha_publicacion)} · Cierre: ${fmtFecha(s.fecha_cierre)} · Últ. cambio: ${fmtFecha(s.fecha_ultimo_cambio)}${s.fecha_cancelacion ? ' · Cancelación: ' + fmtFecha(s.fecha_cancelacion) : ''}</p>`;
            }
            html += '<h3 class="h6">Ofertas recibidas</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Proveedor</th><th>RUT</th><th class="text-end">Monto</th><th></th></tr></thead><tbody>';
            (data.ofertas || []).forEach(o => {
                html += `<tr class="${o.proveedor_seleccionado ? 'table-success' : ''}${o.es_propio ? ' fw-semibold' : ''}">
                    <td>${o.razon_social || '—'}</td>
                    <td class="small">${o.rut_proveedor || '—'}</td>
                    <td class="text-end">${fmtMonto(o.monto_total)}</td>
                    <td class="small">${o.proveedor_seleccionado ? 'Ganador' : ''}${o.es_propio ? ' · Propio' : ''}${o.inadmisible ? ' · Inadm.' : ''}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            html += '<h3 class="h6 mt-3">Detalle por proveedor</h3>';
            (data.ofertas || []).forEach(o => {
                const badges = [
                    o.proveedor_seleccionado ? 'Ganador' : '',
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
