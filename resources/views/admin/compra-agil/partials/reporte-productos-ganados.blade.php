<div class="card shadow-sm" id="reporte-productos-ganados">
    <div class="card-header py-2">
        <h2 class="h6 mb-0">
            <span class="badge text-bg-secondary me-1">1</span>
            Productos proveedor seleccionado Reicol / Romulo
        </h2>
    </div>
    <div class="card-body py-3">
        <p class="small text-muted mb-3">
            Productos de procesos con seguimiento <strong>Cerrada</strong> y proveedor Reicol/Romulo.
            Código, cantidad y monto desde el detalle de la cotización; nombre del maestro y descripción Agile (MP) de la línea.
            Filtre por fecha de publicación o de cierre del proceso.
        </p>
        <form id="form-reporte-productos-ganados" class="row g-2 align-items-end" data-no-loader>
            <div class="col-12 col-md-auto">
                <span class="form-label small mb-1 d-block">Filtrar por</span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Tipo de fecha">
                    <input type="radio" class="btn-check" name="tipo_fecha" id="pg-tipo-cierre" value="cierre" checked>
                    <label class="btn btn-outline-secondary" for="pg-tipo-cierre">Cierre</label>
                    <input type="radio" class="btn-check" name="tipo_fecha" id="pg-tipo-publicacion" value="publicacion">
                    <label class="btn btn-outline-secondary" for="pg-tipo-publicacion">Publicación</label>
                </div>
            </div>
            <div class="col-auto">
                <label for="pg-fecha-desde" class="form-label small mb-0" id="pg-label-desde">Cierre desde</label>
                <input type="date" class="form-control form-control-sm" id="pg-fecha-desde" name="fecha_desde" required>
            </div>
            <div class="col-auto">
                <label for="pg-fecha-hasta" class="form-label small mb-0" id="pg-label-hasta">Cierre hasta</label>
                <input type="date" class="form-control form-control-sm" id="pg-fecha-hasta" name="fecha_hasta" required>
            </div>
            <div class="col-auto">
                <label for="pg-ganador" class="form-label small mb-0">Proveedor seleccionado</label>
                <select class="form-select form-select-sm" id="pg-ganador" name="ganador" style="width:9rem">
                    <option value="ambos" selected>Ambos</option>
                    <option value="reicol">Reicol</option>
                    <option value="romulo">Romulo</option>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <span class="form-label small mb-1 d-block">Formato</span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Formato del reporte">
                    <input type="radio" class="btn-check" name="formato" id="pg-formato-resumen" value="resumen" checked>
                    <label class="btn btn-outline-secondary" for="pg-formato-resumen">Resumen</label>
                    <input type="radio" class="btn-check" name="formato" id="pg-formato-detalle" value="detalle">
                    <label class="btn btn-outline-secondary" for="pg-formato-detalle">Detalle</label>
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-success btn-sm" id="btn-reporte-pg-generar">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Generar CSV
                </button>
            </div>
        </form>
        <p class="small text-muted mb-0 mt-2" id="pg-columnas-desc">
            <strong>Resumen:</strong> código, nombre del maestro, producto Agile (MP), proveedor, cantidad y monto acumulados por producto.
            <strong>Detalle:</strong> número de nota, cotización, orden de compra (O.Compra), producto maestro, producto Agile (MP), cantidad, valor y total por línea.
        </p>

        <div class="d-none mt-3" id="reporte-pg-progreso-wrap">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                <span class="small fw-semibold" id="reporte-pg-progreso-texto">Preparando…</span>
                <span class="small text-muted tabular-nums" id="reporte-pg-progreso-pct">0%</span>
            </div>
            <div class="progress" style="height: 1rem;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="reporte-pg-progreso-bar" role="progressbar" style="width: 0%">0%</div>
            </div>
        </div>

        <div class="d-none alert alert-success small py-2 mt-3 mb-0" id="reporte-pg-listo">
            <i class="bi bi-check-circle"></i>
            <span id="reporte-pg-listo-texto">Reporte listo:</span>
            <a href="#" id="reporte-pg-download-link" class="fw-semibold" download>Descargar CSV</a>
            <span class="text-muted"> (el enlace desaparece tras descargar)</span>
        </div>

        <div class="d-none alert alert-warning small py-2 mt-3 mb-0" id="reporte-pg-vacio"></div>

        <div class="d-none alert alert-danger small py-2 mt-3 mb-0" id="reporte-pg-error"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urls = {
        generar: @json(route('admin.compra-agil.resultados.reportes.productos-ganados.generar')),
        estado: @json(route('admin.compra-agil.resultados.reportes.exportaciones.estado', ['jobId' => '__JOB__'])),
    };

    const form = document.getElementById('form-reporte-productos-ganados');
    const btnGenerar = document.getElementById('btn-reporte-pg-generar');
    const labelDesde = document.getElementById('pg-label-desde');
    const labelHasta = document.getElementById('pg-label-hasta');

    function tipoFechaSeleccionado() {
        return form?.querySelector('input[name="tipo_fecha"]:checked')?.value || 'cierre';
    }

    function formatoSeleccionado() {
        return form?.querySelector('input[name="formato"]:checked')?.value || 'resumen';
    }

    function actualizarEtiquetasFecha() {
        const esCierre = tipoFechaSeleccionado() === 'cierre';
        const prefijo = esCierre ? 'Cierre' : 'Publicación';
        if (labelDesde) labelDesde.textContent = prefijo + ' desde';
        if (labelHasta) labelHasta.textContent = prefijo + ' hasta';
    }

    form?.querySelectorAll('input[name="tipo_fecha"]').forEach(function (radio) {
        radio.addEventListener('change', actualizarEtiquetasFecha);
    });
    actualizarEtiquetasFecha();
    const wrapProgreso = document.getElementById('reporte-pg-progreso-wrap');
    const bar = document.getElementById('reporte-pg-progreso-bar');
    const texto = document.getElementById('reporte-pg-progreso-texto');
    const pctLabel = document.getElementById('reporte-pg-progreso-pct');
    const listo = document.getElementById('reporte-pg-listo');
    const listoTexto = document.getElementById('reporte-pg-listo-texto');
    const vacio = document.getElementById('reporte-pg-vacio');
    const downloadLink = document.getElementById('reporte-pg-download-link');
    const errorBox = document.getElementById('reporte-pg-error');

    let pollTimer = null;
    let currentJobId = null;

    function estadoUrl(jobId) {
        return urls.estado.replace('__JOB__', encodeURIComponent(jobId));
    }

    function setProgress(percent, detail) {
        const pct = Math.max(0, Math.min(100, Number(percent) || 0));
        wrapProgreso.classList.remove('d-none');
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        pctLabel.textContent = pct + '%';
        texto.textContent = detail || 'Generando reporte…';
        bar.classList.toggle('progress-bar-animated', pct < 100);
        bar.classList.toggle('bg-success', pct >= 100);
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
        listo.classList.add('d-none');
        vacio.classList.add('d-none');
    }

    function clearFeedback() {
        errorBox.classList.add('d-none');
        errorBox.textContent = '';
        listo.classList.add('d-none');
        vacio.classList.add('d-none');
        vacio.textContent = '';
        wrapProgreso.classList.add('d-none');
        bar.classList.remove('bg-success');
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    async function pollEstado(jobId) {
        try {
            const res = await fetch(estadoUrl(jobId), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.error || ('No se pudo consultar el progreso (HTTP ' + res.status + ').'));
            }

            setProgress(data.percent, data.detail);

            if (data.status === 'completed' && data.download_url) {
                stopPolling();
                btnGenerar.disabled = false;
                if (window.CotizRenderKeepAlive) {
                    window.CotizRenderKeepAlive.stop();
                }
                downloadLink.href = data.download_url;
                downloadLink.textContent = data.filename || 'Descargar CSV';
                listo.classList.remove('d-none');
                if ((data.row_count ?? 0) === 0) {
                    vacio.textContent = 'No hay productos para los filtros aplicados (seguimiento Cerrada y proveedor Reicol/Romulo).';
                    vacio.classList.remove('d-none');
                    if (listoTexto) listoTexto.textContent = 'CSV generado (sin filas):';
                } else if (listoTexto) {
                    listoTexto.textContent = 'Reporte listo:';
                }
                downloadLink.addEventListener('click', function onDownload() {
                    setTimeout(function () {
                        listo.classList.add('d-none');
                        vacio.classList.add('d-none');
                        wrapProgreso.classList.add('d-none');
                        currentJobId = null;
                    }, 1500);
                }, { once: true });
                return;
            }

            if (data.status === 'failed') {
                stopPolling();
                btnGenerar.disabled = false;
                if (window.CotizRenderKeepAlive) {
                    window.CotizRenderKeepAlive.stop();
                }
                wrapProgreso.classList.add('d-none');
                showError(data.error || 'Error al generar el reporte.');
            }
        } catch (e) {
            stopPolling();
            btnGenerar.disabled = false;
            if (window.CotizRenderKeepAlive) {
                window.CotizRenderKeepAlive.stop();
            }
            wrapProgreso.classList.add('d-none');
            showError(e.message || 'Error de conexión.');
        }
    }

    form?.addEventListener('submit', async function (e) {
        e.preventDefault();
        stopPolling();
        clearFeedback();
        btnGenerar.disabled = true;
        setProgress(0, 'Generando reporte…');
        if (window.CotizRenderKeepAlive) {
            window.CotizRenderKeepAlive.start();
        }

        const body = {
            fecha_desde: form.fecha_desde.value,
            fecha_hasta: form.fecha_hasta.value,
            tipo_fecha: tipoFechaSeleccionado(),
            ganador: form.ganador.value,
            formato: formatoSeleccionado(),
        };

        try {
            const res = await fetch(urls.generar, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.error || 'No se pudo iniciar la exportación.');
            }

            currentJobId = data.job_id;
            if (data.estado) {
                setProgress(data.estado.percent, data.estado.detail);
                if (data.estado.status === 'completed' && data.estado.download_url) {
                    await pollEstado(currentJobId);
                    if (window.CotizRenderKeepAlive) {
                        window.CotizRenderKeepAlive.stop();
                    }
                    return;
                }
            }

            pollTimer = setInterval(function () {
                pollEstado(currentJobId);
            }, 1200);
            pollEstado(currentJobId);
        } catch (err) {
            btnGenerar.disabled = false;
            wrapProgreso.classList.add('d-none');
            showError(err.message || 'Error al generar.');
            if (window.CotizRenderKeepAlive) {
                window.CotizRenderKeepAlive.stop();
            }
        }
    });
})();
</script>
@endpush
