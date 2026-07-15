@extends('layouts.admin')

@section('title', 'Oportunidades')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Oportunidades</h1>
            <p class="text-muted mb-0 small">
                Solo Compras &Aacute;giles <strong>publicadas hoy</strong>, seg&uacute;n sus palabras clave.
                Los resultados aparecen a medida que se consultan. Orden: presupuesto y cercan&iacute;a a Santiago.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-tags"></i> Palabras clave
            </a>
            <button type="button" id="btn-buscar-oportunidades" class="btn btn-primary btn-sm" @disabled($palabras === [])>
                <i class="bi bi-search"></i> Buscar cotizaciones
            </button>
            <button type="button" id="btn-cancelar-oportunidades" class="btn btn-outline-danger btn-sm d-none">
                <i class="bi bi-x-circle"></i> Cancelar
            </button>
        </div>
    </div>

    @if($palabras === [])
        <div class="alert alert-warning">
            No hay palabras clave configuradas.
            <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="alert-link">Agr&eacute;guelas aqu&iacute;</a>
            para poder buscar.
        </div>
    @else
        <div class="mb-3">
            <span class="small text-muted me-1">Palabras clave:</span>
            @foreach($palabras as $frase)
                <span class="badge text-bg-light border me-1">{{ $frase }}</span>
            @endforeach
        </div>
    @endif

    <div id="oportunidad-estado" class="card shadow-sm mb-3 d-none">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-3 align-items-center small mb-2">
                <div class="text-nowrap">
                    <i class="bi bi-clock"></i>
                    Inicio: <strong id="rel-inicio" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap">
                    <i class="bi bi-flag"></i>
                    Fin: <strong id="rel-fin" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap text-muted">
                    Duraci&oacute;n: <strong id="rel-duracion" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap ms-auto">
                    <span id="rel-encontradas" class="badge text-bg-primary">0</span>
                    <span class="text-muted">del d&iacute;a</span>
                    <span id="rel-fecha" class="text-muted ms-1"></span>
                </div>
            </div>
            <div class="progress mb-2" style="height: 0.75rem;">
                <div id="rel-progreso-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar" style="width: 0%">0%</div>
            </div>
            <div id="rel-detalle" class="small text-muted">Preparando consulta…</div>
            <div id="rel-error" class="alert alert-danger mt-2 mb-0 py-2 d-none"></div>
        </div>
    </div>

    <div id="oportunidad-placeholder" class="card shadow-sm @if($palabras === []) d-none @endif">
        <div class="card-body text-center py-5">
            <p class="text-danger fw-bold display-6 mb-3">
                NO USAR A&Uacute;N — EST&Aacute; EN DESARROLLO
            </p>
            <p class="text-muted mb-0">
                Pulse <strong>Buscar cotizaciones</strong> para consultar Mercado P&uacute;blico (solo publicadas hoy).
            </p>
        </div>
    </div>

    <div id="oportunidad-resultados" class="card shadow-sm d-none">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Cotizaci&oacute;n</th>
                        <th>Organismo</th>
                        <th>Fecha publicaci&oacute;n</th>
                        <th>Fecha cierre</th>
                        <th class="text-end">Presupuesto</th>
                    </tr>
                </thead>
                <tbody id="oportunidad-tbody">
                </tbody>
            </table>
        </div>
        <div class="card-body border-top py-2 small text-muted" id="oportunidad-footer">
            Haga clic en una fila para cotizarla.
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const urls = {
        iniciar: @json(route('admin.oportunidades.para-cotizar.iniciar')),
        paso: @json(route('admin.oportunidades.para-cotizar.paso')),
        cotizarBase: @json(url('/admin/cotizaciones/create')),
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const btn = document.getElementById('btn-buscar-oportunidades');
    const btnCancelar = document.getElementById('btn-cancelar-oportunidades');
    const estado = document.getElementById('oportunidad-estado');
    const placeholder = document.getElementById('oportunidad-placeholder');
    const resultados = document.getElementById('oportunidad-resultados');
    const tbody = document.getElementById('oportunidad-tbody');
    const footer = document.getElementById('oportunidad-footer');
    const relInicio = document.getElementById('rel-inicio');
    const relFin = document.getElementById('rel-fin');
    const relDuracion = document.getElementById('rel-duracion');
    const relEncontradas = document.getElementById('rel-encontradas');
    const relFecha = document.getElementById('rel-fecha');
    const relBar = document.getElementById('rel-progreso-bar');
    const relDetalle = document.getElementById('rel-detalle');
    const relError = document.getElementById('rel-error');

    /** @type {Map<string, object>} */
    let porCodigo = new Map();
    let inicioMs = null;
    let tickTimer = null;
    let buscando = false;
    let cancelado = false;
    /** @type {AbortController|null} */
    let abortCtrl = null;

    function setModoBusqueda(activo) {
        buscando = activo;
        if (btn) btn.disabled = activo || @json($palabras === []);
        if (btnCancelar) {
            btnCancelar.classList.toggle('d-none', !activo);
            btnCancelar.disabled = !activo;
        }
    }

    function horaAhora() {
        return new Date().toLocaleTimeString('es-CL', { hour12: false });
    }

    function fmtFecha(valor) {
        if (!valor) return '—';
        const d = new Date(valor);
        if (Number.isNaN(d.getTime())) return String(valor);
        const pad = (n) => String(n).padStart(2, '0');
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    function fmtMonto(monto) {
        const n = Number(monto) || 0;
        if (n <= 0) return '<span class="text-muted">—</span>';
        return '$' + n.toLocaleString('es-CL');
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function comparar(a, b) {
        const ma = Number(a.monto_presupuesto_clp) || 0;
        const mb = Number(b.monto_presupuesto_clp) || 0;
        if (ma !== mb) return mb - ma;
        return (Number(a.distancia_santiago) || 99) - (Number(b.distancia_santiago) || 99);
    }

    function bindFilas() {
        tbody.querySelectorAll('.oportunidad-fila[data-href]').forEach((row) => {
            const go = () => { window.location.href = row.getAttribute('data-href'); };
            row.addEventListener('click', go);
            row.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    go();
                }
            });
        });
    }

    function renderTabla() {
        const items = Array.from(porCodigo.values()).sort(comparar);
        relEncontradas.textContent = String(items.length);

        if (items.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">
                ${buscando ? 'Buscando…' : (cancelado ? 'Búsqueda cancelada. Sin resultados aún.' : 'No se encontraron compras publicadas hoy con esas palabras clave.')}
            </td></tr>`;
            footer.textContent = buscando ? 'Consulta en curso…' : (cancelado ? 'Consulta cancelada.' : 'Sin resultados del día.');
            return;
        }

        tbody.innerHTML = items.map((item) => {
            const codigo = String(item.codigo || '').toUpperCase();
            const href = codigo ? `${urls.cotizarBase}?codigo=${encodeURIComponent(codigo)}` : '';
            const nombre = item.nombre ? `<div class="small text-muted text-truncate" style="max-width:28rem;">${escapeHtml(item.nombre)}</div>` : '';
            const organismo = String(item.organismo || '').trim() || '—';
            const attrs = href
                ? ` class="oportunidad-fila" role="button" tabindex="0" data-href="${escapeHtml(href)}" title="Cotizar ${escapeHtml(codigo)}"`
                : '';
            return `<tr${attrs}>
                <td><code>${escapeHtml(codigo || '—')}</code>${nombre}</td>
                <td class="small"><div class="text-truncate" style="max-width:18rem;" title="${escapeHtml(organismo)}">${escapeHtml(organismo)}</div></td>
                <td class="small text-nowrap">${escapeHtml(fmtFecha(item.fecha_publicacion))}</td>
                <td class="small text-nowrap">${escapeHtml(fmtFecha(item.fecha_cierre))}</td>
                <td class="text-end tabular-nums text-nowrap">${fmtMonto(item.monto_presupuesto_clp)}</td>
            </tr>`;
        }).join('');

        const sufijo = cancelado ? ' (parcial, cancelada).' : ' del día.';
        footer.textContent = `${items.length} oportunidad${items.length === 1 ? '' : 'es'}${sufijo} Haga clic en una fila para cotizarla.`;
        bindFilas();
    }

    function setProgreso(pct) {
        const p = Math.max(0, Math.min(100, pct || 0));
        relBar.style.width = p + '%';
        relBar.textContent = p + '%';
    }

    function actualizarDuracion() {
        if (!inicioMs) {
            relDuracion.textContent = '—';
            return;
        }
        const segs = Math.max(0, Math.floor((Date.now() - inicioMs) / 1000));
        const m = Math.floor(segs / 60);
        const s = segs % 60;
        relDuracion.textContent = m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
    }

    function mostrarError(msg) {
        relError.textContent = msg || 'Error en la consulta.';
        relError.classList.remove('d-none');
    }

    function cancelarBusqueda() {
        if (!buscando) return;
        cancelado = true;
        if (abortCtrl) {
            abortCtrl.abort();
        }
        if (btnCancelar) btnCancelar.disabled = true;
        relDetalle.textContent = 'Cancelando…';
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            signal: abortCtrl ? abortCtrl.signal : undefined,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body || {}),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
            throw new Error(data.error || data.message || (`HTTP ${res.status}`));
        }
        return data;
    }

    async function buscar() {
        if (buscando || !btn || btn.disabled) return;
        cancelado = false;
        abortCtrl = new AbortController();
        setModoBusqueda(true);
        porCodigo = new Map();
        inicioMs = Date.now();
        if (tickTimer) clearInterval(tickTimer);
        tickTimer = setInterval(actualizarDuracion, 250);

        estado.classList.remove('d-none');
        placeholder.classList.add('d-none');
        resultados.classList.remove('d-none');
        relError.classList.add('d-none');
        relFin.textContent = '—';
        relInicio.textContent = '…';
        setProgreso(0);
        relBar.classList.add('progress-bar-animated');
        relDetalle.textContent = 'Iniciando consulta a Mercado Público…';
        renderTabla();

        try {
            const plan = await postJson(urls.iniciar, {});
            if (cancelado) throw new DOMException('Aborted', 'AbortError');

            relInicio.textContent = plan.inicio_label || horaAhora();
            if (plan.fecha) {
                relFecha.textContent = `(${plan.fecha})`;
            }
            const pasos = Array.isArray(plan.pasos) ? plan.pasos : [];
            const total = pasos.length;

            if (total === 0) {
                relFin.textContent = plan.inicio_label || '—';
                setProgreso(100);
                relBar.classList.remove('progress-bar-animated');
                relDetalle.textContent = 'Sin pasos que consultar.';
                renderTabla();
                return;
            }

            for (let i = 0; i < total; i++) {
                if (cancelado) break;

                const paso = pasos[i];
                relDetalle.textContent = `Consultando «${paso.frase}» · ${paso.region_nombre || ('Región ' + paso.region)} (${i + 1}/${total})`;
                const resp = await postJson(urls.paso, {
                    frase: paso.frase,
                    region: paso.region,
                    indice: i,
                    total_pasos: total,
                });

                if (cancelado) break;

                (resp.nuevos || []).forEach((item) => {
                    const codigo = String(item.codigo || '').toUpperCase();
                    if (!codigo) return;
                    if (!porCodigo.has(codigo)) {
                        porCodigo.set(codigo, item);
                    } else {
                        const prev = porCodigo.get(codigo);
                        const palabras = new Set([...(prev.palabras_coinciden || []), ...(item.palabras_coinciden || [])]);
                        prev.palabras_coinciden = Array.from(palabras);
                        porCodigo.set(codigo, prev);
                    }
                });

                setProgreso(resp.progreso ?? Math.round(((i + 1) / total) * 100));
                renderTabla();

                if (resp.terminado) {
                    relFin.textContent = resp.fin_label || horaAhora();
                }
            }

            if (relFin.textContent === '—') {
                relFin.textContent = horaAhora();
            }
            relBar.classList.remove('progress-bar-animated');

            if (cancelado) {
                relDetalle.textContent = `Consulta cancelada. ${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} encontradas hasta el momento.`;
            } else {
                setProgreso(100);
                relDetalle.textContent = `Consulta terminada. ${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} publicadas hoy.`;
            }
            renderTabla();
        } catch (e) {
            relBar.classList.remove('progress-bar-animated');
            if (relFin.textContent === '—') {
                relFin.textContent = horaAhora();
            }
            if (cancelado || e.name === 'AbortError') {
                cancelado = true;
                relDetalle.textContent = `Consulta cancelada. ${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} encontradas hasta el momento.`;
            } else {
                mostrarError(e.message || String(e));
                relDetalle.textContent = 'Consulta interrumpida.';
            }
            renderTabla();
        } finally {
            if (tickTimer) {
                clearInterval(tickTimer);
                tickTimer = null;
            }
            actualizarDuracion();
            abortCtrl = null;
            setModoBusqueda(false);
        }
    }

    btn?.addEventListener('click', buscar);
    btnCancelar?.addEventListener('click', cancelarBusqueda);
})();
</script>
@endpush
