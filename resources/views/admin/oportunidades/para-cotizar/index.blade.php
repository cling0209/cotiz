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
                    Un recorrido por regi&oacute;n (<code>MERCADOPUBLICO_REGIONES</code>);
                    al leer cada cotizaci&oacute;n se hace match local con <strong>todas</strong> las palabras clave.
                </p>
            @else
                <p class="text-muted mb-0 small">
                    Listado de Compras &Aacute;giles del d&iacute;a sincronizadas desde el sitio que realiza la b&uacute;squeda.
                    En este sitio no se ejecuta la b&uacute;squeda autom&aacute;tica.
                </p>
            @endif
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @if($puedePalabras ?? false)
                <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-tags"></i> Palabras clave
                </a>
            @endif
            @if($puedeBuscar)
                <button type="button" id="btn-buscar-oportunidades" class="btn btn-primary btn-sm" @disabled($palabras === [])>
                    <i class="bi bi-search"></i> Buscar cotizaciones
                </button>
                <button type="button" id="btn-cancelar-oportunidades" class="btn btn-outline-danger btn-sm d-none">
                    <i class="bi bi-x-circle"></i> Cancelar
                </button>
            @endif
        </div>
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
                    Palabras clave (match local en cada regi&oacute;n):
                    <a href="{{ route('admin.oportunidades.palabras-clave.index') }}">administrar</a>
                </div>
                @foreach($palabras as $i => $frase)
                    <span class="badge text-bg-light border me-1">
                        {{ $frase }}
                    </span>
                @endforeach
            </div>
        @endif
    @elseif(($puedePalabras ?? false) && $palabras !== [])
        <div class="mb-3">
            <div class="small text-muted mb-1">
                Palabras clave del sitio:
                <a href="{{ route('admin.oportunidades.palabras-clave.index') }}">administrar</a>
            </div>
            @foreach($palabras as $frase)
                <span class="badge text-bg-light border me-1">{{ $frase }}</span>
            @endforeach
        </div>
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
                <div class="text-nowrap">
                    <i class="bi bi-hourglass-split"></i>
                    Tiempo: <strong id="rel-duracion" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap">
                    <i class="bi bi-person"></i>
                    Solicitado por: <strong id="rel-usuario">—</strong>
                </div>
                <div class="text-nowrap ms-auto">
                    <span id="rel-encontradas" class="badge text-bg-primary">0</span>
                    <span class="text-muted">vigentes</span>
                    <span id="rel-fecha" class="text-muted ms-1"></span>
                </div>
            </div>
            <div id="rel-progreso-wrap" class="progress mb-2" style="height: 0.75rem;">
                <div id="rel-progreso-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar" style="width: 0%">0%</div>
            </div>
            <div id="rel-detalle" class="small text-muted">Preparando consulta…</div>
            <div id="rel-error" class="alert alert-danger mt-2 mb-0 py-2 d-none"></div>
            <div id="rel-pasos" class="mt-3 d-none">
                <button type="button" id="rel-pasos-toggle" class="btn btn-sm btn-outline-secondary mb-2"
                        aria-expanded="false" aria-controls="rel-pasos-panel">
                    <i class="bi bi-list-check"></i>
                    Detalle por regi&oacute;n <span id="rel-pasos-contador" class="badge text-bg-secondary ms-1">0</span>
                    <i id="rel-pasos-chevron" class="bi bi-chevron-down ms-1"></i>
                </button>
                <div id="rel-pasos-panel" class="table-responsive d-none" style="max-height: 320px; overflow-y: auto;">
                    <table class="table table-sm table-striped align-middle small mb-0">
                        <thead class="table-light" style="position: sticky; top: 0;">
                            <tr>
                                <th class="text-nowrap">#</th>
                                <th class="text-nowrap">D&iacute;a</th>
                                <th class="text-nowrap">Regi&oacute;n</th>
                                <th class="text-nowrap">Match</th>
                                <th class="text-nowrap text-end">Cotizaciones</th>
                                <th class="text-nowrap text-end">Tiempo</th>
                                <th class="text-nowrap">Resultado</th>
                            </tr>
                        </thead>
                        <tbody id="rel-pasos-tbody"></tbody>
                    </table>
                </div>
                <div id="oportunidad-debug" class="mt-3 d-none">
                    <button type="button" id="debug-toggle" class="btn btn-sm btn-outline-secondary mb-2"
                            aria-expanded="false" aria-controls="debug-panel">
                        <i class="bi bi-braces"></i>
                        Detalle API (JSON)
                        <i id="debug-toggle-chevron" class="bi bi-chevron-down ms-1"></i>
                    </button>
                    <div id="debug-panel" class="border rounded bg-light p-3 d-none">
                        <div class="small fw-semibold mb-2">
                            Consulta Mercado P&uacute;blico &mdash; endpoint y par&aacute;metros
                        </div>
                        <div id="debug-endpoint-line" class="small mb-2">
                            <span class="text-muted">URL:</span>
                            <code id="debug-endpoint-url" class="user-select-all text-break"></code>
                        </div>
                        <div id="debug-paso-line" class="small text-muted mb-2"></div>
                        <pre id="debug-consulta-json" class="bg-white border rounded p-3 mb-2 small font-monospace text-break"
                             style="max-height:16rem;overflow:auto;white-space:pre-wrap;"></pre>
                        <div class="small fw-semibold mb-1 mt-2">
                            <i class="bi bi-arrow-return-left"></i> Respuesta de Mercado P&uacute;blico
                        </div>
                        <div id="debug-respuesta-line" class="small text-muted mb-1"></div>
                        <pre id="debug-respuesta-json" class="bg-white border rounded p-3 mb-0 small font-monospace text-break"
                             style="max-height:16rem;overflow:auto;white-space:pre-wrap;"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="vinculo-estado" class="card shadow-sm mb-3 d-none">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-3 align-items-center small mb-2">
                <div class="fw-semibold text-nowrap">
                    <i class="bi bi-link-45deg"></i> Vinculaciones internas
                </div>
                <div class="text-nowrap">
                    <i class="bi bi-clock"></i>
                    Inicio: <strong id="vin-inicio" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap">
                    <i class="bi bi-flag"></i>
                    Fin: <strong id="vin-fin" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap">
                    <i class="bi bi-hourglass-split"></i>
                    Tiempo: <strong id="vin-duracion" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap ms-auto">
                    <span id="vin-progreso-txt" class="badge text-bg-secondary">0/0</span>
                </div>
            </div>
            <div id="vin-progreso-wrap" class="progress mb-2" style="height: 0.75rem;">
                <div id="vin-progreso-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                     role="progressbar" style="width: 0%">0%</div>
            </div>
            <div id="vin-detalle" class="small text-muted">Preparando vinculación con maestro…</div>
        </div>
    </div>

    <div id="oportunidad-placeholder" class="card shadow-sm @if(! $puedeBuscar || $palabras === [] || count($guardadas) > 0) d-none @endif">
        <div class="card-body text-center text-muted py-5">
            Pulse <strong>Buscar cotizaciones</strong> para consultar Mercado P&uacute;blico
            (d&iacute;as pendientes desde la fecha de inicio configurada).
            Cada resultado vigente se graba autom&aacute;ticamente.
        </div>
    </div>
    @endif

    <div id="oportunidad-resultados" class="card shadow-sm @if(count($guardadas) === 0) d-none @endif">
        <div class="card-body border-bottom py-2">
            <div class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4 col-lg-2">
                    <label for="filtro-region" class="form-label small mb-1">Regi&oacute;n</label>
                    <select id="filtro-region" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        @foreach(($regionesFiltro ?? []) as $codigoRegion => $nombreRegion)
                            <option value="{{ (int) $codigoRegion }}">{{ $nombreRegion }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-4 col-lg-3">
                    <label for="filtro-organismo" class="form-label small mb-1">Organismo</label>
                    <input type="search" id="filtro-organismo" class="form-control form-control-sm"
                           placeholder="Buscar organismo…" autocomplete="off">
                </div>
                <div class="col-sm-8 col-md-5 col-lg-4">
                    <label for="filtro-palabra-clave" class="form-label small mb-1">Palabra clave</label>
                    <input type="search" id="filtro-palabra-clave" class="form-control form-control-sm"
                           placeholder="Ej. papel, l&aacute;piz, c&oacute;digo…" autocomplete="off">
                </div>
                <div class="col-sm-4 col-md-3 col-lg-3 d-flex flex-wrap gap-2 justify-content-md-end align-items-end">
                    <button type="button" id="btn-filtrar-oportunidades" class="btn btn-primary btn-sm" data-no-loader>
                        <i class="bi bi-funnel"></i> Filtrar
                    </button>
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
            <table class="table table-sm table-hover mb-0 align-middle oportunidades-tabla-compacta">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:14rem;">Cotizaci&oacute;n</th>
                        <th data-sort-header="organismo" style="min-width:12rem;">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold text-start" data-sort="organismo">
                                Regi&oacute;n / Organismo <span class="sort-indicator text-muted" aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th data-sort-header="fecha_publicacion" style="min-width:9rem;">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold text-start" data-sort="fecha_publicacion">
                                Fechas <span class="sort-indicator text-muted" aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th class="text-end" data-sort-header="presupuesto" style="min-width:7rem;">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold" data-sort="presupuesto">
                                Presupuesto <span class="sort-indicator text-muted" aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th class="text-end text-nowrap" style="min-width:8rem;">Acci&oacute;n</th>
                    </tr>
                </thead>
                <tbody id="oportunidad-tbody">
                </tbody>
            </table>
        </div>
        <div class="card-body border-top py-2">
            <div class="small text-muted mb-2" id="oportunidad-footer">
                Use <strong>Ir a cotizar</strong> en la fila deseada.
                @if($puedeBuscar)
                    Los resultados del d&iacute;a quedan grabados y se sincronizan con el sitio par.
                @else
                    Resultados sincronizados desde el sitio de b&uacute;squeda.
                @endif
            </div>
            <nav id="oportunidad-paginacion" class="d-flex justify-content-center" aria-label="Paginaci&oacute;n de oportunidades">
                <ul class="pagination mb-0" id="oportunidad-paginacion-lista"></ul>
            </nav>
        </div>
    </div>

</div>
<style>
    .oportunidades-tabla-compacta td {
        vertical-align: top;
        padding-top: 0.55rem;
        padding-bottom: 0.55rem;
    }
    .oportunidades-tabla-compacta .opc-linea-2 {
        line-height: 1.25;
        max-width: 22rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .oportunidades-tabla-compacta .opc-meta {
        color: var(--bs-secondary-color);
        font-size: 0.8rem;
    }
</style>
@endsection

@push('scripts')
<script>
(function () {
    const puedeBuscar = @json((bool) $puedeBuscar);
    const urls = {
        iniciar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.iniciar') : ''),
        estado: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.estado') : ''),
        cancelar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.cancelar') : ''),
        reanudar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.reanudar') : ''),
        cotizarBase: @json(route('admin.cotizaciones.create')),
    };
    const filtrosUserId = @json((int) ($filtrosUserId ?? 0));
    const FILTROS_STORAGE_KEY = filtrosUserId > 0
        ? `cotiz.oportunidades.filtros.${filtrosUserId}`
        : '';
    const VISITAS_STORAGE_KEY = filtrosUserId > 0
        ? `cotiz.oportunidades.visitas.${filtrosUserId}`
        : '';
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
    const relUsuario = document.getElementById('rel-usuario');
    const relEncontradas = document.getElementById('rel-encontradas');
    const relFecha = document.getElementById('rel-fecha');
    const relBar = document.getElementById('rel-progreso-bar');
    const relProgresoWrap = document.getElementById('rel-progreso-wrap');
    const relDetalle = document.getElementById('rel-detalle');
    const relError = document.getElementById('rel-error');
    const relPasos = document.getElementById('rel-pasos');
    const relPasosToggle = document.getElementById('rel-pasos-toggle');
    const relPasosPanel = document.getElementById('rel-pasos-panel');
    const relPasosTbody = document.getElementById('rel-pasos-tbody');
    const relPasosContador = document.getElementById('rel-pasos-contador');
    const debugPanel = document.getElementById('oportunidad-debug');
    const debugToggle = document.getElementById('debug-toggle');
    const debugToggleChevron = document.getElementById('debug-toggle-chevron');
    const debugBody = document.getElementById('debug-panel');
    const debugEndpointUrl = document.getElementById('debug-endpoint-url');
    const debugPasoLine = document.getElementById('debug-paso-line');
    const debugConsultaJson = document.getElementById('debug-consulta-json');
    const debugRespuestaLine = document.getElementById('debug-respuesta-line');
    const debugRespuestaJson = document.getElementById('debug-respuesta-json');
    const filtroRegion = document.getElementById('filtro-region');
    const filtroOrganismo = document.getElementById('filtro-organismo');
    const filtroPalabraClave = document.getElementById('filtro-palabra-clave');
    const btnFiltrarOportunidades = document.getElementById('btn-filtrar-oportunidades');
    const btnDescargarCsv = document.getElementById('btn-descargar-csv');
    const paginacionNav = document.getElementById('oportunidad-paginacion');
    const paginacionLista = document.getElementById('oportunidad-paginacion-lista');

    /** @type {Map<string, object>} */
    let porCodigo = new Map();
    let inicioMs = null;
    let finMs = null;
    let tickTimer = null;
    let buscando = false;
    let cancelado = false;
    let pollTimer = null;
    let ultimaCorridaId = null;
    let intentosCambioDia = 0;
    const PAGE_SIZE = 20;
    let paginaActual = 1;
    let sortState = { column: 'presupuesto', direction: 'desc' };
    let filtroOrganismoTimer = null;
    /** Criterio de palabra clave aplicado al pulsar Filtrar (no al tipear). */
    let filtroPalabraClaveAplicado = '';

    const guardadasIniciales = @json($guardadas ?? []);
    const fechaBusquedaInicial = @json($fechaBusqueda ?? null);
    const corridaInicial = @json($corridaEstado ?? null);

    function cargarItems(items) {
        (items || []).forEach((item) => {
            const codigo = String(item.codigo || '').toUpperCase();
            if (!codigo) return;
            if (porCodigo.has(codigo)) {
                const prev = porCodigo.get(codigo);
                porCodigo.set(codigo, {
                    ...prev,
                    ...item,
                    visitas_usuario: Math.max(
                        Number(prev.visitas_usuario) || 0,
                        Number(item.visitas_usuario) || 0,
                    ),
                });
                return;
            }
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

    function itemCoincidePalabraClave(item, criterio) {
        if (criterio === '') {
            return true;
        }
        const codigo = normalizarTexto(item.codigo);
        const nombre = normalizarTexto(item.nombre);
        if (codigo.includes(criterio) || nombre.includes(criterio)) {
            return true;
        }
        const frases = Array.isArray(item.palabras_coinciden) ? item.palabras_coinciden : [];
        return frases.some((f) => normalizarTexto(f).includes(criterio));
    }

    function itemsFiltrados() {
        const regionSel = filtroRegion ? String(filtroRegion.value || '').trim() : '';
        const organismoSel = filtroOrganismo ? normalizarTexto(filtroOrganismo.value) : '';
        const palabraSel = filtroPalabraClaveAplicado;
        let items = Array.from(porCodigo.values());
        if (regionSel !== '') {
            const codigoRegion = Number(regionSel);
            items = items.filter((item) => Number(item.region) === codigoRegion);
        }
        if (organismoSel !== '') {
            items = items.filter((item) => normalizarTexto(item.organismo).includes(organismoSel));
        }
        if (palabraSel !== '') {
            items = items.filter((item) => itemCoincidePalabraClave(item, palabraSel));
        }
        return items.sort(comparar);
    }

    function aplicarFiltros() {
        filtroPalabraClaveAplicado = filtroPalabraClave
            ? normalizarTexto(filtroPalabraClave.value)
            : '';
        guardarFiltros();
        renderTabla(true);
    }

    function leerFiltrosGuardados() {
        if (!FILTROS_STORAGE_KEY) {
            return null;
        }
        try {
            const raw = localStorage.getItem(FILTROS_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            const data = JSON.parse(raw);
            return data && typeof data === 'object' ? data : null;
        } catch (e) {
            return null;
        }
    }

    function guardarFiltros() {
        if (!FILTROS_STORAGE_KEY) {
            return;
        }
        try {
            localStorage.setItem(FILTROS_STORAGE_KEY, JSON.stringify({
                region: filtroRegion ? String(filtroRegion.value || '') : '',
                organismo: filtroOrganismo ? String(filtroOrganismo.value || '') : '',
                palabra_clave: filtroPalabraClave ? String(filtroPalabraClave.value || '') : '',
                palabra_clave_aplicada: filtroPalabraClaveAplicado,
            }));
        } catch (e) {
            // localStorage no disponible
        }
    }

    function restaurarFiltros() {
        const data = leerFiltrosGuardados();
        if (!data) {
            return;
        }
        if (filtroRegion && data.region != null) {
            filtroRegion.value = String(data.region || '');
        }
        if (filtroOrganismo && data.organismo != null) {
            filtroOrganismo.value = String(data.organismo || '');
        }
        if (filtroPalabraClave && data.palabra_clave != null) {
            filtroPalabraClave.value = String(data.palabra_clave || '');
        }
        filtroPalabraClaveAplicado = data.palabra_clave_aplicada != null
            ? String(data.palabra_clave_aplicada || '')
            : (filtroPalabraClave ? normalizarTexto(filtroPalabraClave.value) : '');
    }

    function leerVisitasLocales() {
        if (!VISITAS_STORAGE_KEY) {
            return {};
        }
        try {
            const raw = localStorage.getItem(VISITAS_STORAGE_KEY);
            if (!raw) {
                return {};
            }
            const data = JSON.parse(raw);
            return data && typeof data === 'object' ? data : {};
        } catch (e) {
            return {};
        }
    }

    function guardarVisitasLocales(mapa) {
        if (!VISITAS_STORAGE_KEY) {
            return;
        }
        try {
            localStorage.setItem(VISITAS_STORAGE_KEY, JSON.stringify(mapa || {}));
        } catch (e) {
            // localStorage no disponible
        }
    }

    function incrementarVisitaLocal(codigo) {
        const codigoNorm = String(codigo || '').toUpperCase().trim();
        if (!codigoNorm || !VISITAS_STORAGE_KEY || !filtrosUserId) {
            return 0;
        }
        const onceKey = `cotiz.oportunidad_visita_once.${filtrosUserId}.${codigoNorm}`;
        try {
            sessionStorage.setItem(onceKey, String(Date.now()));
        } catch (e) {
            // sessionStorage no disponible
        }
        const mapa = leerVisitasLocales();
        const veces = (Number(mapa[codigoNorm]) || 0) + 1;
        mapa[codigoNorm] = veces;
        guardarVisitasLocales(mapa);
        const item = porCodigo.get(codigoNorm);
        if (item) {
            item.visitas_usuario = Math.max(Number(item.visitas_usuario) || 0, veces);
        }
        return veces;
    }

    function visitasDeItem(item) {
        const codigo = String(item?.codigo || '').toUpperCase().trim();
        const servidor = Number(item?.visitas_usuario) || 0;
        const local = Number(leerVisitasLocales()[codigo]) || 0;
        return Math.max(servidor, local);
    }

    function sincronizarVisitasLocalesEnMapa() {
        const mapa = leerVisitasLocales();
        Object.keys(mapa).forEach((codigo) => {
            const item = porCodigo.get(codigo);
            if (!item) {
                return;
            }
            const local = Number(mapa[codigo]) || 0;
            if (local > (Number(item.visitas_usuario) || 0)) {
                item.visitas_usuario = local;
            }
        });
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

    function renderTabla(resetPage) {
        if (resetPage) {
            paginaActual = 1;
        }
        const total = porCodigo.size;
        const items = itemsFiltrados();
        const totalPaginas = Math.max(1, Math.ceil(items.length / PAGE_SIZE));
        if (paginaActual > totalPaginas) {
            paginaActual = totalPaginas;
        }
        if (paginaActual < 1) {
            paginaActual = 1;
        }
        actualizarIndicadoresOrden();
        if (relEncontradas) relEncontradas.textContent = String(total);

        if (total === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">
                ${buscando ? 'Buscando…' : (cancelado ? 'Búsqueda cancelada. Sin resultados aún.' : 'No hay oportunidades vigentes para cotizar.')}
            </td></tr>`;
            footer.textContent = buscando ? 'Consulta en curso…' : (cancelado ? 'Consulta cancelada.' : 'Sin resultados vigentes.');
            if (btnDescargarCsv) btnDescargarCsv.disabled = true;
            actualizarPaginadores(1);
            return;
        }

        if (items.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">
                No hay oportunidades con los filtros seleccionados.
            </td></tr>`;
            footer.textContent = `0 de ${total} oportunidad${total === 1 ? '' : 'es'} visibles con el filtro actual.`;
            if (btnDescargarCsv) btnDescargarCsv.disabled = true;
            actualizarPaginadores(1);
            return;
        }

        const desde = (paginaActual - 1) * PAGE_SIZE;
        const paginaItems = items.slice(desde, desde + PAGE_SIZE);

        tbody.innerHTML = paginaItems.map((item) => {
            const codigo = String(item.codigo || '').toUpperCase();
            const href = codigo ? `${urls.cotizarBase}?codigo=${encodeURIComponent(codigo)}` : '';
            const nombre = String(item.nombre || '').trim();
            const organismo = String(item.organismo || '').trim() || '—';
            const regionNombre = String(item.nombre_region || '').trim() || '—';
            const frases = Array.isArray(item.palabras_coinciden)
                ? item.palabras_coinciden.map((f) => String(f || '').trim()).filter(Boolean)
                : [];
            const tieneCantidad = item.cantidad_productos != null && item.cantidad_productos !== '';
            const cantidadNum = tieneCantidad ? Number(item.cantidad_productos) : null;
            const fraseBajoCodigo = frases.length
                ? `<div class="opc-meta mt-1">Encontrada con: <strong>${escapeHtml(frases.join(', '))}</strong></div>`
                : '';
            const productosBajoCodigo = tieneCantidad && ! Number.isNaN(cantidadNum)
                ? `<div class="opc-meta mt-1">Productos: <strong class="tabular-nums">${escapeHtml(String(cantidadNum))}</strong></div>`
                : '';
            const vinculoCompleto = !!item.vinculo_completo;
            const vinculoHtml = vinculoCompleto
                ? (() => {
                    const vinc = Number(item.productos_vinculados) || 0;
                    const tot = Number(item.cantidad_productos) || 0;
                    const pct = item.porcentaje_vinculo != null
                        ? Number(item.porcentaje_vinculo)
                        : (tot > 0 ? Math.round((vinc / tot) * 100) : 0);
                    return `<div class="opc-meta mt-1">Vinculados: <strong class="tabular-nums">${escapeHtml(String(vinc))}/${escapeHtml(String(tot))}</strong> (${escapeHtml(String(pct))}%)</div>`;
                })()
                : '';
            const nombreHtml = nombre
                ? `<div class="opc-linea-2 opc-meta" title="${escapeHtml(nombre)}">${escapeHtml(nombre)}</div>`
                : '';
            const visitas = visitasDeItem(item);
            const codigoLabel = visitas > 0
                ? `${escapeHtml(codigo || '—')} visto ${visitas}`
                : escapeHtml(codigo || '—');
            const accionHtml = href
                ? `<a href="${escapeHtml(href)}" class="btn btn-primary btn-sm text-nowrap btn-ir-cotizar" data-no-loader data-codigo="${escapeHtml(codigo)}">
                        <i class="bi bi-cart-plus"></i> Ir a cotizar
                   </a>`
                : '<span class="text-muted small">—</span>';
            return `<tr>
                <td>
                    <code>${codigoLabel}</code>
                    ${nombreHtml}
                    ${fraseBajoCodigo}
                    ${productosBajoCodigo}
                    ${vinculoHtml}
                </td>
                <td class="small">
                    <div class="fw-semibold opc-linea-2" title="${escapeHtml(regionNombre)}">${escapeHtml(regionNombre)}</div>
                    <div class="opc-linea-2 opc-meta mt-1" title="${escapeHtml(organismo)}">${escapeHtml(organismo)}</div>
                </td>
                <td class="small text-nowrap">
                    <div><span class="opc-meta">Pub.</span> ${escapeHtml(fmtFecha(item.fecha_publicacion))}</div>
                    <div class="mt-1"><span class="opc-meta">Cierre</span> ${escapeHtml(fmtFecha(item.fecha_cierre))}</div>
                </td>
                <td class="text-end tabular-nums text-nowrap fw-semibold">${fmtMonto(item.monto_presupuesto_clp)}</td>
                <td class="text-end">${accionHtml}</td>
            </tr>`;
        }).join('');

        const filtroActivo = (filtroRegion && String(filtroRegion.value || '').trim() !== '')
            || (filtroOrganismo && String(filtroOrganismo.value || '').trim() !== '')
            || filtroPalabraClaveAplicado !== '';
        const sufijo = cancelado ? ' (parcial, cancelada).' : ' del día.';
        const visibles = filtroActivo
            ? `${items.length} de ${total} oportunidad${total === 1 ? '' : 'es'} visibles.`
            : `${items.length} oportunidad${items.length === 1 ? '' : 'es'}${sufijo}`;
        const rango = items.length > PAGE_SIZE
            ? ` Mostrando ${desde + 1}–${Math.min(desde + PAGE_SIZE, items.length)}.`
            : '';
        footer.textContent = `${visibles}${rango} Use «Ir a cotizar» en la fila deseada.`;
        if (btnDescargarCsv) btnDescargarCsv.disabled = items.length === 0;

        actualizarPaginadores(totalPaginas);
    }

    /** Ventana de páginas con elipsis (mismo criterio visual que Laravel/Bootstrap). */
    function elementosPaginacion(actual, total) {
        if (total <= 1) {
            return [1];
        }
        const onEachSide = 2;
        const windowStart = Math.max(2, actual - onEachSide);
        const windowEnd = Math.min(total - 1, actual + onEachSide);
        const items = [1];
        if (windowStart > 2) {
            items.push('...');
        }
        for (let i = windowStart; i <= windowEnd; i++) {
            items.push(i);
        }
        if (windowEnd < total - 1) {
            items.push('...');
        }
        if (total > 1) {
            items.push(total);
        }
        return items;
    }

    function irAPagina(pagina) {
        const totalPaginas = Math.max(1, Math.ceil(itemsFiltrados().length / PAGE_SIZE));
        const destino = Math.max(1, Math.min(totalPaginas, Number(pagina) || 1));
        if (destino === paginaActual) {
            return;
        }
        paginaActual = destino;
        renderTabla(false);
    }

    function actualizarPaginadores(totalPaginas) {
        if (!paginacionLista) {
            return;
        }
        const total = Math.max(1, Number(totalPaginas) || 1);
        const esPrimera = paginaActual <= 1;
        const esUltima = paginaActual >= total;
        const partes = [];

        partes.push(
            `<li class="page-item${esPrimera ? ' disabled' : ''}">`
            + `<button type="button" class="page-link" data-pagina="${paginaActual - 1}" aria-label="Anterior" ${esPrimera ? 'disabled' : ''}>&laquo;</button>`
            + `</li>`
        );

        elementosPaginacion(paginaActual, total).forEach((item) => {
            if (item === '...') {
                partes.push('<li class="page-item disabled"><span class="page-link">…</span></li>');
                return;
            }
            const activa = item === paginaActual;
            partes.push(
                `<li class="page-item${activa ? ' active' : ''}">`
                + `<button type="button" class="page-link" data-pagina="${item}" ${activa ? 'aria-current="page"' : ''}>${item}</button>`
                + `</li>`
            );
        });

        partes.push(
            `<li class="page-item${esUltima ? ' disabled' : ''}">`
            + `<button type="button" class="page-link" data-pagina="${paginaActual + 1}" aria-label="Siguiente" ${esUltima ? 'disabled' : ''}>&raquo;</button>`
            + `</li>`
        );

        paginacionLista.innerHTML = partes.join('');
        if (paginacionNav) {
            paginacionNav.classList.toggle('d-none', total <= 1 && porCodigo.size === 0);
        }
    }

    if (filtroRegion) {
        filtroRegion.addEventListener('change', () => {
            guardarFiltros();
            renderTabla(true);
        });
    }

    if (filtroOrganismo) {
        filtroOrganismo.addEventListener('input', () => {
            if (filtroOrganismoTimer) clearTimeout(filtroOrganismoTimer);
            filtroOrganismoTimer = setTimeout(() => {
                guardarFiltros();
                renderTabla(true);
            }, 200);
        });
        filtroOrganismo.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (filtroOrganismoTimer) clearTimeout(filtroOrganismoTimer);
                aplicarFiltros();
            }
        });
    }

    if (btnFiltrarOportunidades) {
        btnFiltrarOportunidades.addEventListener('click', () => aplicarFiltros());
    }

    if (filtroPalabraClave) {
        filtroPalabraClave.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                aplicarFiltros();
            }
        });
    }

    if (tbody) {
        // capture: contar antes de que page-loader u otros handlers naveguen
        tbody.addEventListener('click', (e) => {
            const link = e.target.closest('a.btn-ir-cotizar');
            if (!link) {
                return;
            }
            incrementarVisitaLocal(link.getAttribute('data-codigo') || '');
        }, true);
    }

    if (paginacionLista) {
        paginacionLista.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-pagina]');
            if (!btn || btn.disabled) {
                return;
            }
            irAPagina(btn.getAttribute('data-pagina'));
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
            renderTabla(true);
        });
    });

    function setProgreso(pct) {
        const p = Math.max(0, Math.min(100, pct || 0));
        relBar.style.width = p + '%';
        relBar.textContent = p + '%';
    }

    function formatearDuracionSegs(segs) {
        const total = Math.max(0, Math.floor(Number(segs) || 0));
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`;
        if (m > 0) return `${m}m ${String(s).padStart(2, '0')}s`;
        return `${s}s`;
    }

    function actualizarDuracion() {
        if (!inicioMs) {
            relDuracion.textContent = '—';
            return;
        }
        const segs = Math.max(0, Math.floor(((finMs || Date.now()) - inicioMs) / 1000));
        relDuracion.textContent = formatearDuracionSegs(segs);
    }

    function mensajeConTiempo(mensaje, duracionTexto) {
        const base = String(mensaje || '').trim();
        if (!duracionTexto) return base || 'Procesando en segundo plano…';
        if (/tiempo\s*:/i.test(base)) return base;
        return base ? `${base} Tiempo: ${duracionTexto}` : `Tiempo: ${duracionTexto}`;
    }

    function parametrosConsultaPaso(paso) {
        const frase = String(paso?.frase ?? '').trim();
        const params = {
            estado: 'publicada',
            numero_pagina: 1,
            ordenar_por: 'FechaPublicacion',
            region: Number(paso?.region) || 1,
            tamano_pagina: 50,
        };
        if (frase && frase !== '(todas)') {
            params.q = frase;
        }
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

    function setDebugAbierto(abierto) {
        if (!debugBody) return;
        debugBody.classList.toggle('d-none', !abierto);
        if (debugToggle) {
            debugToggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        }
        if (debugToggleChevron) {
            debugToggleChevron.classList.toggle('bi-chevron-down', !abierto);
            debugToggleChevron.classList.toggle('bi-chevron-up', abierto);
        }
    }

    if (debugToggle && debugBody) {
        debugToggle.addEventListener('click', () => {
            setDebugAbierto(debugBody.classList.contains('d-none'));
        });
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
            const fraseRaw = paso?.frase ?? data.parametros?.q ?? '';
            const frase = (!fraseRaw || fraseRaw === '(todas)') ? 'todas las frases' : fraseRaw;
            const regionNombre = paso?.region_nombre || data.region_nombre || (`Región ${paso?.region ?? data.parametros?.region ?? ''}`);
            const pasoTxt = indice != null && total != null ? `Paso ${indice + 1}/${total}` : '';
            let linea = [pasoTxt, `«${frase}»`, regionNombre].filter(Boolean).join(' · ');
            if (nota) linea += ` — ${nota}`;
            const terminado = paso?.resultado && !['pendiente', 'en_curso'].includes(paso.resultado);
            if (terminado && data.total_api != null) {
                linea += ` — API devolvió ${data.total_api}, publicadas hoy: ${data.total_publicadas_hoy ?? 0}`;
            }
            debugPasoLine.textContent = linea;
        }

        const { json: _json, respuesta_json: _rjson, respuesta: _resp, ...sinJson } = data;
        const ordenado = Object.fromEntries(Object.keys(sinJson).sort().map((k) => [k, sinJson[k]]));
        debugConsultaJson.textContent = data.json || JSON.stringify(ordenado, null, 2);

        if (debugRespuestaJson) {
            const resp = data.respuesta && typeof data.respuesta === 'object' ? data.respuesta : null;
            const terminado = paso?.resultado && !['pendiente', 'en_curso'].includes(paso.resultado);
            const cancelado = paso?.resultado === 'cancelado';
            if (cancelado) {
                debugRespuestaJson.textContent = 'Búsqueda cancelada; se detuvo la espera a Mercado Público.';
                if (debugRespuestaLine) {
                    debugRespuestaLine.textContent = 'Cancelado — la consulta en curso no se completó.';
                }
            } else if (!terminado) {
                const items = resp?.items_recibidos;
                debugRespuestaJson.textContent = (items != null && Number(items) > 0)
                    ? (data.respuesta_json || JSON.stringify(resp, null, 2))
                    : 'Esperando respuesta de Mercado Público…';
                if (debugRespuestaLine) {
                    debugRespuestaLine.textContent = items != null
                        ? `En curso — ${items} ítem(s) leídos hasta ahora.`
                        : '';
                }
            } else if (resp) {
                debugRespuestaJson.textContent = data.respuesta_json || JSON.stringify(resp, null, 2);
                if (debugRespuestaLine) {
                    const recibidos = resp.items_recibidos ?? 0;
                    const coinciden = resp.coinciden_hoy ?? 0;
                    debugRespuestaLine.textContent = recibidos === 0
                        ? 'MP no devolvió ítems para esta región/fecha.'
                        : `MP devolvió ${recibidos} ítem(s); coinciden hoy: ${coinciden}.`;
                }
            } else {
                debugRespuestaJson.textContent = 'Sin respuesta registrada para este paso.';
                if (debugRespuestaLine) debugRespuestaLine.textContent = '';
            }
        }

        debugPanel.classList.remove('d-none');
    }

    function actualizarDebugDesdeCorrida(corrida) {
        const pasos = Array.isArray(corrida?.pasos_resumen) ? corrida.pasos_resumen : [];
        const total = pasos.length;
        if (total === 0) {
            return;
        }

        let idx = -1;
        // Mientras corre: preferir el paso en curso; si no, el pendiente.
        if (corrida.estado === 'running') {
            idx = pasos.findIndex((p) => p?.resultado === 'en_curso');
            if (idx < 0) {
                idx = pasos.findIndex((p) => p?.resultado === 'pendiente');
            }
        }
        // Si no hay pendiente (o ya terminó): último paso con consulta real.
        if (idx < 0) {
            for (let i = pasos.length - 1; i >= 0; i--) {
                if (pasos[i]?.consulta && typeof pasos[i].consulta === 'object') {
                    idx = i;
                    break;
                }
            }
        }
        if (idx < 0) {
            idx = 0;
        }

        const paso = pasos[idx];
        const nota = paso?.error
            ? `${paso.error} — la búsqueda continúa con la siguiente región o reintento.`
            : (corrida.estado === 'cancelled'
                ? 'búsqueda cancelada'
                : (corrida.estado === 'running' && (paso?.resultado === 'pendiente' || paso?.resultado === 'en_curso')
                    ? 'consulta en curso…'
                    : null));
        mostrarDebugConsulta(paso?.consulta || null, paso, idx, total, nota);
    }

    function limpiarDebugConsulta() {
        debugPanel?.classList.add('d-none');
        setDebugAbierto(false);
        if (debugEndpointUrl) debugEndpointUrl.textContent = '';
        if (debugPasoLine) debugPasoLine.textContent = '';
        if (debugConsultaJson) debugConsultaJson.textContent = '';
        if (debugRespuestaLine) debugRespuestaLine.textContent = '';
        if (debugRespuestaJson) debugRespuestaJson.textContent = '';
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
        mostrarErrorFatal(msg);
    }

    function cancelarBusqueda() {
        if (!buscando || !urls.cancelar) return;
        if (btnCancelar) btnCancelar.disabled = true;
        relDetalle.textContent = 'Cancelando…';
        postJson(urls.cancelar, {})
            .then((data) => aplicarEstadoCorrida(data.corrida))
            .catch((e) => mostrarError(e.message || String(e)));
    }

    let reanudandoSilencioso = false;
    function reanudarBusquedaSilencioso() {
        if (!urls.reanudar || reanudandoSilencioso) return;
        reanudandoSilencioso = true;
        postJson(urls.reanudar, {})
            .then((data) => {
                if (data && data.corrida) {
                    aplicarEstadoCorrida(data.corrida);
                }
            })
            .catch(() => {})
            .finally(() => {
                setTimeout(() => { reanudandoSilencioso = false; }, 5000);
            });
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

    function mostrarAvisoPaso(msg) {
        relError.textContent = msg || '';
        relError.classList.remove('d-none', 'alert-danger');
        relError.classList.add('alert-warning');
    }

    function mostrarErrorFatal(msg) {
        relError.textContent = msg || 'Error en la consulta.';
        relError.classList.remove('d-none', 'alert-warning');
        relError.classList.add('alert-danger');
    }

    const BADGE_PASO = {
        ok: 'text-bg-success',
        ok_reintento: 'text-bg-success',
        fallo_reintentara: 'text-bg-warning',
        fallo_definitivo: 'text-bg-danger',
        en_curso: 'text-bg-primary',
        pendiente: 'text-bg-secondary',
        cancelado: 'text-bg-dark',
    };

    function formatearDia(fecha) {
        if (!fecha) return '—';
        const [anio, mes, dia] = String(fecha).split('-');
        return (anio && mes && dia) ? `${dia}-${mes}-${anio}` : String(fecha);
    }

    function renderPasosCorrida(pasos) {
        if (!relPasos || !relPasosTbody) return;
        if (!Array.isArray(pasos) || pasos.length === 0) {
            relPasos.classList.add('d-none');
            return;
        }

        relPasos.classList.remove('d-none');
        const terminados = pasos.filter((p) => p.resultado && !['pendiente', 'en_curso'].includes(p.resultado)).length;
        if (relPasosContador) {
            relPasosContador.textContent = `${terminados}/${pasos.length}`;
        }

        relPasosTbody.innerHTML = '';
        pasos.forEach((paso, idx) => {
            const tr = document.createElement('tr');

            const tdNum = document.createElement('td');
            tdNum.className = 'text-muted tabular-nums';
            tdNum.textContent = String(idx + 1);

            const tdDia = document.createElement('td');
            tdDia.className = 'text-nowrap tabular-nums';
            tdDia.textContent = formatearDia(paso.fecha_busqueda);

            const tdRegion = document.createElement('td');
            tdRegion.className = 'text-nowrap';
            tdRegion.textContent = paso.region_nombre || (paso.region ? `Región ${paso.region}` : '—');

            const tdFrase = document.createElement('td');
            const frasePaso = paso.frase || '';
            tdFrase.textContent = (!frasePaso || frasePaso === '(todas)')
                ? 'todas las frases'
                : frasePaso;

            const tdEncontradas = document.createElement('td');
            tdEncontradas.className = 'text-end tabular-nums';
            if (['pendiente', 'en_curso', 'cancelado'].includes(paso.resultado) || paso.encontradas === null || paso.encontradas === undefined) {
                tdEncontradas.textContent = '—';
                tdEncontradas.classList.add('text-muted');
            } else {
                tdEncontradas.textContent = String(Number(paso.encontradas) || 0);
            }

            const tdTiempo = document.createElement('td');
            tdTiempo.className = 'text-end text-nowrap tabular-nums';
            const duracionTexto = paso.duracion_texto
                || (paso.duracion_segundos != null ? formatearDuracionSegs(paso.duracion_segundos) : null);
            if (duracionTexto) {
                tdTiempo.textContent = duracionTexto;
            } else {
                tdTiempo.textContent = '—';
                tdTiempo.classList.add('text-muted');
            }

            const tdResultado = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = `badge ${BADGE_PASO[paso.resultado] || 'text-bg-secondary'}`;
            badge.textContent = paso.etiqueta || 'Pendiente';
            tdResultado.appendChild(badge);
            if (paso.error) {
                const err = document.createElement('div');
                err.className = 'text-danger small mt-1';
                err.textContent = paso.error;
                tdResultado.appendChild(err);
            }

            tr.append(tdNum, tdDia, tdRegion, tdFrase, tdEncontradas, tdTiempo, tdResultado);
            relPasosTbody.appendChild(tr);
        });
    }

    const relPasosChevron = document.getElementById('rel-pasos-chevron');
    function setPasosPanelAbierto(abierto) {
        if (!relPasosPanel) return;
        relPasosPanel.classList.toggle('d-none', !abierto);
        if (relPasosToggle) {
            relPasosToggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        }
        if (relPasosChevron) {
            relPasosChevron.classList.toggle('bi-chevron-down', !abierto);
            relPasosChevron.classList.toggle('bi-chevron-up', abierto);
        }
    }
    if (relPasosToggle && relPasosPanel) {
        relPasosToggle.addEventListener('click', () => {
            setPasosPanelAbierto(relPasosPanel.classList.contains('d-none'));
        });
    }

    function aplicarEstadoCorrida(corrida) {
        if (!corrida) return;

        if (ultimaCorridaId !== corrida.id) {
            ultimaCorridaId = corrida.id;
            intentosCambioDia = 0;
        }

        estado.classList.remove('d-none');
        placeholder.classList.add('d-none');
        const inicio = corrida.inicio ? new Date(corrida.inicio) : null;
        const fin = corrida.fin ? new Date(corrida.fin) : null;
        inicioMs = inicio && !Number.isNaN(inicio.getTime()) ? inicio.getTime() : inicioMs;
        finMs = fin && !Number.isNaN(fin.getTime()) ? fin.getTime() : null;
        relInicio.textContent = inicio ? inicio.toLocaleTimeString('es-CL', { hour12: false }) : '—';
        relFin.textContent = fin ? fin.toLocaleTimeString('es-CL', { hour12: false }) : '—';
        if (relUsuario) {
            const usuario = String(corrida.usuario || '').trim();
            relUsuario.textContent = usuario !== '' ? usuario : 'sistema';
        }
        setProgreso(Number(corrida.progreso) || 0);
        const duracionTexto = corrida.duracion_texto
            || (corrida.duracion_segundos != null ? formatearDuracionSegs(corrida.duracion_segundos) : null)
            || (inicioMs ? formatearDuracionSegs(((finMs || Date.now()) - inicioMs) / 1000) : null);
        if (duracionTexto) {
            relDuracion.textContent = duracionTexto;
        } else {
            actualizarDuracion();
        }
        relDetalle.textContent = mensajeConTiempo(corrida.mensaje, !corrida.estado || corrida.estado === 'running' ? null : duracionTexto);

        porCodigo = new Map();
        cargarItems(corrida.items || []);
        sincronizarVisitasLocalesEnMapa();
        aplicarEstadoVinculo(corrida.vinculo || null);
        const activo = corrida.estado === 'running';
        const cambiandoDia = corrida.estado === 'completed'
            && Boolean(corrida.fecha_siguiente_pendiente)
            && intentosCambioDia < 30;
        const vinculoActivo = corrida.vinculo && corrida.vinculo.estado === 'running';
        // Mostrar el listado si hay filas o si la búsqueda está en curso (antes quedaba oculto
        // cuando listarGuardadasHoy() venía vacío en catch-up de días anteriores).
        if (resultados && (porCodigo.size > 0 || activo)) {
            resultados.classList.remove('d-none');
        }
        renderTabla();
        renderPasosCorrida(corrida.pasos_resumen || []);
        actualizarDebugDesdeCorrida(corrida);
        if (corrida.fecha_busqueda && relFecha) {
            relFecha.textContent = cambiandoDia
                ? `(finalizado ${formatearDia(corrida.fecha_busqueda)}; sigue ${formatearDia(corrida.fecha_siguiente_pendiente)})`
                : `(buscando ${formatearDia(corrida.fecha_busqueda)})`;
        }

        cancelado = corrida.estado === 'cancelled';
        setModoBusqueda(activo || cambiandoDia);
        relBar.classList.toggle('progress-bar-animated', activo || cambiandoDia);
        if (relProgresoWrap) {
            relProgresoWrap.classList.toggle('d-none', !(activo || cambiandoDia));
        }

        const fallidos = Number(corrida.pasos_fallidos) || 0;
        const ultimoError = corrida.ultimo_error && typeof corrida.ultimo_error === 'object'
            ? corrida.ultimo_error
            : null;
        if (corrida.reanudada_auto) {
            mostrarAvisoPaso(
                corrida.mensaje
                    || 'La búsqueda se retomó automáticamente desde el último paso guardado.',
            );
        } else if (corrida.worker_stalled) {
            mostrarAvisoPaso(
                'La búsqueda no avanza en el servidor (posible worker detenido o Mercado Público colgado). '
                + 'Se intentará retomar automáticamente; si no avanza, verifique RUN_QUEUE_WORKER=true en Render.',
            );
            if (urls.reanudar) {
                reanudarBusquedaSilencioso();
            }
        } else if (activo && ultimoError) {
            if (String(corrida.mensaje || '').toLowerCase().includes('fallido')) {
                mostrarAvisoPaso('Un paso falló en Mercado Público; la búsqueda sigue con la siguiente región o reintento.');
            } else {
                relError.classList.add('d-none');
            }
        } else if (!activo && fallidos > 0) {
            mostrarErrorFatal(`La búsqueda terminó con ${fallidos} paso(s) fallido(s). Los demás pasos sí fueron procesados.`);
        } else if (activo) {
            relError.classList.add('d-none');
        } else {
            relError.classList.add('d-none');
        }

        if (activo || cambiandoDia || vinculoActivo) {
            detenerPolling();
            if (cambiandoDia) {
                intentosCambioDia++;
                relDetalle.textContent = `Día ${formatearDia(corrida.fecha_busqueda)} terminado. Iniciando búsqueda del ${formatearDia(corrida.fecha_siguiente_pendiente)}…`;
            }
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

    function aplicarEstadoVinculo(vinculo) {
        const card = document.getElementById('vinculo-estado');
        const vinInicio = document.getElementById('vin-inicio');
        const vinFin = document.getElementById('vin-fin');
        const vinDuracion = document.getElementById('vin-duracion');
        const vinBar = document.getElementById('vin-progreso-bar');
        const vinWrap = document.getElementById('vin-progreso-wrap');
        const vinDetalle = document.getElementById('vin-detalle');
        const vinTxt = document.getElementById('vin-progreso-txt');
        if (!card) {
            return;
        }
        if (!vinculo) {
            card.classList.add('d-none');
            return;
        }

        card.classList.remove('d-none');
        const inicio = vinculo.inicio ? new Date(vinculo.inicio) : null;
        const fin = vinculo.fin ? new Date(vinculo.fin) : null;
        if (vinInicio) {
            vinInicio.textContent = inicio && !Number.isNaN(inicio.getTime())
                ? inicio.toLocaleTimeString('es-CL', { hour12: false })
                : '—';
        }
        if (vinFin) {
            vinFin.textContent = fin && !Number.isNaN(fin.getTime())
                ? fin.toLocaleTimeString('es-CL', { hour12: false })
                : '—';
        }
        const duracionTexto = vinculo.duracion_texto
            || (vinculo.duracion_segundos != null ? formatearDuracionSegs(vinculo.duracion_segundos) : null);
        if (vinDuracion) {
            vinDuracion.textContent = duracionTexto || '—';
        }
        const progreso = Number(vinculo.progreso) || 0;
        const total = Number(vinculo.total_pasos) || 0;
        const hechos = Number(vinculo.pasos_procesados) || 0;
        if (vinTxt) {
            vinTxt.textContent = `${hechos}/${total}`;
        }
        if (vinBar) {
            vinBar.style.width = `${progreso}%`;
            vinBar.textContent = `${progreso}%`;
            vinBar.classList.toggle('progress-bar-animated', vinculo.estado === 'running');
        }
        if (vinWrap) {
            vinWrap.classList.toggle('d-none', vinculo.estado !== 'running' && progreso >= 100);
        }
        if (vinDetalle) {
            vinDetalle.textContent = String(vinculo.mensaje || 'Vinculación con maestro…');
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
        if (relProgresoWrap) relProgresoWrap.classList.remove('d-none');
        relDetalle.textContent = 'Iniciando consulta a Mercado Público…';
        renderTabla();

        try {
            const data = await postJson(urls.iniciar, {});
            aplicarEstadoCorrida(data.corrida);
        } catch (e) {
            relBar.classList.remove('progress-bar-animated');
            if (relProgresoWrap) relProgresoWrap.classList.add('d-none');
            mostrarError(e.message || String(e));
            relDetalle.textContent = 'No se pudo encolar la búsqueda.';
            renderTabla();
            setModoBusqueda(false);
        }
    }

    // Al abrir: restaura filtros del usuario y muestra lo ya grabado.
    restaurarFiltros();
    if (Array.isArray(guardadasIniciales) && guardadasIniciales.length > 0) {
        cargarItems(guardadasIniciales);
        sincronizarVisitasLocalesEnMapa();
        if (fechaBusquedaInicial && relFecha) {
            relFecha.textContent = `(${fechaBusquedaInicial})`;
        }
        if (estado && relDetalle) {
            estado.classList.remove('d-none');
            relDetalle.textContent = puedeBuscar
                ? `${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} vigentes. Pulse Buscar para consultar de nuevo.`
                : `${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} sincronizadas vigentes.`;
            setProgreso(100);
            if (relProgresoWrap) relProgresoWrap.classList.add('d-none');
        }
        if (resultados) resultados.classList.remove('d-none');
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
