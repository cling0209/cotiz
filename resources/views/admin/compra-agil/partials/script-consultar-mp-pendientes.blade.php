<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urlTpl = @json(route('admin.compra-agil.resultados.consultar-individual', ['nronota' => '__NRO__']));

    const segLabels = {
        cerrada: 'Cerrada',
        pendiente: 'Pendiente seguimiento',
        desierta: 'Desierta',
        cancelada: 'Cancelada',
        no_encontrada: 'No existe en MP',
    };

    function labelSeguimiento(codigo) {
        return segLabels[codigo] || codigo || '—';
    }

    function fmtGlosa(codigo, glosa) {
        return glosa || codigo || '—';
    }

    function feedbackRow(nronota) {
        return document.querySelector('tr.consulta-mp-feedback[data-nronota="' + nronota + '"]');
    }

    function dataRow(nronota) {
        return document.querySelector('tr.pendiente-data-row[data-nronota="' + nronota + '"]');
    }

    function mostrarProgreso(nronota) {
        const fb = feedbackRow(nronota);
        if (!fb) return;
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
    }

    function finalizarProgreso(nronota, ok) {
        const fb = feedbackRow(nronota);
        if (!fb) return;
        const bar = fb.querySelector('.consulta-mp-progress-bar');
        if (bar) {
            bar.style.width = '100%';
            bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
            bar.classList.add(ok ? 'bg-success' : 'bg-danger');
        }
    }

    function mensajeResultado(r) {
        const antGlosa = fmtGlosa(r.estado_anterior, r.estado_anterior_glosa);
        const nueGlosa = fmtGlosa(r.estado_nuevo, r.estado_glosa);
        const antSeg = labelSeguimiento(r.resultado_anterior);
        const nueSeg = labelSeguimiento(r.resultado_propio);

        if (r.cambio) {
            let txt = 'Cambio detectado: ' + antGlosa + ' → ' + nueGlosa;
            if (r.resultado_anterior !== r.resultado_propio) {
                txt += ' · Seguimiento: ' + antSeg + ' → ' + nueSeg;
            }
            return { ok: true, text: txt };
        }

        return { ok: true, text: 'Sin cambios de estado (' + nueGlosa + ' · ' + nueSeg + ')' };
    }

    function actualizarFila(nronota, r) {
        const row = dataRow(nronota);
        if (!row) return;

        const estadoCell = row.querySelector('.cell-estado-mp');
        if (estadoCell) {
            estadoCell.textContent = r.estado_glosa || r.estado_nuevo || '—';
        }

        const provCell = row.querySelector('.cell-proveedor');
        if (provCell && r.razon_social_ganador) {
            provCell.textContent = r.razon_social_ganador.length > 30
                ? r.razon_social_ganador.substring(0, 30) + '…'
                : r.razon_social_ganador;
        }

        const montoCell = row.querySelector('.cell-monto');
        if (montoCell && r.monto_total_ganador) {
            montoCell.textContent = '$' + Number(r.monto_total_ganador).toLocaleString('es-CL');
        }

        const consultadoCell = row.querySelector('.cell-consultado');
        if (consultadoCell) {
            const now = new Date();
            consultadoCell.textContent = now.toLocaleDateString('es-CL') + ' ' + now.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
        }

        const btnConsultar = row.querySelector('.btn-consultar-mp-individual');
        if (btnConsultar && (r.finalizado || r.resultado_propio !== 'pendiente')) {
            btnConsultar.remove();
        }
    }

    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.btn-consultar-mp-individual');
        if (!btn || btn.disabled) {
            return;
        }

        const nronota = btn.dataset.nronota;
        if (!nronota) {
            return;
        }

        btn.disabled = true;
        mostrarProgreso(nronota);

        try {
            const res = await fetch(urlTpl.replace('__NRO__', nronota), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            const data = await res.json().catch(function () { return {}; });

            if (!res.ok) {
                finalizarProgreso(nronota, false);
                const fb = feedbackRow(nronota);
                const msg = fb?.querySelector('.consulta-mp-mensaje');
                if (msg) {
                    msg.className = 'consulta-mp-mensaje small mt-1 text-danger';
                    msg.textContent = data.error || 'Error al consultar Mercado Público.';
                }
                btn.disabled = false;
                return;
            }

            finalizarProgreso(nronota, true);
            const r = data.resultado || {};
            const info = mensajeResultado(r);
            const fb = feedbackRow(nronota);
            const msg = fb?.querySelector('.consulta-mp-mensaje');
            if (msg) {
                msg.className = 'consulta-mp-mensaje small mt-1 fw-semibold ' + (r.cambio ? 'text-success' : 'text-secondary');
                msg.textContent = info.text;
            }

            actualizarFila(nronota, r);

            if (r.cambio) {
                dataRow(nronota)?.classList.add('table-info');
            }
        } catch (err) {
            finalizarProgreso(nronota, false);
            const fb = feedbackRow(nronota);
            const msg = fb?.querySelector('.consulta-mp-mensaje');
            if (msg) {
                msg.className = 'consulta-mp-mensaje small mt-1 text-danger';
                msg.textContent = 'Error de red al consultar Mercado Público.';
            }
            btn.disabled = false;
        }
    });
})();
</script>
