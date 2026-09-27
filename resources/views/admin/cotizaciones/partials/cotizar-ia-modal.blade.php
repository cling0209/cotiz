{{-- «Cotizar con IA»: solo usuarios de CotizarIaService::USUARIOS_PERMITIDOS. --}}
<div class="modal fade" id="modal-cotizar-ia" tabindex="-1" aria-labelledby="modal-cotizar-ia-label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6" id="modal-cotizar-ia-label"><i class="bi bi-stars"></i> Cotizar con IA</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body py-2">
                <div id="cotizar-ia-cargando" class="text-center py-4 d-none" role="status">
                    <div class="spinner-border text-success" aria-hidden="true"></div>
                    <p class="small text-muted mt-2 mb-0">Analizando la cotizaci&oacute;n y sus adjuntos&hellip; puede tardar 1 a 2 minutos.</p>
                </div>
                <div id="cotizar-ia-error" class="alert alert-danger py-2 small d-none" role="alert"></div>
                <div id="cotizar-ia-resultado" class="d-none">
                    <div class="border rounded p-2 mb-2 small">
                        <strong>Productos tomados de:</strong> <span id="cotizar-ia-fuente" class="badge text-bg-dark"></span>
                        <div id="cotizar-ia-motivo" class="text-muted mt-1"></div>
                        <div id="cotizar-ia-adjuntos" class="text-muted mt-1"></div>
                    </div>
                    <div id="cotizar-ia-avisos" class="alert alert-warning py-2 small d-none" role="status"></div>
                    <p class="small mb-2" id="cotizar-ia-resumen"></p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle small mb-2">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-end" style="width: 3rem;">#</th>
                                    <th>Solicitado</th>
                                    <th class="text-end" style="width: 5rem;">Cant.</th>
                                    <th>V&iacute;nculo propuesto</th>
                                    <th class="text-end" style="width: 7rem;">Costo neto</th>
                                    <th class="text-center" style="width: 4rem;" title="Desmarque para dejar la l&iacute;nea pendiente sin v&iacute;nculo">Usar</th>
                                </tr>
                            </thead>
                            <tbody id="cotizar-ia-lineas"></tbody>
                        </table>
                    </div>
                    <div class="form-check small d-none" id="cotizar-ia-wrap-reemplazar">
                        <input class="form-check-input" type="checkbox" id="cotizar-ia-reemplazar" checked>
                        <label class="form-check-label" for="cotizar-ia-reemplazar" id="cotizar-ia-reemplazar-label"></label>
                    </div>
                    <p class="small text-muted mb-0 mt-1">
                        Las l&iacute;neas con referencia web quedan pendientes (NOK) con el costo neto y la URL en la observaci&oacute;n interna.
                        Los v&iacute;nculos por IA que agregue se guardan como aprendizaje.
                    </p>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-success btn-sm d-none" id="btn-cotizar-ia-aplicar">
                    <i class="bi bi-check2-circle"></i> Agregar a la cotizaci&oacute;n
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

    const urlPreview = @json(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota));
    const urlAplicar = @json(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota));
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
    };

    let token = null;
    let enCurso = false;

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

    function celdaVinculo(linea) {
        if (linea.estado === 'vinculado' && linea.producto) {
            const [txt, cls] = ORIGENES[linea.origen] || ['Maestro', 'text-bg-secondary'];
            return '<span class="badge ' + cls + ' me-1">' + esc(txt) + '</span>'
                + '<span class="font-monospace">' + esc(linea.producto.prod_item) + '</span> '
                + esc(linea.producto.prod_nombre);
        }
        if (linea.estado === 'referencia_web' && linea.referencia) {
            const ref = linea.referencia;
            const pack = ref.unidades_por_pack > 1 ? ' <span class="text-muted">(pack ' + esc(ref.unidades_por_pack) + ' un.)</span>' : '';
            return '<span class="badge text-bg-warning me-1">' + esc(ref.sitio) + '</span>'
                + esc(ref.titulo) + pack
                + ' <a href="' + esc(ref.url) + '" target="_blank" rel="noopener noreferrer">ver</a>'
                + '<div class="text-muted">$' + numero.format(ref.precio_clp) + ' c/IVA</div>';
        }
        return '<span class="badge text-bg-secondary">Sin v\u00ednculo</span>';
    }

    function costoLinea(linea) {
        if (linea.estado === 'vinculado' && linea.producto && linea.producto.costo > 0) {
            return '$' + numero.format(linea.producto.costo);
        }
        if (linea.estado === 'referencia_web' && linea.referencia) {
            return '$' + numero.format(linea.referencia.neto_unitario);
        }
        return '';
    }

    function pintar(data) {
        token = data.token;
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

        tbody.innerHTML = (data.lineas || []).map((linea) => {
            const conVinculo = linea.estado !== 'pendiente';
            const fuente = linea.fuente === 'adjunto' ? ' <span class="badge text-bg-light border">adjunto</span>' : '';
            return '<tr>'
                + '<td class="text-end tabular-nums">' + (linea.indice + 1) + '</td>'
                + '<td>' + esc(linea.descripcion) + fuente + '</td>'
                + '<td class="text-end tabular-nums">' + numero.format(linea.cantidad) + '</td>'
                + '<td>' + celdaVinculo(linea) + '</td>'
                + '<td class="text-end tabular-nums">' + costoLinea(linea) + '</td>'
                + '<td class="text-center">'
                + (conVinculo
                    ? '<input type="checkbox" class="form-check-input cotizar-ia-usar" data-indice="' + linea.indice + '" checked aria-label="Usar v\u00ednculo de la l\u00ednea ' + (linea.indice + 1) + '">'
                    : '')
                + '</td>'
                + '</tr>';
        }).join('');

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
        enCurso = true;
        token = null;
        errorBox.classList.add('d-none');
        resultado.classList.add('d-none');
        btnAplicar.classList.add('d-none');
        modal.show();
        estado(true);
        try {
            pintar(await postJson(urlPreview));
        } catch (e) {
            mostrarError(e.message || 'No se pudo cotizar con IA.');
        } finally {
            estado(false);
            enCurso = false;
        }
    });

    btnAplicar.addEventListener('click', async () => {
        if (!token || enCurso) {
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
        try {
            await postJson(urlAplicar, { token, rechazados, reemplazar });
            window.location.reload();
        } catch (e) {
            mostrarError(e.message || 'No se pudieron agregar las l\u00edneas.');
            btnAplicar.disabled = false;
            enCurso = false;
        }
    });
})();
</script>
@endpush
