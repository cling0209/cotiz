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
            Código, cantidad y monto desde el detalle de la cotización; nombre desde el maestro de productos.
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
            <strong>Resumen:</strong> código y nombre del maestro, proveedor, cantidad y monto acumulados por producto.
            <strong>Detalle:</strong> número de nota, cotización, orden de compra (O.Compra), producto, cantidad, valor y total por línea.
        </p>

        <div class="d-none alert alert-success small py-2 mt-3 mb-0" id="reporte-pg-listo">
            <i class="bi bi-check-circle"></i>
            <span id="reporte-pg-listo-texto">CSV descargado.</span>
        </div>

        <div class="d-none alert alert-warning small py-2 mt-3 mb-0" id="reporte-pg-vacio"></div>

        <div class="d-none alert alert-danger small py-2 mt-3 mb-0" id="reporte-pg-error"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urlGenerar = @json(route('admin.compra-agil.resultados.reportes.productos-ganados.generar'));

    const form = document.getElementById('form-reporte-productos-ganados');
    const btnGenerar = document.getElementById('btn-reporte-pg-generar');
    const btnGenerarHtml = btnGenerar?.innerHTML || '';
    const labelDesde = document.getElementById('pg-label-desde');
    const labelHasta = document.getElementById('pg-label-hasta');
    const listo = document.getElementById('reporte-pg-listo');
    const listoTexto = document.getElementById('reporte-pg-listo-texto');
    const vacio = document.getElementById('reporte-pg-vacio');
    const errorBox = document.getElementById('reporte-pg-error');

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
    }

    function nombreDesdeContentDisposition(header) {
        if (!header) return 'productos_proveedor_seleccionado.csv';
        const utf8 = header.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8?.[1]) {
            try {
                return decodeURIComponent(utf8[1]);
            } catch (e) {
                return utf8[1];
            }
        }
        const simple = header.match(/filename="?([^";]+)"?/i);
        return simple?.[1] || 'productos_proveedor_seleccionado.csv';
    }

    function descargarBlob(blob, filename) {
        const objectUrl = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1000);
    }

    form?.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearFeedback();
        btnGenerar.disabled = true;
        btnGenerar.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Generando CSV…';

        const body = {
            fecha_desde: form.fecha_desde.value,
            fecha_hasta: form.fecha_hasta.value,
            tipo_fecha: tipoFechaSeleccionado(),
            ganador: form.ganador.value,
            formato: formatoSeleccionado(),
        };

        try {
            const res = await fetch(urlGenerar, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/csv, application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(body),
            });

            const contentType = res.headers.get('Content-Type') || '';
            if (!res.ok) {
                if (contentType.includes('application/json')) {
                    const data = await res.json().catch(() => ({}));
                    throw new Error(data.error || 'No se pudo generar el reporte.');
                }
                throw new Error('No se pudo generar el reporte.');
            }

            const csvText = await res.text();
            const filename = nombreDesdeContentDisposition(res.headers.get('Content-Disposition'));
            descargarBlob(new Blob([csvText], { type: 'text/csv;charset=utf-8' }), filename);

            const soloEncabezado = csvText.split(/\r?\n/).filter(function (linea) { return linea.trim() !== ''; }).length <= 1;
            listo.classList.remove('d-none');
            if (soloEncabezado) {
                vacio.textContent = 'No hay productos para los filtros aplicados (seguimiento Cerrada y proveedor Reicol/Romulo).';
                vacio.classList.remove('d-none');
                if (listoTexto) listoTexto.textContent = 'CSV generado (sin filas de datos):';
            } else if (listoTexto) {
                listoTexto.textContent = 'CSV descargado correctamente.';
            }
        } catch (err) {
            showError(err.message || 'Error al generar.');
        } finally {
            btnGenerar.disabled = false;
            btnGenerar.innerHTML = btnGenerarHtml;
        }
    });
})();
</script>
@endpush
