@extends('layouts.admin')

@section('title', 'Oportunidades')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Oportunidades</h1>
            @if($puedeBuscar)
                <p class="text-muted mb-0 small">
                    Solo Compras &Aacute;giles <strong>publicadas hoy</strong>, seg&uacute;n sus palabras clave.
                    Lo que se va encontrando se <strong>graba</strong> y se sincroniza con el sitio par.
                    B&uacute;squeda por prioridad: primero las regiones de <code>MERCADOPUBLICO_REGIONES</code>,
                    y dentro de cada regi&oacute;n las palabras clave en el orden configurado.
                </p>
            @else
                <p class="text-muted mb-0 small">
                    Listado de Compras &Aacute;giles del d&iacute;a sincronizadas desde el sitio que realiza la b&uacute;squeda.
                    En este sitio no se buscan ni se administran palabras clave.
                </p>
            @endif
        </div>
        @if($puedeBuscar)
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
        @endif
    </div>

    @if($puedeBuscar)
        @if($palabras === [])
            <div class="alert alert-warning">
                No hay palabras clave configuradas.
                <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="alert-link">Agr&eacute;guelas aqu&iacute;</a>
                para poder buscar.
            </div>
        @else
            <div class="mb-3">
                <div class="small text-muted mb-1">
                    Palabras clave (prioridad de b&uacute;squeda, de izquierda a derecha):
                    <a href="{{ route('admin.oportunidades.palabras-clave.index') }}">cambiar orden</a>
                </div>
                @foreach($palabras as $i => $frase)
                    <span class="badge text-bg-light border me-1" title="Prioridad {{ $i + 1 }}">
                        <span class="text-muted">{{ $i + 1 }}.</span> {{ $frase }}
                    </span>
                @endforeach
            </div>
        @endif
    @elseif(count($guardadas) === 0)
        <div class="alert alert-info">
            A&uacute;n no hay oportunidades sincronizadas para hoy. Cuando el sitio de b&uacute;squeda encuentre cotizaciones, aparecer&aacute;n aqu&iacute;.
        </div>
    @endif

    @if($puedeBuscar)
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

    <div id="oportunidad-placeholder" class="card shadow-sm @if(! $puedeBuscar || $palabras === [] || count($guardadas) > 0) d-none @endif">
        <div class="card-body text-center text-muted py-5">
            Pulse <strong>Buscar cotizaciones</strong> para consultar Mercado P&uacute;blico (solo publicadas hoy).
            Cada resultado se graba autom&aacute;ticamente.
        </div>
    </div>
    @endif

    <div id="oportunidad-resultados" class="card shadow-sm @if(count($guardadas) === 0) d-none @endif">
        <div class="card-body border-bottom py-2">
            <div class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4 col-lg-3">
                    <label for="filtro-region" class="form-label small mb-1">Regi&oacute;n</label>
                    <select id="filtro-region" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        @foreach(($regionesFiltro ?? []) as $codigoRegion => $nombreRegion)
                            <option value="{{ (int) $codigoRegion }}">{{ $nombreRegion }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-5 col-lg-4">
                    <label for="filtro-organismo" class="form-label small mb-1">Organismo</label>
                    <input type="search" id="filtro-organismo" class="form-control form-control-sm"
                           placeholder="Buscar organismo…" autocomplete="off">
                </div>
                <div class="col-sm-12 col-md-3 col-lg-5 d-flex flex-wrap gap-2 justify-content-md-end align-items-end">
                    <button type="button" id="btn-descargar-csv" class="btn btn-outline-success btn-sm" data-no-loader>
                        <i class="bi bi-download"></i> Descargar CSV
                    </button>
                </div>
            </div>
            <p class="small text-muted mb-0 mt-2">
                Orden por defecto: presupuesto de mayor a menor; a igual presupuesto, menos productos primero.
                El CSV exporta exactamente lo visible con los filtros actuales.
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Cotizaci&oacute;n</th>
                        <th>Regi&oacute;n</th>
                        <th>Palabra clave</th>
                        <th data-sort-header="organismo">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold" data-sort="organismo">
                                Organismo <span class="sort-indicator text-muted" aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th class="text-center">Productos</th>
                        <th data-sort-header="fecha_publicacion">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold" data-sort="fecha_publicacion">
                                Fecha publicaci&oacute;n <span class="sort-indicator text-muted" aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th data-sort-header="fecha_cierre">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold" data-sort="fecha_cierre">
                                Fecha cierre <span class="sort-indicator text-muted" aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th class="text-end" data-sort-header="presupuesto">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold" data-sort="presupuesto">
                                Presupuesto <span class="sort-indicator text-muted" aria-hidden="true">↕</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody id="oportunidad-tbody">
                </tbody>
            </table>
        </div>
        <div class="card-body border-top py-2 small text-muted" id="oportunidad-footer">
            Haga clic en una fila para cotizarla.
            @if($puedeBuscar)
                Los resultados del d&iacute;a quedan grabados y se sincronizan con el sitio par.
            @else
                Resultados sincronizados desde el sitio de b&uacute;squeda.
            @endif
        </div>
    </div>

    @if($puedeBuscar)
    <div id="oportunidad-debug" class="card shadow-sm mt-3 d-none">
        <div class="card-header py-2 small fw-semibold bg-light">
            <i class="bi bi-braces"></i> Consulta Mercado P&uacute;blico &mdash; endpoint y par&aacute;metros
        </div>
        <div class="card-body py-3">
            <div id="debug-endpoint-line" class="small mb-2">
                <span class="text-muted">URL:</span>
                <code id="debug-endpoint-url" class="user-select-all text-break"></code>
            </div>
            <div id="debug-paso-line" class="small text-muted mb-2"></div>
            <pre id="debug-consulta-json" class="bg-light border rounded p-3 mb-0 small font-monospace text-break"
                 style="max-height:20rem;overflow:auto;white-space:pre-wrap;"></pre>
        </div>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    const puedeBuscar = @json((bool) $puedeBuscar);
    const urls = {
        iniciar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.iniciar') : ''),
        estado: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.estado') : ''),
        cancelar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.cancelar') : ''),
        cotizarBase: @json(url('/admin/cotizaciones/create')),
    };
    const mpApi = {
        baseUrl: @json($mpBaseUrl ?? ''),
        path: @json($mpPath ?? '/v2/compra-agil'),
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
    const debugPanel = document.getElementById('oportunidad-debug');
    const debugEndpointUrl = document.getElementById('debug-endpoint-url');
    const debugPasoLine = document.getElementById('debug-paso-line');
    const debugConsultaJson = document.getElementById('debug-consulta-json');
    const filtroRegion = document.getElementById('filtro-region');
    const filtroOrganismo = document.getElementById('filtro-organismo');
    const btnDescargarCsv = document.getElementById('btn-descargar-csv');

    /** @type {Map<string, object>} */
    let porCodigo = new Map();
    let inicioMs = null;
    let finMs = null;
    let tickTimer = null;
    let buscando = false;
    let cancelado = false;
    let pollTimer = null;
    let sortState = { column: 'presupuesto', direction: 'desc' };
    let filtroOrganismoTimer = null;

    const guardadasIniciales = @json($guardadas ?? []);
    const fechaBusquedaInicial = @json($fechaBusqueda ?? null);
    const corridaInicial = @json($corridaEstado ?? null);

    function cargarItems(items) {
        (items || []).forEach((item) => {
            const codigo = String(item.codigo || '').toUpperCase();
            if (!codigo || porCodigo.has(codigo)) return;
            porCodigo.set(codigo, item);
        });
    }

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

    function valorOrden(item, column) {
        if (column === 'organismo') {
            return String(item.organismo || '').trim();
        }
        if (column === 'fecha_publicacion') {
            const d = new Date(item.fecha_publicacion || '');
            return Number.isNaN(d.getTime()) ? 0 : d.getTime();
        }
        if (column === 'fecha_cierre') {
            const d = new Date(item.fecha_cierre || '');
            return Number.isNaN(d.getTime()) ? 0 : d.getTime();
        }
        return Number(item.monto_presupuesto_clp) || 0;
    }

    function comparar(a, b) {
        const direction = sortState.direction === 'asc' ? 1 : -1;
        const column = sortState.column;
        const va = valorOrden(a, column);
        const vb = valorOrden(b, column);

        if (typeof va === 'string' || typeof vb === 'string') {
            const texto = String(va).localeCompare(String(vb), 'es', { sensitivity: 'base' });
            if (texto !== 0) return texto * direction;
        } else if (va !== vb) {
            return (va - vb) * direction;
        }

        const ma = Number(a.monto_presupuesto_clp) || 0;
        const mb = Number(b.monto_presupuesto_clp) || 0;
        if (ma !== mb) return mb - ma;
        const tieneA = a.cantidad_productos != null && a.cantidad_productos !== '';
        const tieneB = b.cantidad_productos != null && b.cantidad_productos !== '';
        const pa = tieneA ? Number(a.cantidad_productos) : Number.MAX_SAFE_INTEGER;
        const pb = tieneB ? Number(b.cantidad_productos) : Number.MAX_SAFE_INTEGER;
        const na = Number.isFinite(pa) ? pa : Number.MAX_SAFE_INTEGER;
        const nb = Number.isFinite(pb) ? pb : Number.MAX_SAFE_INTEGER;
        if (na !== nb) return na - nb;
        return String(a.codigo || '').localeCompare(String(b.codigo || ''), 'es');
    }

    function actualizarIndicadoresOrden() {
        document.querySelectorAll('[data-sort-header]').forEach((th) => {
            const column = th.getAttribute('data-sort-header');
            const activo = column === sortState.column;
            th.setAttribute('aria-sort', activo ? (sortState.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            const indicator = th.querySelector('.sort-indicator');
            if (indicator) {
                indicator.textContent = activo ? (sortState.direction === 'asc' ? '↑' : '↓') : '↕';
            }
        });
    }

    function normalizarTexto(valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function itemsFiltrados() {
        const regionSel = filtroRegion ? String(filtroRegion.value || '').trim() : '';
        const organismoSel = filtroOrganismo ? normalizarTexto(filtroOrganismo.value) : '';
        let items = Array.from(porCodigo.values());
        if (regionSel !== '') {
            const codigoRegion = Number(regionSel);
            items = items.filter((item) => Number(item.region) === codigoRegion);
        }
        if (organismoSel !== '') {
            items = items.filter((item) => normalizarTexto(item.organismo).includes(organismoSel));
        }
        return items.sort(comparar);
    }

    function csvEscape(valor) {
        const texto = String(valor ?? '');
        if (/[;"\r\n]/.test(texto)) {
            return '"' + texto.replace(/"/g, '""') + '"';
        }
        return texto;
    }

    function descargarCsv() {
        const items = itemsFiltrados();
        if (items.length === 0) {
            return;
        }

        const headers = [
            'Cotizacion',
            'Nombre',
            'Region',
            'Palabra clave',
            'Organismo',
            'Productos',
            'Fecha publicacion',
            'Fecha cierre',
            'Presupuesto CLP',
        ];
        const rows = items.map((item) => {
            const frases = Array.isArray(item.palabras_coinciden)
                ? item.palabras_coinciden.map((f) => String(f || '').trim()).filter(Boolean).join(' | ')
                : '';
            const tieneCantidad = item.cantidad_productos != null && item.cantidad_productos !== '';
            const cantidadNum = tieneCantidad ? Number(item.cantidad_productos) : '';
            return [
                String(item.codigo || '').toUpperCase(),
                String(item.nombre || '').trim(),
                String(item.nombre_region || '').trim(),
                frases,
                String(item.organismo || '').trim(),
                Number.isFinite(cantidadNum) ? cantidadNum : '',
                fmtFecha(item.fecha_publicacion),
                fmtFecha(item.fecha_cierre),
                Number(item.monto_presupuesto_clp) || 0,
            ].map(csvEscape).join(';');
        });

        const contenido = '\uFEFF' + [headers.join(';'), ...rows].join('\r\n');
        const blob = new Blob([contenido], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const fecha = (fechaBusquedaInicial || new Date().toISOString().slice(0, 10)).replace(/-/g, '');
        a.href = url;
        a.download = `oportunidades_${fecha}.csv`;
        a.setAttribute('data-no-loader', '');
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
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
        const total = porCodigo.size;
        const items = itemsFiltrados();
        actualizarIndicadoresOrden();
        if (relEncontradas) relEncontradas.textContent = String(total);

        if (total === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-4">
                ${buscando ? 'Buscando…' : (cancelado ? 'Búsqueda cancelada. Sin resultados aún.' : 'No se encontraron compras publicadas hoy con esas palabras clave.')}
            </td></tr>`;
            footer.textContent = buscando ? 'Consulta en curso…' : (cancelado ? 'Consulta cancelada.' : 'Sin resultados del día.');
            if (btnDescargarCsv) btnDescargarCsv.disabled = true;
            return;
        }

        if (items.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-4">
                No hay oportunidades con los filtros seleccionados.
            </td></tr>`;
            footer.textContent = `0 de ${total} oportunidad${total === 1 ? '' : 'es'} visibles con el filtro actual.`;
            if (btnDescargarCsv) btnDescargarCsv.disabled = true;
            return;
        }

        tbody.innerHTML = items.map((item) => {
            const codigo = String(item.codigo || '').toUpperCase();
            const href = codigo ? `${urls.cotizarBase}?codigo=${encodeURIComponent(codigo)}` : '';
            const nombre = item.nombre ? `<div class="small text-muted text-truncate" style="max-width:28rem;">${escapeHtml(item.nombre)}</div>` : '';
            const organismo = String(item.organismo || '').trim() || '—';
            const regionNombre = String(item.nombre_region || '').trim() || '—';
            const frases = Array.isArray(item.palabras_coinciden)
                ? item.palabras_coinciden.map((f) => String(f || '').trim()).filter(Boolean)
                : [];
            const frasesHtml = frases.length
                ? frases.map((f) => `<span class="badge text-bg-light border me-1 mb-1" title="Encontrada con «${escapeHtml(f)}»">${escapeHtml(f)}</span>`).join('')
                : '<span class="text-muted">—</span>';
            const tieneCantidad = item.cantidad_productos != null && item.cantidad_productos !== '';
            const cantidadNum = tieneCantidad ? Number(item.cantidad_productos) : null;
            const cantidadProductos = ! tieneCantidad || Number.isNaN(cantidadNum)
                ? '<span class="text-muted">—</span>'
                : escapeHtml(String(cantidadNum));
            const attrs = href
                ? ` class="oportunidad-fila" role="button" tabindex="0" data-href="${escapeHtml(href)}" title="Cotizar ${escapeHtml(codigo)}"`
                : '';
            const fraseBajoCodigo = frases.length
                ? `<div class="small mt-1">Encontrada con: <strong>${escapeHtml(frases.join(', '))}</strong></div>`
                : '';
            const productosBajoCodigo = tieneCantidad && ! Number.isNaN(cantidadNum)
                ? `<div class="small mt-1">Productos: <strong class="tabular-nums">${escapeHtml(String(cantidadNum))}</strong></div>`
                : '';
            return `<tr${attrs}>
                <td><code>${escapeHtml(codigo || '—')}</code>${nombre}${fraseBajoCodigo}${productosBajoCodigo}</td>
                <td class="small text-nowrap">${escapeHtml(regionNombre)}</td>
                <td class="small" style="min-width:8rem;">${frasesHtml}</td>
                <td class="small"><div class="text-truncate" style="max-width:18rem;" title="${escapeHtml(organismo)}">${escapeHtml(organismo)}</div></td>
                <td class="text-center tabular-nums">${cantidadProductos}</td>
                <td class="small text-nowrap">${escapeHtml(fmtFecha(item.fecha_publicacion))}</td>
                <td class="small text-nowrap">${escapeHtml(fmtFecha(item.fecha_cierre))}</td>
                <td class="text-end tabular-nums text-nowrap">${fmtMonto(item.monto_presupuesto_clp)}</td>
            </tr>`;
        }).join('');

        const filtroActivo = (filtroRegion && String(filtroRegion.value || '').trim() !== '')
            || (filtroOrganismo && String(filtroOrganismo.value || '').trim() !== '');
        const sufijo = cancelado ? ' (parcial, cancelada).' : ' del día.';
        const visibles = filtroActivo
            ? `${items.length} de ${total} oportunidad${total === 1 ? '' : 'es'} visibles.`
            : `${items.length} oportunidad${items.length === 1 ? '' : 'es'}${sufijo}`;
        footer.textContent = `${visibles} Haga clic en una fila para cotizarla.`;
        if (btnDescargarCsv) btnDescargarCsv.disabled = items.length === 0;
        bindFilas();
    }

    if (filtroRegion) {
        filtroRegion.addEventListener('change', renderTabla);
    }

    if (filtroOrganismo) {
        filtroOrganismo.addEventListener('input', () => {
            if (filtroOrganismoTimer) clearTimeout(filtroOrganismoTimer);
            filtroOrganismoTimer = setTimeout(renderTabla, 200);
        });
        filtroOrganismo.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (filtroOrganismoTimer) clearTimeout(filtroOrganismoTimer);
                renderTabla();
            }
        });
    }

    if (btnDescargarCsv) {
        btnDescargarCsv.addEventListener('click', descargarCsv);
    }

    document.querySelectorAll('[data-sort]').forEach((button) => {
        button.addEventListener('click', () => {
            const column = button.getAttribute('data-sort') || 'presupuesto';
            if (sortState.column === column) {
                sortState = {
                    column,
                    direction: sortState.direction === 'asc' ? 'desc' : 'asc',
                };
            } else {
                sortState = {
                    column,
                    direction: column === 'organismo' ? 'asc' : 'desc',
                };
            }
            renderTabla();
        });
    });

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
        const segs = Math.max(0, Math.floor(((finMs || Date.now()) - inicioMs) / 1000));
        const m = Math.floor(segs / 60);
        const s = segs % 60;
        relDuracion.textContent = m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
    }

    function parametrosConsultaPaso(paso) {
        const params = {
            estado: 'publicada',
            numero_pagina: 1,
            ordenar_por: 'FechaPublicacion',
            q: String(paso?.frase ?? '').trim(),
            region: Number(paso?.region) || 1,
            tamano_pagina: 50,
        };
        return Object.fromEntries(Object.keys(params).sort().map((k) => [k, params[k]]));
    }

    function buildConsultaPreview(paso, indice, total) {
        const params = parametrosConsultaPaso(paso);
        const endpoint = `${mpApi.baseUrl}${mpApi.path}`;
        const qs = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => qs.set(k, String(v)));

        const payload = {
            endpoint,
            filtro_fecha: new Date().toISOString().slice(0, 10),
            header_ticket: '(configurado)',
            metodo: 'GET',
            parametros: params,
            paso: indice != null && total != null ? `${indice + 1}/${total}` : null,
            region_nombre: paso?.region_nombre ?? null,
            total_api: null,
            total_publicadas_hoy: null,
            url_completa: `${endpoint}?${qs.toString()}`,
        };

        return payload;
    }

    function mostrarDebugConsulta(consulta, paso, indice, total, nota) {
        if (!debugPanel || !debugConsultaJson) return;

        const data = consulta && typeof consulta === 'object'
            ? consulta
            : buildConsultaPreview(paso, indice, total);

        const url = data.url_completa
            || (data.endpoint && data.parametros
                ? `${data.endpoint}?${new URLSearchParams(Object.entries(data.parametros).sort()).toString()}`
                : '');

        if (debugEndpointUrl) {
            debugEndpointUrl.textContent = url || `${data.metodo || 'GET'} ${data.endpoint || ''}`;
        }

        if (debugPasoLine) {
            const frase = paso?.frase ?? data.parametros?.q ?? '';
            const regionNombre = paso?.region_nombre || data.region_nombre || (`Región ${paso?.region ?? data.parametros?.region ?? ''}`);
            const pasoTxt = indice != null && total != null ? `Paso ${indice + 1}/${total}` : '';
            let linea = [pasoTxt, `«${frase}»`, regionNombre].filter(Boolean).join(' · ');
            if (nota) linea += ` — ${nota}`;
            if (data.total_api != null) {
                linea += ` — API devolvió ${data.total_api}, publicadas hoy: ${data.total_publicadas_hoy ?? 0}`;
            }
            debugPasoLine.textContent = linea;
        }

        const { json: _json, ...sinJson } = data;
        const ordenado = Object.fromEntries(Object.keys(sinJson).sort().map((k) => [k, sinJson[k]]));
        debugConsultaJson.textContent = data.json || JSON.stringify(ordenado, null, 2);
        debugPanel.classList.remove('d-none');
        debugPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function limpiarDebugConsulta() {
        debugPanel?.classList.add('d-none');
        if (debugEndpointUrl) debugEndpointUrl.textContent = '';
        if (debugPasoLine) debugPasoLine.textContent = '';
        if (debugConsultaJson) debugConsultaJson.textContent = '';
    }

    function textoDetallePaso(paso, indice, total, consulta) {
        const frase = paso?.frase ?? '';
        const regionNombre = paso?.region_nombre || ('Región ' + (paso?.region ?? ''));
        let texto = `Consultando «${frase}» · ${regionNombre} (${indice + 1}/${total})`;
        if (consulta) {
            texto += ` — API: ${consulta.total_api ?? 0}, publicadas hoy: ${consulta.total_publicadas_hoy ?? 0}`;
        }
        return texto;
    }

    function mostrarError(msg) {
        relError.textContent = msg || 'Error en la consulta.';
        relError.classList.remove('d-none');
    }

    function cancelarBusqueda() {
        if (!buscando || !urls.cancelar) return;
        if (btnCancelar) btnCancelar.disabled = true;
        relDetalle.textContent = 'Cancelando…';
        postJson(urls.cancelar, {})
            .then((data) => aplicarEstadoCorrida(data.corrida))
            .catch((e) => mostrarError(e.message || String(e)));
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
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
            const err = new Error(data.error || data.message || (`HTTP ${res.status}`));
            err.consulta = data.consulta ?? null;
            throw err;
        }
        return data;
    }

    async function getJson(url) {
        const res = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
            throw new Error(data.error || data.message || (`HTTP ${res.status}`));
        }
        return data;
    }

    function detenerPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function aplicarEstadoCorrida(corrida) {
        if (!corrida) return;

        estado.classList.remove('d-none');
        placeholder.classList.add('d-none');
        const inicio = corrida.inicio ? new Date(corrida.inicio) : null;
        const fin = corrida.fin ? new Date(corrida.fin) : null;
        inicioMs = inicio && !Number.isNaN(inicio.getTime()) ? inicio.getTime() : inicioMs;
        finMs = fin && !Number.isNaN(fin.getTime()) ? fin.getTime() : null;
        relInicio.textContent = inicio ? inicio.toLocaleTimeString('es-CL', { hour12: false }) : '—';
        relFin.textContent = fin ? fin.toLocaleTimeString('es-CL', { hour12: false }) : '—';
        setProgreso(Number(corrida.progreso) || 0);
        relDetalle.textContent = corrida.mensaje || 'Procesando en segundo plano…';

        porCodigo = new Map();
        cargarItems(corrida.items || []);
        renderTabla();

        const activo = corrida.estado === 'running';
        cancelado = corrida.estado === 'cancelled';
        setModoBusqueda(activo);
        relBar.classList.toggle('progress-bar-animated', activo);

        const fallidos = Number(corrida.pasos_fallidos) || 0;
        if (!activo && fallidos > 0) {
            mostrarError(`La búsqueda terminó con ${fallidos} paso(s) fallido(s). Los demás pasos sí fueron procesados.`);
        } else if (activo) {
            relError.classList.add('d-none');
        }

        if (activo) {
            detenerPolling();
            pollTimer = setTimeout(consultarEstado, 2000);
        } else {
            detenerPolling();
            if (tickTimer) {
                clearInterval(tickTimer);
                tickTimer = null;
            }
            actualizarDuracion();
        }
    }

    async function consultarEstado() {
        if (!urls.estado) return;
        try {
            const data = await getJson(urls.estado);
            aplicarEstadoCorrida(data.corrida);
        } catch (e) {
            relDetalle.textContent = 'Conexión interrumpida; reintentando estado de la búsqueda…';
            detenerPolling();
            pollTimer = setTimeout(consultarEstado, 5000);
        }
    }

    async function buscar() {
        if (buscando || !btn || btn.disabled) return;
        cancelado = false;
        setModoBusqueda(true);
        inicioMs = Date.now();
        finMs = null;
        if (tickTimer) clearInterval(tickTimer);
        tickTimer = setInterval(actualizarDuracion, 250);

        estado.classList.remove('d-none');
        placeholder.classList.add('d-none');
        resultados.classList.remove('d-none');
        relError.classList.add('d-none');
        limpiarDebugConsulta();
        relFin.textContent = '—';
        relInicio.textContent = '…';
        setProgreso(0);
        relBar.classList.add('progress-bar-animated');
        relDetalle.textContent = 'Iniciando consulta a Mercado Público…';
        renderTabla();

        try {
            const data = await postJson(urls.iniciar, {});
            aplicarEstadoCorrida(data.corrida);
        } catch (e) {
            relBar.classList.remove('progress-bar-animated');
            mostrarError(e.message || String(e));
            relDetalle.textContent = 'No se pudo encolar la búsqueda.';
            renderTabla();
            setModoBusqueda(false);
        }
    }

    // Al abrir: muestra lo ya grabado hoy.
    if (Array.isArray(guardadasIniciales) && guardadasIniciales.length > 0) {
        cargarItems(guardadasIniciales);
        if (fechaBusquedaInicial && relFecha) {
            relFecha.textContent = `(${fechaBusquedaInicial})`;
        }
        if (estado && relDetalle) {
            estado.classList.remove('d-none');
            relDetalle.textContent = puedeBuscar
                ? `${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} grabadas hoy. Pulse Buscar para consultar de nuevo.`
                : `${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} sincronizadas hoy.`;
            setProgreso(100);
        }
        renderTabla();
    }

    if (corridaInicial) {
        aplicarEstadoCorrida(corridaInicial);
        if (corridaInicial.estado === 'running' && !tickTimer) {
            tickTimer = setInterval(actualizarDuracion, 250);
        }
    }

    if (puedeBuscar) {
        btn?.addEventListener('click', buscar);
        btnCancelar?.addEventListener('click', cancelarBusqueda);
    }
})();
</script>
@endpush
