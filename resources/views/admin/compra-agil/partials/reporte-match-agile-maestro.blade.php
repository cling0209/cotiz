<div class="card shadow-sm" id="reporte-match-agile-maestro">
    <div class="card-header py-2">
        <h2 class="h6 mb-0">
            <span class="badge text-bg-secondary me-1">2</span>
            Match Agile ↔ producto maestro
        </h2>
    </div>
    <div class="card-body py-3">
        <p class="small text-muted mb-3">
            Descarga de <code>agilemaeprod</code> con código maestro: código, descripción del maestro
            (<code>maeprod</code>) y descripción Agile (MP). Sin filtros.
        </p>
        <form id="form-reporte-match-agile" class="row g-2 align-items-end" data-no-loader>
            <div class="col-auto">
                <button type="submit" class="btn btn-success btn-sm" id="btn-reporte-ma-generar">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Descargar CSV
                </button>
            </div>
        </form>
        <p class="small text-muted mb-0 mt-2">
            Columnas: Código producto maestro, Descripción maestro, Producto Agile (MP).
            Solo filas con código de producto.
        </p>

        <div class="d-none mt-3" id="reporte-ma-progreso-wrap">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                <span class="small fw-semibold" id="reporte-ma-progreso-texto">Preparando…</span>
                <span class="small text-muted tabular-nums" id="reporte-ma-progreso-pct">0%</span>
            </div>
            <div class="progress" style="height: 1rem;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="reporte-ma-progreso-bar" role="progressbar" style="width: 0%">0%</div>
            </div>
        </div>

        <div class="d-none alert alert-success small py-2 mt-3 mb-0" id="reporte-ma-listo">
            <i class="bi bi-check-circle"></i>
            <span id="reporte-ma-listo-texto">Reporte listo:</span>
            <a href="#" id="reporte-ma-download-link" class="fw-semibold" download>Descargar CSV</a>
            <span class="text-muted"> (el enlace desaparece tras descargar)</span>
        </div>

        <div class="d-none alert alert-warning small py-2 mt-3 mb-0" id="reporte-ma-vacio"></div>
        <div class="d-none alert alert-danger small py-2 mt-3 mb-0" id="reporte-ma-error"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urls = {
        generar: @json(route('admin.compra-agil.resultados.reportes.match-agile-maestro.generar')),
        estado: @json(route('admin.compra-agil.resultados.reportes.exportaciones.estado', ['jobId' => '__JOB__'])),
    };

    const form = document.getElementById('form-reporte-match-agile');
    const btnGenerar = document.getElementById('btn-reporte-ma-generar');
    const wrapProgreso = document.getElementById('reporte-ma-progreso-wrap');
    const bar = document.getElementById('reporte-ma-progreso-bar');
    const texto = document.getElementById('reporte-ma-progreso-texto');
    const pctLabel = document.getElementById('reporte-ma-progreso-pct');
    const listo = document.getElementById('reporte-ma-listo');
    const listoTexto = document.getElementById('reporte-ma-listo-texto');
    const vacio = document.getElementById('reporte-ma-vacio');
    const downloadLink = document.getElementById('reporte-ma-download-link');
    const errorBox = document.getElementById('reporte-ma-error');

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
                    vacio.textContent = 'No hay registros en agilemaeprod.';
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

        try {
            const res = await fetch(urls.generar, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({}),
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
