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
            <div class="d-flex flex-column align-items-end gap-1">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
                    <button type="button" id="btn-buscar-oportunidades" class="btn btn-primary btn-sm" @disabled($palabras===[])>
                        <i class="bi bi-search"></i> Buscar cotizaciones
                    </button>
                    <button type="button" id="btn-cancelar-oportunidades" class="btn btn-outline-danger btn-sm d-none">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                </div>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="checkbox" id="chk-ventana-completa" value="1">
                    <label class="form-check-label small text-muted" for="chk-ventana-completa" title="Ignora el incremental y consulta desde las 00:00 del día (útil si se saltó un tramo)">
                        Ventana completa del día
                    </label>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-1 justify-content-end">
                    <label class="small text-muted mb-0" for="inp-cambio-desde" title="Opcional: forzar inicio (ej. 2026-07-31 17:36)">Desde</label>
                    <input type="datetime-local" id="inp-cambio-desde" class="form-control form-control-sm" style="width: 13rem;"
                        title="Si se completa, fuerza cambio_desde (tiene prioridad sobre incremental)">
                </div>
            </div>
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
                                <th class="text-nowrap text-end">P&aacute;gina</th>
                                <th class="text-nowrap text-end">T. match</th>
                                <th class="text-nowrap text-end">Tiempo</th>
                                <th class="text-nowrap">Resultado</th>
                            </tr>
                        </thead>
                        <tbody id="rel-pasos-tbody"></tbody>
                    </table>
                </div>
                <div id="rel-eventos" class="mt-3 d-none">
                    <button type="button" id="rel-eventos-toggle" class="btn btn-sm btn-outline-secondary mb-2"
                        aria-expanded="true" aria-controls="rel-eventos-panel">
                        <i class="bi bi-activity"></i>
                        Eventos <span id="rel-eventos-contador" class="badge text-bg-secondary ms-1">0</span>
                        <i id="rel-eventos-chevron" class="bi bi-chevron-up ms-1"></i>
                    </button>
                    <div id="rel-eventos-panel" class="border rounded bg-light p-2"
                        style="max-height: 220px; overflow-y: auto;">
                        <ul id="rel-eventos-lista" class="list-unstyled small mb-0"></ul>
                    </div>
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

    <div id="vinculo-aviso" class="alert alert-warning d-none mb-3" role="status">
        <div id="vinculo-aviso-texto" class="small mb-0"></div>
    </div>

    <div id="vinculo-estado" class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <div id="vinculo-meta" class="d-flex flex-wrap gap-3 align-items-center small mb-2">
                <div class="fw-semibold text-nowrap">
                    <i class="bi bi-link-45deg"></i> Vinculaciones internas
                </div>
                <div class="text-nowrap vin-meta-extra d-none">
                    <i class="bi bi-clock"></i>
                    Inicio: <strong id="vin-inicio" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap vin-meta-extra d-none">
                    <i class="bi bi-flag"></i>
                    Fin: <strong id="vin-fin" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap vin-meta-extra d-none">
                    <i class="bi bi-hourglass-split"></i>
                    Tiempo: <strong id="vin-duracion" class="tabular-nums">—</strong>
                </div>
                <div class="text-nowrap vin-meta-extra d-none">
                    <i class="bi bi-activity"></i>
                    &Uacute;ltima: <strong id="vin-ultima" class="tabular-nums">—</strong>
                    <span id="vin-ultima-hace" class="text-muted ms-1"></span>
                </div>
                <div class="text-nowrap ms-auto vin-meta-extra d-none">
                    <span id="vin-progreso-txt" class="badge text-bg-secondary">0/0</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <button type="button" id="btn-iniciar-vinculo" class="btn btn-success btn-sm" data-no-loader title="Proceso aparte de la b&uacute;squeda; puede correr en paralelo">
                    <i class="bi bi-link-45deg"></i> Procesar vinculaciones
                    <span id="btn-iniciar-vinculo-badge" class="badge text-bg-light text-dark ms-1 d-none">0</span>
                </button>
                <button type="button" id="btn-cancelar-vinculo" class="btn btn-outline-danger btn-sm d-none" data-no-loader title="Detiene la vinculaci&oacute;n para poder procesarla de nuevo">
                    <i class="bi bi-x-circle"></i> Cancelar vinculaciones
                </button>
            </div>
            <div id="vin-progreso-wrap" class="progress mb-2 d-none" style="height: 0.75rem;">
                <div id="vin-progreso-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                    role="progressbar" style="width: 0%">0%</div>
            </div>
            <div id="vin-detalle" class="small text-muted mb-0">Pulse <strong>Procesar vinculaciones</strong> para vincular cotizaciones al maestro.</div>
            <div id="vin-regiones-wrap" class="mt-3 d-none">
                <button type="button" id="vin-regiones-toggle" class="btn btn-sm btn-outline-secondary mb-2"
                    aria-expanded="false" aria-controls="vin-regiones-panel">
                    <i class="bi bi-list-check"></i>
                    Detalle por regi&oacute;n <span id="vin-regiones-contador" class="badge text-bg-secondary ms-1">0</span>
                    <i id="vin-regiones-chevron" class="bi bi-chevron-down ms-1"></i>
                </button>
                <div id="vin-regiones-panel" class="table-responsive d-none" style="max-height: 320px; overflow-y: auto;">
                    <table class="table table-sm table-striped align-middle small mb-0">
                        <thead class="table-light" style="position: sticky; top: 0;">
                            <tr>
                                <th class="text-nowrap">Regi&oacute;n</th>
                                <th class="text-nowrap text-end">Cotizaciones</th>
                                <th class="text-nowrap text-end">Procesadas</th>
                                <th class="text-nowrap text-end">Proceso %</th>
                                <th class="text-nowrap text-end">Productos vinc.</th>
                                <th class="text-nowrap text-end">Vinc. %</th>
                            </tr>
                        </thead>
                        <tbody id="vin-regiones-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3" id="sync-par-paneles">
        <div class="col-md-6">
            <div id="sync-cotizaciones-estado" class="card shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="d-flex flex-wrap gap-2 align-items-center small mb-2">
                        <div class="fw-semibold text-nowrap">
                            <i class="bi bi-cloud-upload"></i> Sync cotizaciones al par
                        </div>
                        <span id="sync-cot-badge" class="badge text-bg-secondary">0 pendientes</span>
                        <span id="sync-cot-peer" class="text-muted ms-auto"></span>
                    </div>
                    <div id="sync-cot-resumen" class="small text-muted mb-2">Cola vac&iacute;a.</div>
                    <div id="sync-cot-ultimo-proceso" class="small mb-2 d-none"></div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <button type="button" id="btn-sync-cotizaciones" class="btn btn-outline-primary btn-sm" data-no-loader
                            title="Reintenta enviar cotizaciones pendientes al sitio par">
                            <i class="bi bi-arrow-repeat"></i> Sincronizar cotizaciones
                        </button>
                        <button type="button" id="sync-cot-detalle-toggle" class="btn btn-sm btn-outline-secondary"
                            aria-expanded="false" aria-controls="sync-cot-detalle-panel" data-no-loader>
                            <i class="bi bi-list-ul"></i>
                            Detalle <span id="sync-cot-detalle-contador" class="badge text-bg-secondary ms-1">0</span>
                            <i id="sync-cot-detalle-chevron" class="bi bi-chevron-down ms-1"></i>
                        </button>
                    </div>
                    <div id="sync-cot-detalle-panel" class="d-none">
                        <div id="sync-cot-error" class="alert alert-warning py-1 px-2 small d-none mb-2"></div>
                        <div id="sync-cot-detalle" class="small text-muted mb-2">Sin detalle.</div>
                        <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm table-striped align-middle small mb-0">
                                <thead class="table-light" style="position: sticky; top: 0;">
                                    <tr>
                                        <th class="text-nowrap">#</th>
                                        <th class="text-nowrap">C&oacute;digos</th>
                                        <th class="text-nowrap text-end">Items</th>
                                        <th class="text-nowrap text-end">Intentos</th>
                                        <th class="text-nowrap">Error</th>
                                        <th class="text-nowrap">Actualizado</th>
                                    </tr>
                                </thead>
                                <tbody id="sync-cot-lotes-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div id="sync-vinculaciones-estado" class="card shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="d-flex flex-wrap gap-2 align-items-center small mb-2">
                        <div class="fw-semibold text-nowrap">
                            <i class="bi bi-cloud-check"></i> Sync vinculaciones al par
                        </div>
                        <span id="sync-vin-badge" class="badge text-bg-secondary">0 pendientes</span>
                        <span id="sync-vin-peer" class="text-muted ms-auto"></span>
                    </div>
                    <div id="sync-vin-resumen" class="small text-muted mb-2">Cola vac&iacute;a.</div>
                    <div id="sync-vin-ultimo-proceso" class="small mb-2 d-none"></div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <button type="button" id="btn-sync-vinculaciones" class="btn btn-outline-success btn-sm" data-no-loader
                            title="Reintenta cola pendiente y reenvía al par las vinculaciones con preview. Marca OK/error/omitido por cotización.">
                            <i class="bi bi-arrow-repeat"></i> Sincronizar vinculaciones
                        </button>
                        <button type="button" id="sync-vin-detalle-toggle" class="btn btn-sm btn-outline-secondary"
                            aria-expanded="false" aria-controls="sync-vin-detalle-panel" data-no-loader>
                            <i class="bi bi-list-ul"></i>
                            Detalle <span id="sync-vin-detalle-contador" class="badge text-bg-secondary ms-1">0</span>
                            <i id="sync-vin-detalle-chevron" class="bi bi-chevron-down ms-1"></i>
                        </button>
                    </div>
                    <div id="sync-vin-detalle-panel" class="d-none">
                        <div id="sync-vin-error" class="alert alert-warning py-1 px-2 small d-none mb-2"></div>
                        <div id="sync-vin-detalle" class="small text-muted mb-2">Sin detalle.</div>
                        <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm table-striped align-middle small mb-0">
                                <thead class="table-light" style="position: sticky; top: 0;">
                                    <tr>
                                        <th class="text-nowrap">#</th>
                                        <th class="text-nowrap">C&oacute;digos</th>
                                        <th class="text-nowrap text-end">Items</th>
                                        <th class="text-nowrap text-end">Intentos</th>
                                        <th class="text-nowrap">Error</th>
                                        <th class="text-nowrap">Actualizado</th>
                                    </tr>
                                </thead>
                                <tbody id="sync-vin-lotes-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
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
                <div class="col-6 col-md-3 col-xl-2">
                    <label for="filtro-region" class="form-label small mb-1">Regi&oacute;n</label>
                    <select id="filtro-region" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        @foreach(($regionesFiltro ?? []) as $codigoRegion => $nombreRegion)
                        <option value="{{ (int) $codigoRegion }}">{{ $nombreRegion }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label for="filtro-organismo" class="form-label small mb-1">Organismo</label>
                    <input type="search" id="filtro-organismo" class="form-control form-control-sm"
                        placeholder="Buscar organismo…" autocomplete="off">
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label for="filtro-palabra-clave" class="form-label small mb-1">Palabra clave</label>
                    <input type="search" id="filtro-palabra-clave" class="form-control form-control-sm"
                        placeholder="Ej. papel, l&aacute;piz, c&oacute;digo…" autocomplete="off">
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label for="filtro-codigo" class="form-label small mb-1">C&oacute;digo cotizaci&oacute;n</label>
                    <input type="search" id="filtro-codigo" class="form-control form-control-sm"
                        placeholder="Ej. 1234-56-COT25" autocomplete="off">
                </div>
                <div class="col-6 col-md-4 col-xl-3">
                    <label class="form-label small mb-1">% v&iacute;nculo</label>
                    <div class="d-flex gap-1 align-items-center" title="Rango de vinculaci&oacute;n al maestro. Vac&iacute;o = todas.">
                        <input type="number" id="filtro-vinculo-desde" class="form-control form-control-sm"
                            min="0" max="100" step="1" placeholder="Desde" inputmode="numeric" autocomplete="off">
                        <span class="small text-muted flex-shrink-0">a</span>
                        <input type="number" id="filtro-vinculo-hasta" class="form-control form-control-sm"
                            min="0" max="100" step="1" placeholder="Hasta" inputmode="numeric" autocomplete="off">
                    </div>
                    <button type="button" id="filtro-cierre-24h" class="btn btn-sm mt-1 w-100 filtro-cierre-24h"
                        data-no-loader
                        title="Mostrar solo cotizaciones que cierran en las pr&oacute;ximas 24 horas"
                        aria-pressed="false">
                        <i class="bi bi-alarm"></i> Cierran en 24 h
                        (<span id="filtro-cierre-24h-count" class="filtro-cierre-24h-count">0</span>)
                    </button>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label small mb-1">Fecha publicaci&oacute;n</label>
                    <div class="d-flex gap-1 align-items-center" title="Rango de fecha de publicaci&oacute;n. Vac&iacute;o = todas.">
                        <input type="date" id="filtro-pub-desde" class="form-control form-control-sm"
                            autocomplete="off" aria-label="Publicaci&oacute;n desde">
                        <span class="small text-muted flex-shrink-0">a</span>
                        <input type="date" id="filtro-pub-hasta" class="form-control form-control-sm"
                            autocomplete="off" aria-label="Publicaci&oacute;n hasta">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label small mb-1">Fecha cierre</label>
                    <div class="d-flex gap-1 align-items-center" title="Rango de fecha de cierre. Vac&iacute;o = todas.">
                        <input type="date" id="filtro-cierre-desde" class="form-control form-control-sm"
                            autocomplete="off" aria-label="Cierre desde">
                        <span class="small text-muted flex-shrink-0">a</span>
                        <input type="date" id="filtro-cierre-hasta" class="form-control form-control-sm"
                            autocomplete="off" aria-label="Cierre hasta">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-2 d-flex flex-wrap gap-2 align-items-end">
                    <button type="button" id="btn-filtrar-oportunidades" class="btn btn-primary btn-sm" data-no-loader>
                        <i class="bi bi-funnel"></i> Filtrar
                    </button>
                    <button type="button" id="btn-descargar-csv" class="btn btn-outline-success btn-sm" data-no-loader>
                        <i class="bi bi-download"></i> CSV
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
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="small text-muted" id="oportunidad-footer">
                Use <strong>Ir a cotizar</strong> en la fila deseada.
                @if($puedeBuscar)
                Los resultados del d&iacute;a quedan grabados y se sincronizan con el sitio par.
                @else
                Resultados sincronizados desde el sitio de b&uacute;squeda.
                @endif
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label for="oportunidad-por-pagina" class="form-label small mb-0 text-nowrap">Registros por página:</label>
                    <select id="oportunidad-por-pagina" class="form-select form-select-sm w-auto">
                        <option value="20">20</option>
                        <option value="40">40</option>
                        <option value="60">60</option>
                    </select>
                </div>
            </div>
            <nav id="oportunidad-paginacion" class="d-flex justify-content-center" aria-label="Paginaci&oacute;n de oportunidades">
                <ul class="pagination mb-0" id="oportunidad-paginacion-lista"></ul>
            </nav>
        </div>
    </div>

    <div class="modal fade" id="modal-vinculo-productos" tabindex="-1" aria-labelledby="modal-vinculo-productos-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title fs-6" id="modal-vinculo-productos-label">Productos y vinculaci&oacute;n</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p id="modal-vinculo-resumen" class="small text-muted mb-2"></p>
                    <div id="modal-vinculo-loading" class="text-center text-muted py-4 d-none">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <span id="modal-vinculo-loading-text" class="ms-2">Cargando productos…</span>
                    </div>
                    <div id="modal-vinculo-error" class="alert alert-warning py-2 small d-none mb-0"></div>
                    <div id="modal-vinculo-tabla-wrap" class="table-responsive d-none">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Producto MP</th>
                                    <th class="text-end text-nowrap">Cant.</th>
                                    <th>Estado</th>
                                    <th>Producto maestro</th>
                                    <th class="text-end text-nowrap">Precio</th>
                                </tr>
                            </thead>
                            <tbody id="modal-vinculo-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
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

    .filtro-cierre-24h {
        --bs-btn-color: #c2410c;
        --bs-btn-bg: #fff7ed;
        --bs-btn-border-color: #fb923c;
        --bs-btn-hover-color: #9a3412;
        --bs-btn-hover-bg: #ffedd5;
        --bs-btn-hover-border-color: #f97316;
        --bs-btn-active-color: #fff;
        --bs-btn-active-bg: #ea580c;
        --bs-btn-active-border-color: #c2410c;
        font-weight: 600;
    }

    .filtro-cierre-24h .filtro-cierre-24h-count {
        color: inherit;
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1;
        vertical-align: baseline;
    }

    .filtro-cierre-24h.is-active {
        color: #fff;
        background-color: #ea580c;
        border-color: #c2410c;
    }
</style>
@endsection

@push('scripts')
<script>
    function ocultarPageLoaderOportunidades() {
        try {
            sessionStorage.removeItem('page-loader-pending');
        } catch (_e) {
            // sessionStorage no disponible
        }
        if (window.PageLoader?.hide) {
            window.PageLoader.hide();
        }
    }
    document.addEventListener('DOMContentLoaded', ocultarPageLoaderOportunidades);
    window.addEventListener('load', ocultarPageLoaderOportunidades);
</script>
<script>
    (function() {
        const puedeBuscar = @json((bool) $puedeBuscar);
        const puedeEliminar = @json((bool) ($puedeEliminar ?? false));
        const urls = {
            iniciar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.iniciar') : ''),
            estado: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.estado') : ''),
            cancelar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.cancelar') : ''),
            reanudar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.reanudar') : ''),
            iniciarVinculo: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.iniciar-vinculo') : ''),
            cancelarVinculo: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.cancelar-vinculo') : ''),
            syncPar: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.sync-par') : ''),
            syncParInicio: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.sync-par-inicio') : ''),
            syncParLote: @json($puedeBuscar ? route('admin.oportunidades.para-cotizar.sync-par-lote') : ''),
            detalleVinculoBase: @json(url()->route('admin.oportunidades.para-cotizar.detalle-vinculo', ['codigo' => '__CODIGO__'])),
            vincularCodigo: @json(route('admin.oportunidades.para-cotizar.vincular-codigo')),
            visita: @json(route('admin.oportunidades.para-cotizar.visita')),
            eliminar: @json(($puedeEliminar ?? false) ? route('admin.oportunidades.para-cotizar.destroy') : ''),
            cotizarBase: @json(route('admin.cotizaciones.create')),
        };
        const syncParInicial = @json($syncPar ?? null);
        const filtrosUserId = @json((int)($filtrosUserId ?? 0));
        const FILTROS_STORAGE_KEY = filtrosUserId > 0 ?
            `cotiz.oportunidades.filtros.${filtrosUserId}` :
            '';
        const VISITAS_STORAGE_KEY = filtrosUserId > 0 ?
            `cotiz.oportunidades.visitas.${filtrosUserId}` :
            '';
        const mpApi = {
            baseUrl: @json($mpBaseUrl ?? ''),
            path: @json($mpPath ?? '/v2/compra-agil'),
        };
        // Orden de MERCADOPUBLICO_REGIONES (mismo que la búsqueda / plan de vinculación).
        const regionesOrdenConfig = @json(array_values(array_map('intval', \App\Services\CompraAgilRegionScope::regionesIncluidas())));
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const btn = document.getElementById('btn-buscar-oportunidades');
        const btnCancelar = document.getElementById('btn-cancelar-oportunidades');
        const btnIniciarVinculo = document.getElementById('btn-iniciar-vinculo');
        const btnIniciarVinculoBadge = document.getElementById('btn-iniciar-vinculo-badge');
        const btnCancelarVinculo = document.getElementById('btn-cancelar-vinculo');
        const vinculoAviso = document.getElementById('vinculo-aviso');
        const vinculoAvisoTexto = document.getElementById('vinculo-aviso-texto');
        const vinculoMeta = document.getElementById('vinculo-meta');
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
        const relEventos = document.getElementById('rel-eventos');
        const relEventosToggle = document.getElementById('rel-eventos-toggle');
        const relEventosPanel = document.getElementById('rel-eventos-panel');
        const relEventosLista = document.getElementById('rel-eventos-lista');
        const relEventosContador = document.getElementById('rel-eventos-contador');
        const relEventosChevron = document.getElementById('rel-eventos-chevron');
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
        const filtroVinculoDesde = document.getElementById('filtro-vinculo-desde');
        const filtroVinculoHasta = document.getElementById('filtro-vinculo-hasta');
        const filtroCodigo = document.getElementById('filtro-codigo');
        const filtroPubDesde = document.getElementById('filtro-pub-desde');
        const filtroPubHasta = document.getElementById('filtro-pub-hasta');
        const filtroCierreDesde = document.getElementById('filtro-cierre-desde');
        const filtroCierreHasta = document.getElementById('filtro-cierre-hasta');
        const btnFiltroCierre24h = document.getElementById('filtro-cierre-24h');
        const badgeFiltroCierre24h = document.getElementById('filtro-cierre-24h-count');
        const btnFiltrarOportunidades = document.getElementById('btn-filtrar-oportunidades');
        let filtroCierre24hActivo = false;
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
        const PAGE_SIZE_STORAGE_KEY = 'cotiz_por_pagina_admin.oportunidades.para-cotizar';
        const PAGE_SIZE_OPCIONES = [20, 40, 60];
        function normalizarPageSize(valor) {
            const n = Number.parseInt(String(valor), 10);
            return PAGE_SIZE_OPCIONES.includes(n) ? n : 20;
        }
        let PAGE_SIZE = (() => {
            try {
                const guardado = localStorage.getItem(PAGE_SIZE_STORAGE_KEY);
                return guardado !== null ? normalizarPageSize(guardado) : 20;
            } catch (_e) {
                return 20;
            }
        })();
        const selectPorPagina = document.getElementById('oportunidad-por-pagina');
        if (selectPorPagina) {
            selectPorPagina.value = String(PAGE_SIZE);
            selectPorPagina.addEventListener('change', () => {
                PAGE_SIZE = normalizarPageSize(selectPorPagina.value);
                try {
                    localStorage.setItem(PAGE_SIZE_STORAGE_KEY, String(PAGE_SIZE));
                } catch (_e) {
                    // Ignorar
                }
                paginaActual = 1;
                renderTabla();
            });
        }
        let paginaActual = 1;
        let sortState = {
            column: 'presupuesto',
            direction: 'desc'
        };
        let filtroOrganismoTimer = null;
        /** Criterio de palabra clave aplicado al pulsar Filtrar (no al tipear). */
        let filtroPalabraClaveAplicado = '';

        const guardadasIniciales = @json($guardadas ?? []);
        const fechaBusquedaInicial = @json($fechaBusqueda ?? null);
        const corridaInicial = @json($corridaEstado ?? null);
        const vinculoPendientesInicial = @json((int)($vinculoPendientes ?? 0));

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
            return new Date().toLocaleTimeString('es-CL', {
                hour12: false
            });
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
                const texto = String(va).localeCompare(String(vb), 'es', {
                    sensitivity: 'base'
                });
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

        /** Frases de coincidencia en orden alfabético (es). */
        function frasesOrdenadas(lista) {
            if (!Array.isArray(lista)) {
                return [];
            }
            return lista
                .map((f) => String(f || '').trim())
                .filter(Boolean)
                .sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }));
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

        function itemVinculoProcesado(item) {
            if (!item) {
                return false;
            }
            if (item.vinculo_estado === 'procesada') {
                return true;
            }
            if (item.vinculo_estado === 'fallida' || item.vinculo_estado === 'pendiente') {
                return false;
            }
            const completo = item.vinculo_completo === true
                || item.vinculo_completo === 1
                || item.vinculo_completo === '1';
            if (!completo) {
                return false;
            }
            if (item.tiene_vinculo_preview === false) {
                return false;
            }
            if (item.tiene_vinculo_preview === true) {
                return true;
            }
            const preview = item.vinculo_preview_json;
            if (preview == null || preview === '') {
                return false;
            }
            return true;
        }

        function itemNecesitaVincular(item) {
            return !itemVinculoProcesado(item);
        }

        function htmlEstadoVinculoListado(item) {
            const estado = String(item?.vinculo_estado || '').trim();
            let html = '';
            if (itemVinculoProcesado(item)) {
                const vinc = Number(item.productos_vinculados) || 0;
                const tot = Number(item.cantidad_productos) || 0;
                const pct = porcentajeVinculoItem(item) ?? 0;
                html = `<div class="opc-meta mt-1">Vinculados: <strong class="tabular-nums">${escapeHtml(String(vinc))}/${escapeHtml(String(tot))}</strong> (${escapeHtml(String(pct))}%)</div>`;
            } else if (estado === 'fallida' || (item?.vinculo_error && String(item.vinculo_error).trim() !== '')) {
                const msg = String(item.vinculo_error || 'Error al vincular').trim();
                html = `<div class="mt-1">
                    <span class="badge text-bg-danger">Vinculación fallida</span>
                    <div class="opc-meta text-danger mt-1" title="${escapeHtml(msg)}">${escapeHtml(msg)}</div>
                </div>`;
            } else {
                html = '<div class="mt-1"><span class="badge text-bg-warning">Vinculación pendiente</span></div>';
            }
            // Sync al par solo aplica en el sitio ANALISIS_ADMIN (origen del relay).
            return html + (puedeBuscar ? htmlEstadoSyncParListado(item) : '');
        }

        function htmlEstadoSyncParListado(item) {
            if (!puedeBuscar) {
                return '';
            }
            const syncEstado = String(item?.sync_par_estado || '').trim();
            if (!syncEstado) {
                return '';
            }
            const cuando = item?.sync_par_at ? formatearFechaSync(item.sync_par_at) : '';
            const err = String(item?.sync_par_error || '').trim();
            if (syncEstado === 'ok') {
                return `<div class="opc-meta mt-1 text-success" title="Réplica al sitio par OK">
                    <i class="bi bi-cloud-check"></i> Par: OK${cuando ? ' · ' + escapeHtml(cuando) : ''}
                </div>`;
            }
            if (syncEstado === 'error') {
                const msg = err || 'Error al sincronizar al par';
                return `<div class="mt-1">
                    <span class="badge text-bg-danger">Sync par falló</span>
                    <div class="opc-meta text-danger mt-1" title="${escapeHtml(msg)}">${escapeHtml(msg)}</div>
                </div>`;
            }
            if (syncEstado === 'omitido') {
                const msg = err || 'Sin preview; no se puede replicar como procesada';
                return `<div class="mt-1">
                    <span class="badge text-bg-secondary">Sync par omitido</span>
                    <div class="opc-meta text-muted mt-1" title="${escapeHtml(msg)}">${escapeHtml(msg)}</div>
                </div>`;
            }
            // pendiente
            return `<div class="mt-1">
                <span class="badge text-bg-warning">Sync par pendiente</span>
            </div>`;
        }

        function porcentajeVinculoItem(item) {
            if (!itemVinculoProcesado(item)) {
                return null;
            }
            if (item.porcentaje_vinculo != null && item.porcentaje_vinculo !== '') {
                return Number(item.porcentaje_vinculo) || 0;
            }
            const vinc = Number(item.productos_vinculados) || 0;
            const tot = Number(item.cantidad_productos) || 0;
            return tot > 0 ? Math.round((vinc / tot) * 100) : 0;
        }

        function parsePorcentajeFiltro(valor) {
            const raw = String(valor ?? '').trim();
            if (raw === '') {
                return null;
            }
            const n = Number(raw);
            if (!Number.isFinite(n)) {
                return null;
            }
            return Math.max(0, Math.min(100, Math.round(n)));
        }

        function rangoVinculoFiltro() {
            let desde = parsePorcentajeFiltro(filtroVinculoDesde ? filtroVinculoDesde.value : '');
            let hasta = parsePorcentajeFiltro(filtroVinculoHasta ? filtroVinculoHasta.value : '');
            if (desde == null && hasta == null) {
                return null;
            }
            if (desde != null && hasta != null && desde > hasta) {
                const tmp = desde;
                desde = hasta;
                hasta = tmp;
            }
            return { desde, hasta };
        }

        function itemCoincideVinculo(item, rango) {
            if (!rango) {
                return true;
            }
            if (!itemVinculoProcesado(item)) {
                return false;
            }
            const pct = porcentajeVinculoItem(item);
            if (pct == null || !Number.isFinite(pct)) {
                return false;
            }
            if (rango.desde != null && pct < rango.desde) {
                return false;
            }
            if (rango.hasta != null && pct > rango.hasta) {
                return false;
            }
            return true;
        }

        function itemCoincideCodigo(item, criterio) {
            if (criterio === '') {
                return true;
            }
            return normalizarTexto(item.codigo).includes(criterio);
        }

        function fechaItemMs(valor) {
            const raw = String(valor || '').trim();
            if (raw === '') {
                return null;
            }
            const d = new Date(raw);
            return Number.isNaN(d.getTime()) ? null : d.getTime();
        }

        function rangoFechaFiltro(inputDesde, inputHasta) {
            let rawDesde = inputDesde ? String(inputDesde.value || '').trim() : '';
            let rawHasta = inputHasta ? String(inputHasta.value || '').trim() : '';
            if (rawDesde === '' && rawHasta === '') {
                return null;
            }
            if (rawDesde !== '' && rawHasta !== '' && rawDesde > rawHasta) {
                const tmp = rawDesde;
                rawDesde = rawHasta;
                rawHasta = tmp;
            }
            const desde = rawDesde !== '' ? new Date(`${rawDesde}T00:00:00`).getTime() : null;
            const hasta = rawHasta !== '' ? new Date(`${rawHasta}T23:59:59.999`).getTime() : null;
            return { desde, hasta };
        }

        function itemCoincideFecha(valor, rango) {
            if (!rango) {
                return true;
            }
            const ms = fechaItemMs(valor);
            if (ms == null) {
                return false;
            }
            if (rango.desde != null && ms < rango.desde) {
                return false;
            }
            if (rango.hasta != null && ms > rango.hasta) {
                return false;
            }
            return true;
        }

        function itemCierraEn24h(item, ahoraMs, hastaMs) {
            const ms = fechaItemMs(item.fecha_cierre);
            if (ms == null) {
                return false;
            }
            return ms >= ahoraMs && ms <= hastaMs;
        }

        function contarCierre24h() {
            const ahoraMs = Date.now();
            const hastaMs = ahoraMs + (24 * 60 * 60 * 1000);
            let n = 0;
            porCodigo.forEach((item) => {
                if (itemCierraEn24h(item, ahoraMs, hastaMs)) {
                    n += 1;
                }
            });
            return n;
        }

        function actualizarUiCierre24h() {
            const n = contarCierre24h();
            if (badgeFiltroCierre24h) {
                badgeFiltroCierre24h.textContent = String(n);
            }
            if (btnFiltroCierre24h) {
                btnFiltroCierre24h.classList.toggle('is-active', filtroCierre24hActivo);
                btnFiltroCierre24h.setAttribute('aria-pressed', filtroCierre24hActivo ? 'true' : 'false');
                btnFiltroCierre24h.title = filtroCierre24hActivo
                    ? 'Quitar filtro: mostrar todas las cotizaciones'
                    : 'Mostrar solo cotizaciones que cierran en las próximas 24 horas';
            }
        }

        function itemsFiltrados() {
            const regionSel = filtroRegion ? String(filtroRegion.value || '').trim() : '';
            const organismoSel = filtroOrganismo ? normalizarTexto(filtroOrganismo.value) : '';
            const palabraSel = filtroPalabraClaveAplicado;
            const codigoSel = filtroCodigo ? normalizarTexto(filtroCodigo.value) : '';
            const vinculoRango = rangoVinculoFiltro();
            const pubRango = rangoFechaFiltro(filtroPubDesde, filtroPubHasta);
            const cierreRango = rangoFechaFiltro(filtroCierreDesde, filtroCierreHasta);
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
            if (codigoSel !== '') {
                items = items.filter((item) => itemCoincideCodigo(item, codigoSel));
            }
            if (vinculoRango) {
                items = items.filter((item) => itemCoincideVinculo(item, vinculoRango));
            }
            if (pubRango) {
                items = items.filter((item) => itemCoincideFecha(item.fecha_publicacion, pubRango));
            }
            if (cierreRango) {
                items = items.filter((item) => itemCoincideFecha(item.fecha_cierre, cierreRango));
            }
            if (filtroCierre24hActivo) {
                const ahoraMs = Date.now();
                const hastaMs = ahoraMs + (24 * 60 * 60 * 1000);
                items = items.filter((item) => itemCierraEn24h(item, ahoraMs, hastaMs));
            }
            return items.sort(comparar);
        }

        function aplicarFiltros() {
            filtroPalabraClaveAplicado = filtroPalabraClave ?
                normalizarTexto(filtroPalabraClave.value) :
                '';
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
                    vinculo_desde: filtroVinculoDesde ? String(filtroVinculoDesde.value || '') : '',
                    vinculo_hasta: filtroVinculoHasta ? String(filtroVinculoHasta.value || '') : '',
                    codigo: filtroCodigo ? String(filtroCodigo.value || '') : '',
                    pub_desde: filtroPubDesde ? String(filtroPubDesde.value || '') : '',
                    pub_hasta: filtroPubHasta ? String(filtroPubHasta.value || '') : '',
                    cierre_desde: filtroCierreDesde ? String(filtroCierreDesde.value || '') : '',
                    cierre_hasta: filtroCierreHasta ? String(filtroCierreHasta.value || '') : '',
                    cierre_24h: filtroCierre24hActivo === true,
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
            if (filtroVinculoDesde && data.vinculo_desde != null) {
                filtroVinculoDesde.value = String(data.vinculo_desde || '');
            }
            if (filtroVinculoHasta && data.vinculo_hasta != null) {
                filtroVinculoHasta.value = String(data.vinculo_hasta || '');
            }
            if (filtroCodigo && data.codigo != null) {
                filtroCodigo.value = String(data.codigo || '');
            }
            if (filtroPubDesde && data.pub_desde != null) {
                filtroPubDesde.value = String(data.pub_desde || '');
            }
            if (filtroPubHasta && data.pub_hasta != null) {
                filtroPubHasta.value = String(data.pub_hasta || '');
            }
            if (filtroCierreDesde && data.cierre_desde != null) {
                filtroCierreDesde.value = String(data.cierre_desde || '');
            }
            if (filtroCierreHasta && data.cierre_hasta != null) {
                filtroCierreHasta.value = String(data.cierre_hasta || '');
            }
            filtroCierre24hActivo = data.cierre_24h === true;
            filtroPalabraClaveAplicado = data.palabra_clave_aplicada != null ?
                String(data.palabra_clave_aplicada || '') :
                (filtroPalabraClave ? normalizarTexto(filtroPalabraClave.value) : '');
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

        function registrarVisitaServidor(codigo) {
            const codigoNorm = String(codigo || '').toUpperCase().trim();
            if (!codigoNorm || !urls.visita || !csrf) {
                return;
            }
            const body = new FormData();
            body.append('_token', csrf);
            body.append('codigo', codigoNorm);
            fetch(urls.visita, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body,
            }).then((res) => res.json().catch(() => ({}))).then((data) => {
                if (!data || !data.ok) {
                    return;
                }
                const veces = Number(data.visitas_usuario) || 0;
                const item = porCodigo.get(codigoNorm);
                if (item && veces > 0) {
                    item.visitas_usuario = Math.max(Number(item.visitas_usuario) || 0, veces);
                    const mapa = leerVisitasLocales();
                    mapa[codigoNorm] = Math.max(Number(mapa[codigoNorm]) || 0, veces);
                    guardarVisitasLocales(mapa);
                    renderTabla(false);
                }
            }).catch(() => {
                // silencioso: el contador local ya quedó
            });
        }

        async function confirmarEliminar(codigo) {
            const msg = `¿Eliminar la oportunidad «${codigo}»?\nSe borrará también en el sitio par.`;
            if (window.AdminDialog?.confirm) {
                return AdminDialog.confirm(msg, {
                    title: 'Eliminar oportunidad',
                    type: 'danger',
                    okText: 'Eliminar',
                });
            }
            return window.confirm(msg);
        }

        async function eliminarOportunidad(codigo, btn) {
            const codigoNorm = String(codigo || '').toUpperCase().trim();
            if (!puedeEliminar || !urls.eliminar || !csrf || !codigoNorm) {
                return;
            }
            const ok = await confirmarEliminar(codigoNorm);
            if (!ok) {
                return;
            }

            const labelPrev = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Eliminando…';
            }

            try {
                const res = await fetch(urls.eliminar, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ codigo: codigoNorm }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    const err = (data && data.error) ? data.error : 'No se pudo eliminar.';
                    if (window.AdminDialog?.alert) {
                        await AdminDialog.alert(err, { title: 'Eliminar oportunidad', type: 'danger' });
                    } else {
                        window.alert(err);
                    }
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = labelPrev;
                    }
                    return;
                }
                porCodigo.delete(codigoNorm);
                renderTabla(false);
                if (data.pendiente_sync && window.AdminDialog?.alert) {
                    await AdminDialog.alert(
                        data.mensaje || 'Eliminada localmente; sync al par pendiente.',
                        { title: 'Eliminar oportunidad', type: 'warning' },
                    );
                }
            } catch (e) {
                const err = e && e.message ? e.message : 'Error de red al eliminar.';
                if (window.AdminDialog?.alert) {
                    await AdminDialog.alert(err, { title: 'Eliminar oportunidad', type: 'danger' });
                } else {
                    window.alert(err);
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = labelPrev;
                }
            }
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
                const frases = frasesOrdenadas(item.palabras_coinciden).join(' | ');
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
            const blob = new Blob([contenido], {
                type: 'text/csv;charset=utf-8;'
            });
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
            actualizarUiCierre24h();
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
                footer.textContent = filtroCierre24hActivo
                    ? `Ninguna oportunidad cierra en las próximas 24 h (${total} vigentes).`
                    : `0 de ${total} oportunidad${total === 1 ? '' : 'es'} visibles con el filtro actual.`;
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
                const frases = frasesOrdenadas(item.palabras_coinciden);
                const tieneCantidad = item.cantidad_productos != null && item.cantidad_productos !== '';
                const cantidadNum = tieneCantidad ? Number(item.cantidad_productos) : null;
                const fraseBajoCodigo = frases.length ?
                    `<div class="opc-meta mt-1">Encontrada con: <strong>${escapeHtml(frases.join(', '))}</strong></div>` :
                    '';
                const productosBajoCodigo = tieneCantidad && !Number.isNaN(cantidadNum) ?
                    `<div class="opc-meta mt-1">Productos: <strong class="tabular-nums">${escapeHtml(String(cantidadNum))}</strong></div>` :
                    '';
                const vinculoHtml = htmlEstadoVinculoListado(item);
                const nombreHtml = nombre ?
                    `<div class="opc-linea-2 opc-meta" title="${escapeHtml(nombre)}">${escapeHtml(nombre)}</div>` :
                    '';
                const visitas = visitasDeItem(item);
                const codigoSolo = escapeHtml(codigo || '—');
                const vistoHtml = visitas > 0
                    ? ` <span class="opc-meta">visto ${visitas}</span>`
                    : '';
                const btnCopiarCodigo = codigo
                    ? `<button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline btn-copiar-codigo" data-no-loader data-codigo="${escapeHtml(codigo)}" title="Copiar código ${escapeHtml(codigo)}" aria-label="Copiar código">
                        <i class="bi bi-clipboard" aria-hidden="true"></i>
                    </button>`
                    : '';
                const btnProductos = codigo
                    ? `<button type="button" class="btn btn-outline-secondary btn-sm text-nowrap btn-ver-vinculo" data-no-loader data-codigo="${escapeHtml(codigo)}" title="Ver productos y vinculación">
                        <i class="bi bi-list-ul"></i> Productos
                    </button>`
                    : '';
                const btnCotizar = href
                    ? `<a href="${escapeHtml(href)}" class="btn btn-primary btn-sm text-nowrap btn-ir-cotizar" data-no-loader data-codigo="${escapeHtml(codigo)}">
                        <i class="bi bi-cart-plus"></i> Ir a cotizar
                   </a>`
                    : '';
                const btnEliminar = (puedeEliminar && codigo && urls.eliminar)
                    ? `<button type="button" class="btn btn-outline-danger btn-sm text-nowrap btn-eliminar-oportunidad" data-no-loader data-codigo="${escapeHtml(codigo)}" title="Eliminar oportunidad (también en el sitio par)">
                        <i class="bi bi-trash"></i> Eliminar
                    </button>`
                    : '';
                const accionHtml = (btnProductos || btnCotizar || btnEliminar)
                    ? `<div class="d-inline-flex flex-column align-items-end gap-1">${btnProductos}${btnCotizar}${btnEliminar}</div>`
                    : '<span class="text-muted small">—</span>';
                return `<tr>
                <td>
                    <span class="text-nowrap"><code>${codigoSolo}</code>${btnCopiarCodigo}${vistoHtml}</span>
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

            const filtroActivo = (filtroRegion && String(filtroRegion.value || '').trim() !== '') ||
                (filtroOrganismo && String(filtroOrganismo.value || '').trim() !== '') ||
                filtroPalabraClaveAplicado !== '' ||
                (filtroCodigo && String(filtroCodigo.value || '').trim() !== '') ||
                rangoVinculoFiltro() != null ||
                rangoFechaFiltro(filtroPubDesde, filtroPubHasta) != null ||
                rangoFechaFiltro(filtroCierreDesde, filtroCierreHasta) != null ||
                filtroCierre24hActivo;
            const sufijo = cancelado ? ' (parcial, cancelada).' : ' del día.';
            const visibles = filtroCierre24hActivo ?
                `${items.length} oportunidad${items.length === 1 ? '' : 'es'} cierran en 24 h (${total} vigentes).` :
                (filtroActivo ?
                    `${items.length} de ${total} oportunidad${total === 1 ? '' : 'es'} visibles.` :
                    `${items.length} oportunidad${items.length === 1 ? '' : 'es'}${sufijo}`);
            const rango = items.length > PAGE_SIZE ?
                ` Mostrando ${desde + 1}–${Math.min(desde + PAGE_SIZE, items.length)}.` :
                '';
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
                `<li class="page-item${esPrimera ? ' disabled' : ''}">` +
                `<button type="button" class="page-link" data-pagina="${paginaActual - 1}" aria-label="Anterior" ${esPrimera ? 'disabled' : ''}>&laquo;</button>` +
                `</li>`
            );

            elementosPaginacion(paginaActual, total).forEach((item) => {
                if (item === '...') {
                    partes.push('<li class="page-item disabled"><span class="page-link">…</span></li>');
                    return;
                }
                const activa = item === paginaActual;
                partes.push(
                    `<li class="page-item${activa ? ' active' : ''}">` +
                    `<button type="button" class="page-link" data-pagina="${item}" ${activa ? 'aria-current="page"' : ''}>${item}</button>` +
                    `</li>`
                );
            });

            partes.push(
                `<li class="page-item${esUltima ? ' disabled' : ''}">` +
                `<button type="button" class="page-link" data-pagina="${paginaActual + 1}" aria-label="Siguiente" ${esUltima ? 'disabled' : ''}>&raquo;</button>` +
                `</li>`
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

        if (btnFiltroCierre24h) {
            btnFiltroCierre24h.addEventListener('click', () => {
                filtroCierre24hActivo = !filtroCierre24hActivo;
                if (filtroCierre24hActivo) {
                    if (filtroCierreDesde) filtroCierreDesde.value = '';
                    if (filtroCierreHasta) filtroCierreHasta.value = '';
                }
                guardarFiltros();
                renderTabla(true);
            });
        }

        if (filtroPalabraClave) {
            filtroPalabraClave.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    aplicarFiltros();
                }
            });
        }

        [filtroVinculoDesde, filtroVinculoHasta, filtroPubDesde, filtroPubHasta, filtroCierreDesde, filtroCierreHasta].forEach((el) => {
            if (!el) {
                return;
            }
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    aplicarFiltros();
                }
            });
            el.addEventListener('change', () => {
                if ((el === filtroCierreDesde || el === filtroCierreHasta) && filtroCierre24hActivo) {
                    const tieneRango = String(filtroCierreDesde?.value || '').trim() !== ''
                        || String(filtroCierreHasta?.value || '').trim() !== '';
                    if (tieneRango) {
                        filtroCierre24hActivo = false;
                    }
                }
                guardarFiltros();
                renderTabla(true);
            });
        });

        if (filtroCodigo) {
            let filtroCodigoTimer = null;
            filtroCodigo.addEventListener('input', () => {
                if (filtroCodigoTimer) clearTimeout(filtroCodigoTimer);
                filtroCodigoTimer = setTimeout(() => {
                    guardarFiltros();
                    renderTabla(true);
                }, 200);
            });
            filtroCodigo.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (filtroCodigoTimer) clearTimeout(filtroCodigoTimer);
                    aplicarFiltros();
                }
            });
        }

        const modalVinculoEl = document.getElementById('modal-vinculo-productos');
        const modalVinculoLabel = document.getElementById('modal-vinculo-productos-label');
        const modalVinculoResumen = document.getElementById('modal-vinculo-resumen');
        const modalVinculoLoading = document.getElementById('modal-vinculo-loading');
        const modalVinculoLoadingText = document.getElementById('modal-vinculo-loading-text');
        const modalVinculoError = document.getElementById('modal-vinculo-error');
        const modalVinculoTablaWrap = document.getElementById('modal-vinculo-tabla-wrap');
        const modalVinculoTbody = document.getElementById('modal-vinculo-tbody');
        const bsModalVinculo = modalVinculoEl && typeof bootstrap !== 'undefined'
            ? bootstrap.Modal.getOrCreateInstance(modalVinculoEl)
            : null;

        function setModalVinculoLoading(texto) {
            if (modalVinculoLoadingText) {
                modalVinculoLoadingText.textContent = texto || 'Cargando productos…';
            }
            if (modalVinculoLoading) {
                modalVinculoLoading.classList.remove('d-none');
            }
        }

        function aplicarItemVinculoLocal(item) {
            if (!item || !item.codigo) {
                return;
            }
            const cod = String(item.codigo).toUpperCase();
            const prev = porCodigo.get(cod) || {};
            porCodigo.set(cod, { ...prev, ...item, codigo: cod });
            renderTabla(false);
        }

        /**
         * Vincula on-demand solo si aún no está procesada (sin refresco por frases).
         * @returns {Promise<{ok: boolean, error?: string}>}
         */
        async function asegurarVinculoAntes(codigo, { onAviso } = {}) {
            const cod = String(codigo || '').trim().toUpperCase();
            if (!cod) {
                return { ok: false, error: 'Código vacío.' };
            }
            const item = porCodigo.get(cod);
            if (!itemNecesitaVincular(item)) {
                return { ok: true };
            }
            if (typeof onAviso === 'function') {
                onAviso('Sin vínculo local; consultando sitio par…');
            }
            if (!urls.vincularCodigo) {
                return { ok: false, error: 'No hay endpoint de vinculación disponible.' };
            }
            try {
                const res = await fetch(urls.vincularCodigo, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ codigo: cod }),
                });
                const data = await res.json().catch(() => ({}));
                if (data?.item) {
                    aplicarItemVinculoLocal(data.item);
                }
                if (!res.ok || !data?.ok) {
                    return {
                        ok: false,
                        error: data?.error || `HTTP ${res.status}`,
                    };
                }
                return { ok: true };
            } catch (err) {
                return {
                    ok: false,
                    error: err?.message || 'Vinculación fallida.',
                };
            }
        }

        function badgeEstadoVinculo(estado, esSugerencia) {
            const e = String(estado || '').toLowerCase();
            if (e === 'vinculado') {
                return esSugerencia
                    ? '<span class="badge text-bg-info">Sugerencia</span>'
                    : '<span class="badge text-bg-success">Vinculado</span>';
            }
            if (e === 'pendiente' || e === '') {
                return '<span class="badge text-bg-warning">Sin vínculo</span>';
            }
            return `<span class="badge text-bg-secondary">${escapeHtml(e)}</span>`;
        }

        async function abrirDetalleVinculo(codigo) {
            const cod = String(codigo || '').trim().toUpperCase();
            if (!cod || !bsModalVinculo) {
                return;
            }
            if (modalVinculoLabel) {
                modalVinculoLabel.textContent = `Productos — ${cod}`;
            }
            if (modalVinculoResumen) {
                modalVinculoResumen.textContent = '';
            }
            if (modalVinculoError) {
                modalVinculoError.classList.add('d-none');
                modalVinculoError.textContent = '';
            }
            if (modalVinculoTablaWrap) {
                modalVinculoTablaWrap.classList.add('d-none');
            }
            if (modalVinculoTbody) {
                modalVinculoTbody.innerHTML = '';
            }
            const necesitaVinculo = itemNecesitaVincular(porCodigo.get(cod));
            setModalVinculoLoading(
                necesitaVinculo
                    ? 'Sin vínculo local; consultando sitio par…'
                    : 'Cargando productos…'
            );
            bsModalVinculo.show();

            if (necesitaVinculo) {
                const prev = await asegurarVinculoAntes(cod, {
                    onAviso: (msg) => setModalVinculoLoading(msg),
                });
                if (!prev.ok) {
                    if (modalVinculoLoading) {
                        modalVinculoLoading.classList.add('d-none');
                    }
                    if (modalVinculoError) {
                        modalVinculoError.className = 'alert alert-danger py-2 small mb-0';
                        modalVinculoError.textContent = 'Vinculación fallida: ' + (prev.error || 'Error desconocido');
                        modalVinculoError.classList.remove('d-none');
                    }
                    return;
                }
                setModalVinculoLoading('Cargando productos…');
            }

            try {
                const urlDetalle = String(urls.detalleVinculoBase || '').replace('__CODIGO__', encodeURIComponent(cod));
                const res = await fetch(urlDetalle, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const data = await res.json().catch(() => ({}));
                if (modalVinculoLoading) {
                    modalVinculoLoading.classList.add('d-none');
                }
                if (!res.ok || !data.ok) {
                    if (modalVinculoError) {
                        modalVinculoError.className = 'alert alert-warning py-2 small mb-0';
                        modalVinculoError.textContent = data.error ||
                            'No se pudo obtener el detalle de productos de Mercado Público.';
                        modalVinculoError.classList.remove('d-none');
                    }
                    return;
                }
                const resumen = data.resumen || {};
                const vinc = data.productos_vinculados ?? resumen.vinculados ?? 0;
                const tot = data.cantidad_productos ?? resumen.total ?? (Array.isArray(data.lineas) ? data.lineas.length : 0);
                const pct = data.porcentaje_vinculo != null
                    ? data.porcentaje_vinculo
                    : (tot > 0 ? Math.round((Number(vinc) / Number(tot)) * 100) : 0);
                if (modalVinculoResumen) {
                    modalVinculoResumen.textContent =
                        `Vinculados ${vinc}/${tot} (${pct}%).`;
                }
                const lineas = Array.isArray(data.lineas) ? data.lineas : [];
                if (modalVinculoTbody) {
                    modalVinculoTbody.innerHTML = lineas.map((linea) => {
                        const desc = String(linea.descripcion || '').trim() || '—';
                        const cant = linea.cantidad != null ? String(linea.cantidad) : '—';
                        const prod = linea.producto && typeof linea.producto === 'object' ? linea.producto : null;
                        const prodTxt = prod
                            ? `<code>${escapeHtml(String(prod.prod_item || ''))}</code>` +
                              (prod.prod_nombre
                                  ? `<div class="opc-meta">${escapeHtml(String(prod.prod_nombre))}</div>`
                                  : '')
                            : '<span class="text-muted">—</span>';
                        const precioTxt = prod && prod.prod_valor != null && prod.prod_valor !== ''
                            ? ('$' + Math.round(Number(prod.prod_valor) || 0).toLocaleString('es-CL'))
                            : '—';
                        return `<tr>
                            <td class="small">${escapeHtml(desc)}</td>
                            <td class="text-end tabular-nums small">${escapeHtml(cant)}</td>
                            <td class="small">${badgeEstadoVinculo(linea.estado, linea.es_sugerencia)}</td>
                            <td class="small">${prodTxt}</td>
                            <td class="text-end tabular-nums text-nowrap small">${escapeHtml(precioTxt)}</td>
                        </tr>`;
                    }).join('') || '<tr><td colspan="5" class="text-muted text-center">Sin productos.</td></tr>';
                }
                if (modalVinculoTablaWrap) {
                    modalVinculoTablaWrap.classList.remove('d-none');
                }
            } catch (err) {
                if (modalVinculoLoading) {
                    modalVinculoLoading.classList.add('d-none');
                }
                if (modalVinculoError) {
                    modalVinculoError.className = 'alert alert-warning py-2 small mb-0';
                    modalVinculoError.textContent = 'No se pudo cargar el detalle de productos.';
                    modalVinculoError.classList.remove('d-none');
                }
            }
        }

        function copiarTextoPortapapeles(texto) {
            if (navigator.clipboard?.writeText && window.isSecureContext) {
                return navigator.clipboard.writeText(texto);
            }
            return new Promise((resolve, reject) => {
                const ta = document.createElement('textarea');
                ta.value = texto;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    const ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    ok ? resolve(true) : reject(new Error('No se pudo copiar'));
                } catch (err) {
                    document.body.removeChild(ta);
                    reject(err);
                }
            });
        }

        function feedbackCopiado(btn) {
            if (!btn) {
                return;
            }
            const icon = btn.querySelector('i');
            if (!icon) {
                return;
            }
            const prev = icon.className;
            icon.className = 'bi bi-clipboard-check text-success';
            btn.title = '¡Copiado!';
            window.setTimeout(() => {
                icon.className = prev;
                const cod = btn.getAttribute('data-codigo') || '';
                btn.title = cod ? `Copiar código ${cod}` : 'Copiar código';
            }, 1500);
        }

        if (tbody) {
            // Ir a cotizar: vincular solo si aún no está procesada (sin refresco por frases).
            tbody.addEventListener('click', async (e) => {
                const link = e.target.closest('a.btn-ir-cotizar');
                if (!link) {
                    return;
                }
                const cod = String(link.getAttribute('data-codigo') || '').trim().toUpperCase();
                incrementarVisitaLocal(cod);
                registrarVisitaServidor(cod);
                e.preventDefault();
                e.stopPropagation();
                const href = link.getAttribute('href') || '';
                const necesita = itemNecesitaVincular(porCodigo.get(cod));
                if (!necesita) {
                    window.location.href = href;
                    return;
                }
                const labelPrev = link.innerHTML;
                link.classList.add('disabled');
                link.setAttribute('aria-disabled', 'true');
                link.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Buscando vínculo…';
                const prev = await asegurarVinculoAntes(cod);
                if (!prev.ok) {
                    link.classList.remove('disabled');
                    link.removeAttribute('aria-disabled');
                    link.innerHTML = labelPrev;
                    if (bsModalVinculo && modalVinculoLabel && modalVinculoError) {
                        modalVinculoLabel.textContent = `Vinculación — ${cod}`;
                        if (modalVinculoResumen) modalVinculoResumen.textContent = '';
                        if (modalVinculoTablaWrap) modalVinculoTablaWrap.classList.add('d-none');
                        if (modalVinculoLoading) modalVinculoLoading.classList.add('d-none');
                        modalVinculoError.className = 'alert alert-danger py-2 small mb-0';
                        modalVinculoError.textContent = 'Vinculación fallida: ' + (prev.error || 'Error desconocido');
                        modalVinculoError.classList.remove('d-none');
                        bsModalVinculo.show();
                    }
                    return;
                }
                window.location.href = href;
            }, true);

            tbody.addEventListener('click', (e) => {
                const btnVinculo = e.target.closest('button.btn-ver-vinculo');
                if (btnVinculo) {
                    e.preventDefault();
                    const codVinculo = btnVinculo.getAttribute('data-codigo') || '';
                    incrementarVisitaLocal(codVinculo);
                    renderTabla(false);
                    registrarVisitaServidor(codVinculo);
                    abrirDetalleVinculo(codVinculo);
                    return;
                }
                const btnEliminar = e.target.closest('button.btn-eliminar-oportunidad');
                if (btnEliminar) {
                    e.preventDefault();
                    const codElim = String(btnEliminar.getAttribute('data-codigo') || '').trim().toUpperCase();
                    if (codElim) {
                        eliminarOportunidad(codElim, btnEliminar);
                    }
                    return;
                }
                const btnCopiar = e.target.closest('button.btn-copiar-codigo');
                if (!btnCopiar) {
                    return;
                }
                e.preventDefault();
                const cod = String(btnCopiar.getAttribute('data-codigo') || '').trim().toUpperCase();
                if (!cod) {
                    return;
                }
                copiarTextoPortapapeles(cod).then(() => {
                    feedbackCopiado(btnCopiar);
                }).catch(() => {
                    btnCopiar.title = 'No se pudo copiar';
                });
            });
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
            const paginaPaso = Number(paso?.pagina);
            const params = {
                estado: 'publicada',
                numero_pagina: (Number.isFinite(paginaPaso) && paginaPaso > 0) ? paginaPaso : 1,
                ordenar_por: 'FechaPublicacion',
                region: Number(paso?.region) || 1,
                tamano_pagina: 50,
            };
            if (frase && frase !== '(todas)') {
                params.q = frase;
            }
            return Object.fromEntries(Object.keys(params).sort().map((k) => [k, params[k]]));
        }

        function sincronizarConsultaConPaginaPaso(consulta, paso) {
            if (!consulta || typeof consulta !== 'object' || !paso) {
                return consulta;
            }
            const paginaPaso = Number(paso.pagina);
            if (!Number.isFinite(paginaPaso) || paginaPaso < 1) {
                return consulta;
            }
            const data = { ...consulta };
            const params = (data.parametros && typeof data.parametros === 'object')
                ? { ...data.parametros }
                : {};
            const paginaConsulta = Number(params.numero_pagina ?? data.respuesta?.pagina);
            if (paginaConsulta === paginaPaso) {
                return data;
            }
            params.numero_pagina = paginaPaso;
            data.parametros = Object.fromEntries(
                Object.keys(params).sort().map((k) => [k, params[k]]),
            );
            const endpoint = data.endpoint || `${mpApi.baseUrl}${mpApi.path}`;
            const qs = new URLSearchParams();
            Object.entries(data.parametros).forEach(([k, v]) => qs.set(k, String(v)));
            data.endpoint = endpoint;
            data.url_completa = `${endpoint}?${qs.toString()}`;
            if (data.respuesta && typeof data.respuesta === 'object') {
                data.respuesta = { ...data.respuesta, pagina: paginaPaso };
            }
            const {
                json: _j,
                respuesta_json: _rj,
                respuesta: _r,
                ...paraJson
            } = data;
            data.json = JSON.stringify(
                Object.fromEntries(Object.keys(paraJson).sort().map((k) => [k, paraJson[k]])),
                null,
                2,
            );
            return data;
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

            let data = consulta && typeof consulta === 'object' ?
                consulta :
                buildConsultaPreview(paso, indice, total);
            data = sincronizarConsultaConPaginaPaso(data, paso);

            const url = data.url_completa ||
                (data.endpoint && data.parametros ?
                    `${data.endpoint}?${new URLSearchParams(Object.entries(data.parametros).sort()).toString()}` :
                    '');

            if (debugEndpointUrl) {
                debugEndpointUrl.textContent = url || `${data.metodo || 'GET'} ${data.endpoint || ''}`;
            }

            if (debugPasoLine) {
                const fraseRaw = paso?.frase ?? data.parametros?.q ?? '';
                const frase = (!fraseRaw || fraseRaw === '(todas)') ? 'todas las frases' : fraseRaw;
                const regionNombre = paso?.region_nombre || data.region_nombre || (`Región ${paso?.region ?? data.parametros?.region ?? ''}`);
                const pasoTxt = indice != null && total != null ? `Paso ${indice + 1}/${total}` : '';
                const paginaMp = Number(paso?.pagina ?? data.respuesta?.pagina ?? data.parametros?.numero_pagina);
                const paginasMax = Number(paso?.paginas_max);
                const paginaTxt = Number.isFinite(paginaMp) && paginaMp > 0 ?
                    (Number.isFinite(paginasMax) && paginasMax > 0 ? `pág ${paginaMp}/${paginasMax}` : `pág ${paginaMp}`) :
                    '';
                let linea = [pasoTxt, `«${frase}»`, regionNombre, paginaTxt].filter(Boolean).join(' · ');
                if (nota) linea += ` — ${nota}`;
                const terminado = paso?.resultado && !['pendiente', 'en_curso'].includes(paso.resultado);
                if (terminado && data.total_api != null) {
                    linea += ` — API devolvió ${data.total_api}, publicadas hoy: ${data.total_publicadas_hoy ?? 0}`;
                }
                debugPasoLine.textContent = linea;
            }

            const {
                json: _json,
                respuesta_json: _rjson,
                respuesta: _resp,
                ...sinJson
            } = data;
            const ordenado = Object.fromEntries(Object.keys(sinJson).sort().map((k) => [k, sinJson[k]]));
            debugConsultaJson.textContent = data.json || JSON.stringify(ordenado, null, 2);

            if (debugRespuestaJson) {
                const resp = data.respuesta && typeof data.respuesta === 'object' ? data.respuesta : null;
                const terminado = paso?.resultado && !['pendiente', 'en_curso'].includes(paso.resultado);
                const cancelado = paso?.resultado === 'cancelado';
                const muestra = Array.isArray(resp?.muestra) ? resp.muestra : [];
                const hayPaginaMp = resp?.items_pagina != null;
                const hayRespuestaMp = hayPaginaMp || muestra.length > 0;
                const paginaMp = resp?.pagina != null ? Number(resp.pagina) : null;
                const itemsPagina = hayPaginaMp ? Number(resp.items_pagina) : null;
                const itemsAcum = resp?.items_recibidos ?? resp?.items_acumulados;
                const esperandoWorker = typeof nota === 'string' && /esperando worker|en cola/i.test(nota);
                const errorMsg = String(resp?.error_mensaje || paso?.error || '').trim();
                const errorTipo = String(resp?.error_tipo || '').trim();
                const hayError = Boolean(resp?.error) || errorMsg !== '';

                if (cancelado) {
                    debugRespuestaJson.textContent = 'Búsqueda cancelada; se detuvo la espera a Mercado Público.';
                    if (debugRespuestaLine) {
                        debugRespuestaLine.textContent = 'Cancelado — la consulta en curso no se completó.';
                    }
                } else if (hayError) {
                    const payload = {
                        error: true,
                        error_tipo: errorTipo || 'error',
                        error_mensaje: errorMsg || 'Error sin detalle',
                        pagina: paginaMp,
                        items_pagina: itemsPagina,
                        items_acumulados: itemsAcum,
                        muestra,
                    };
                    debugRespuestaJson.textContent = data.respuesta_json && resp?.error
                        ? data.respuesta_json
                        : JSON.stringify(payload, null, 2);
                    if (debugRespuestaLine) {
                        const tipoTxt = errorTipo === 'base_datos'
                            ? 'Error de base de datos'
                            : (errorTipo === 'timeout_mp' || errorTipo === 'timeout_worker'
                                ? 'Timeout / página lenta'
                                : (errorTipo === 'http_mp' ? 'Error HTTP Mercado Público' : 'Error'));
                        const pagTxt = paginaMp != null ? ` (pág ${paginaMp})` : '';
                        debugRespuestaLine.textContent = `${tipoTxt}${pagTxt} — se continúa con la siguiente página si aplica.`;
                    }
                } else if (hayRespuestaMp && resp) {
                    const porFraseDbg = (paso?.encontradas_por_frase && typeof paso.encontradas_por_frase === 'object')
                        ? paso.encontradas_por_frase
                        : (resp.encontradas_por_frase && typeof resp.encontradas_por_frase === 'object'
                            ? resp.encontradas_por_frase
                            : null);
                    const muestraMatch = Array.isArray(paso?.encontradas_muestra)
                        ? paso.encontradas_muestra
                        : (Array.isArray(resp.encontradas_muestra) ? resp.encontradas_muestra : []);
                    const payloadDbg = { ...resp };
                    if (porFraseDbg && Object.keys(porFraseDbg).length > 0) {
                        payloadDbg.encontradas_por_frase = porFraseDbg;
                    }
                    if (muestraMatch.length > 0) {
                        payloadDbg.encontradas_muestra = muestraMatch;
                    }
                    debugRespuestaJson.textContent = JSON.stringify(payloadDbg, null, 2);
                    if (debugRespuestaLine) {
                        const partes = [];
                        if (paginaMp != null) partes.push(`página ${paginaMp}`);
                        if (itemsPagina != null) partes.push(`${itemsPagina} ítem(s) en esta página`);
                        if (itemsAcum != null) partes.push(`${itemsAcum} acumulados`);
                        const coinciden = resp.coinciden_hoy ?? resp.encontradas_pagina ?? paso?.encontradas;
                        if (coinciden != null) partes.push(`cotiz. ${coinciden}`);
                        if (porFraseDbg && Object.keys(porFraseDbg).length > 0) {
                            const top = Object.entries(porFraseDbg)
                                .sort((a, b) => Number(b[1]) - Number(a[1]))
                                .slice(0, 4)
                                .map(([f, n]) => `${f}×${n}`);
                            partes.push(top.join(', '));
                        }
                        if (muestra.length > 0) partes.push(`muestra MP ${muestra.length}`);
                        if (muestraMatch.length > 0) partes.push(`muestra match ${muestraMatch.length}`);
                        debugRespuestaLine.textContent = partes.length ?
                            (terminado ? `MP — ${partes.join(' · ')}.` : `Respuesta MP — ${partes.join(' · ')}.`) :
                            'Respuesta de Mercado Público recibida.';
                    }
                } else if (!terminado) {
                    if (esperandoWorker) {
                        debugRespuestaJson.textContent =
                            'Job en cola sin tomar. Aún no se consultó Mercado Público — verifique el worker (RUN_QUEUE_WORKER=true).';
                        if (debugRespuestaLine) {
                            debugRespuestaLine.textContent = 'Esperando worker — sin respuesta MP todavía.';
                        }
                    } else {
                        debugRespuestaJson.textContent = 'Esperando respuesta de Mercado Público…';
                        if (debugRespuestaLine) {
                            const pagTxt = paginaMp != null ? ` (página ${paginaMp})` : '';
                            debugRespuestaLine.textContent = itemsAcum != null ?
                                `En curso${pagTxt} — ${itemsAcum} ítem(s) leídos hasta ahora.` :
                                `En curso${pagTxt} — consultando API…`;
                        }
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
            const hayEnCurso = pasos.some((p) => p?.resultado === 'en_curso');
            const mensajeLower = String(corrida?.mensaje || '').toLowerCase();
            const esperandoWorker = corrida?.estado === 'running' &&
                !hayEnCurso &&
                (
                    Boolean(corrida.worker_stalled) ||
                    mensajeLower.includes('esperando worker') ||
                    mensajeLower.includes('encolada') ||
                    mensajeLower.includes('en cola')
                );
            let nota = null;
            if (paso?.error) {
                nota = `${paso.error} — la búsqueda continúa con la siguiente región o reintento.`;
            } else if (corrida.estado === 'cancelled') {
                nota = 'búsqueda cancelada';
            } else if (esperandoWorker && (paso?.resultado === 'pendiente' || !paso?.resultado)) {
                nota = 'esperando worker (job en cola)';
            } else if (corrida.estado === 'running' && (paso?.resultado === 'pendiente' || paso?.resultado === 'en_curso')) {
                const pag = Number(paso?.pagina);
                nota = Number.isFinite(pag) && pag > 0 ?
                    `consulta página ${pag} en curso…` :
                    'consulta en curso…';
            }
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
                    setTimeout(() => {
                        reanudandoSilencioso = false;
                    }, 5000);
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

        function setRenderKeepAliveProceso(activo) {
            if (!window.CotizRenderKeepAlive) {
                return;
            }
            if (activo) {
                window.CotizRenderKeepAlive.start();
            } else {
                window.CotizRenderKeepAlive.stop();
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
            esperando_worker: 'text-bg-warning',
            esperando_mp: 'text-bg-info',
            procesando_match: 'text-bg-primary',
        };

        function formatearDia(fecha) {
            if (!fecha) return '—';
            const [anio, mes, dia] = String(fecha).split('-');
            return (anio && mes && dia) ? `${dia}-${mes}-${anio}` : String(fecha);
        }

        function etiquetaEsperaPaso(paso, corridaMeta) {
            const resultado = String(paso?.resultado || '');
            const mensaje = String(corridaMeta?.mensaje || '').toLowerCase();
            const hayEnCurso = Boolean(corridaMeta?.hayEnCurso);
            const esperandoWorker = Boolean(corridaMeta?.esperandoWorker);

            if (resultado === 'en_curso') {
                const pagina = Number(paso.pagina);
                const paginasMax = Number(paso.paginas_max);
                const maxTxt = (Number.isFinite(pagina) && pagina > 0)
                    ? (Number.isFinite(paginasMax) && paginasMax > 0 ? ` · pág ${pagina}/${paginasMax}` : ` · pág ${pagina}`)
                    : '';
                const itemsPagina = Number(paso.items_pagina);
                const matchRev = Number(paso.match_revisados);
                const matchTot = Number(paso.match_total);
                const matchSeg = Number(paso.match_segundos);
                const fase = String(paso.fase || '');

                const enMatch = fase === 'match'
                    || (Number.isFinite(matchRev) && matchRev > 0)
                    || (Number.isFinite(itemsPagina) && itemsPagina > 0);

                if (enMatch) {
                    let texto = 'Procesando match' + maxTxt;
                    if (Number.isFinite(matchTot) && matchTot > 0) {
                        const rev = Number.isFinite(matchRev) && matchRev > 0 ? matchRev : 0;
                        texto += ` · ${rev}/${matchTot}`;
                    } else if (Number.isFinite(itemsPagina) && itemsPagina > 0) {
                        texto += ` · ${itemsPagina} ítems`;
                    }
                    const cotiz = Number(paso.encontradas);
                    if (Number.isFinite(cotiz) && cotiz > 0) {
                        texto += ` · ${cotiz} cotiz.`;
                    }
                    if (Number.isFinite(matchSeg) && matchSeg > 0) {
                        texto += ` · ${matchSeg}s`;
                    }
                    return { clave: 'procesando_match', texto };
                }

                return {
                    clave: 'esperando_mp',
                    texto: 'Esperando Mercado Público' + maxTxt,
                };
            }

            if (resultado === 'pendiente' && esperandoWorker && !hayEnCurso) {
                if (mensaje.includes('esperando worker')) {
                    return { clave: 'esperando_worker', texto: 'Esperando worker' };
                }
                return { clave: 'esperando_worker', texto: 'En cola' };
            }

            return null;
        }

        function renderPasosCorrida(pasos, corrida = null) {
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

            const hayEnCurso = pasos.some((p) => p?.resultado === 'en_curso');
            const mensaje = String(corrida?.mensaje || '');
            const mensajeLower = mensaje.toLowerCase();
            const esperandoWorker = Boolean(corrida) &&
                corrida.estado === 'running' &&
                !hayEnCurso &&
                (
                    Boolean(corrida.worker_stalled) ||
                    mensajeLower.includes('esperando worker') ||
                    mensajeLower.includes('encolada') ||
                    mensajeLower.includes('en cola')
                );
            const corridaMeta = { mensaje, hayEnCurso, esperandoWorker };

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
                const etiquetaFrase = (!frasePaso || frasePaso === '(todas)') ?
                    'todas las frases' :
                    frasePaso;
                const porFrase = (paso.encontradas_por_frase && typeof paso.encontradas_por_frase === 'object')
                    ? paso.encontradas_por_frase
                    : null;
                if (porFrase && Object.keys(porFrase).length > 0) {
                    const partes = Object.entries(porFrase)
                        .sort((a, b) => Number(b[1]) - Number(a[1]))
                        .slice(0, 5)
                        .map(([f, n]) => `${escapeHtml(String(f))}×${Number(n) || 0}`);
                    tdFrase.innerHTML =
                        `<div class="small">${escapeHtml(etiquetaFrase)}</div>` +
                        `<div class="text-muted" style="font-size:0.7rem;line-height:1.25;">${partes.join(' · ')}</div>`;
                } else {
                    tdFrase.textContent = etiquetaFrase;
                }

                const tdEncontradas = document.createElement('td');
                tdEncontradas.className = 'text-end tabular-nums';
                if (paso.resultado === 'pendiente' || paso.resultado === 'cancelado' ||
                    paso.encontradas === null || paso.encontradas === undefined) {
                    tdEncontradas.textContent = '—';
                    tdEncontradas.classList.add('text-muted');
                } else {
                    tdEncontradas.textContent = String(Number(paso.encontradas) || 0);
                    if (paso.resultado === 'en_curso') {
                        tdEncontradas.classList.add('fw-semibold', 'text-primary');
                    }
                }

                const tdPagina = document.createElement('td');
                tdPagina.className = 'text-end text-nowrap tabular-nums';
                const pagina = Number(paso.pagina);
                const paginasMax = Number(paso.paginas_max);
                const itemsLeidos = Number(paso.items_leidos);
                if (Number.isFinite(pagina) && pagina > 0) {
                    const maxTxt = Number.isFinite(paginasMax) && paginasMax > 0 ? `/${paginasMax}` : '';
                    let paginaTxt = `${pagina}${maxTxt}`;
                    const itemsPagina = Number(paso.items_pagina);
                    const matchRev = Number(paso.match_revisados);
                    const matchTot = Number(paso.match_total);
                    if (Number.isFinite(matchTot) && matchTot > 0 && paso.resultado === 'en_curso') {
                        const rev = Number.isFinite(matchRev) ? matchRev : 0;
                        paginaTxt += ` · match ${rev}/${matchTot}`;
                    } else if (Number.isFinite(itemsPagina) && itemsPagina > 0) {
                        paginaTxt += ` · ${itemsPagina}/pág`;
                    }
                    if (Number.isFinite(itemsLeidos) && itemsLeidos > 0) {
                        paginaTxt += ` · acum ${itemsLeidos}`;
                    }
                    tdPagina.textContent = paginaTxt;
                } else {
                    tdPagina.textContent = '—';
                    tdPagina.classList.add('text-muted');
                }

                const tdMatchTiempo = document.createElement('td');
                tdMatchTiempo.className = 'text-end text-nowrap tabular-nums';
                const matchSeg = Number(paso.match_segundos);
                const matchSegAcum = Number(paso.match_segundos_acum);
                const liveMatch = (Number.isFinite(matchSeg) && matchSeg > 0) ? matchSeg : 0;
                const acumMatch = (Number.isFinite(matchSegAcum) && matchSegAcum > 0) ? matchSegAcum : 0;
                const enMatchLive = paso.resultado === 'en_curso' &&
                    (String(paso.fase || '') === 'match' || liveMatch > 0);
                const matchSegMostrar = enMatchLive
                    ? (acumMatch + liveMatch)
                    : (liveMatch > 0 ? liveMatch : (acumMatch > 0 ? acumMatch : null));
                if (matchSegMostrar != null && matchSegMostrar > 0) {
                    tdMatchTiempo.textContent = formatearDuracionSegs(matchSegMostrar);
                    if (enMatchLive) {
                        tdMatchTiempo.classList.add('fw-semibold', 'text-primary');
                    }
                } else {
                    tdMatchTiempo.textContent = '—';
                    tdMatchTiempo.classList.add('text-muted');
                }

                const tdTiempo = document.createElement('td');
                tdTiempo.className = 'text-end text-nowrap tabular-nums';
                const duracionTexto = paso.duracion_texto ||
                    (paso.duracion_segundos != null ? formatearDuracionSegs(paso.duracion_segundos) : null);
                if (duracionTexto) {
                    tdTiempo.textContent = duracionTexto;
                } else {
                    tdTiempo.textContent = '—';
                    tdTiempo.classList.add('text-muted');
                }

                const tdResultado = document.createElement('td');
                const espera = etiquetaEsperaPaso(paso, corridaMeta);
                const badge = document.createElement('span');
                if (espera) {
                    badge.className = `badge ${BADGE_PASO[espera.clave] || 'text-bg-secondary'}`;
                    badge.textContent = espera.texto;
                } else {
                    badge.className = `badge ${BADGE_PASO[paso.resultado] || 'text-bg-secondary'}`;
                    badge.textContent = paso.etiqueta || 'Pendiente';
                }
                tdResultado.appendChild(badge);
                if (paso.error) {
                    const err = document.createElement('div');
                    err.className = 'text-danger small mt-1';
                    err.textContent = paso.error;
                    tdResultado.appendChild(err);
                }

                tr.append(tdNum, tdDia, tdRegion, tdFrase, tdEncontradas, tdPagina, tdMatchTiempo, tdTiempo, tdResultado);
                relPasosTbody.appendChild(tr);
            });
        }

        function indiceRegionConfig(region) {
            const codigo = Number(region) || 0;
            if (!Array.isArray(regionesOrdenConfig) || regionesOrdenConfig.length === 0) {
                return 999;
            }
            const pos = regionesOrdenConfig.indexOf(codigo);
            return pos >= 0 ? pos : 999;
        }

        function listaProgresoRegiones(vinculo) {
            // Preferir lista ordenada del servidor (evita que JSON reordene claves "3","13",…).
            if (vinculo && Array.isArray(vinculo.progreso_regiones) && vinculo.progreso_regiones.length > 0) {
                return vinculo.progreso_regiones.filter((s) => s && Number(s.total) > 0);
            }
            const porRegion = vinculo && vinculo.progreso_por_region && typeof vinculo.progreso_por_region === 'object'
                ? vinculo.progreso_por_region
                : {};
            return Object.values(porRegion)
                .filter((s) => s && Number(s.total) > 0)
                .sort((a, b) => {
                    const ia = indiceRegionConfig(a.region);
                    const ib = indiceRegionConfig(b.region);
                    if (ia !== ib) return ia - ib;
                    return (Number(a.region) || 0) - (Number(b.region) || 0);
                });
        }

        function renderVinculoRegiones(vinculo) {
            const wrap = document.getElementById('vin-regiones-wrap');
            const tbody = document.getElementById('vin-regiones-tbody');
            const contador = document.getElementById('vin-regiones-contador');
            if (!wrap || !tbody) return;

            const regiones = listaProgresoRegiones(vinculo);
            if (regiones.length === 0) {
                wrap.classList.add('d-none');
                tbody.innerHTML = '';
                if (contador) contador.textContent = '0';
                return;
            }

            wrap.classList.remove('d-none');
            // El panel queda cerrado: el usuario lo abre con el botón (mismo diseño que la búsqueda).
            let totalCotiz = 0;
            let hechosCotiz = 0;
            regiones.forEach((s) => {
                totalCotiz += Number(s.total) || 0;
                hechosCotiz += Number(s.hechos) || 0;
            });
            if (contador) contador.textContent = `${hechosCotiz}/${totalCotiz}`;
            tbody.innerHTML = '';
            regiones.forEach((stats) => {
                const tr = document.createElement('tr');
                const total = Number(stats.total) || 0;
                const hechos = Number(stats.hechos) || 0;
                const pct = Number(stats.porcentaje) || 0;
                const prodVinc = Number(stats.productos_vinculados) || 0;
                const prodTotal = Number(stats.productos_total) || 0;
                const pctVinc = Number(stats.porcentaje_vinculados) || 0;

                const tdRegion = document.createElement('td');
                tdRegion.className = 'text-nowrap';
                tdRegion.textContent = stats.region_nombre || `Región ${stats.region || '—'}`;

                const tdCotiz = document.createElement('td');
                tdCotiz.className = 'text-end tabular-nums';
                tdCotiz.textContent = String(total);

                const tdProc = document.createElement('td');
                tdProc.className = 'text-end tabular-nums';
                tdProc.textContent = `${hechos}/${total}`;
                tdProc.classList.add(pct >= 100 ? 'text-success' : (hechos > 0 ? 'text-primary' : 'text-muted'));

                const tdPct = document.createElement('td');
                tdPct.className = 'text-end tabular-nums';
                tdPct.textContent = `${pct}%`;
                tdPct.classList.add(pct >= 100 ? 'text-success' : (hechos > 0 ? 'text-primary' : 'text-muted'));

                const tdProd = document.createElement('td');
                tdProd.className = 'text-end tabular-nums';
                if (prodTotal > 0) {
                    tdProd.textContent = `${prodVinc}/${prodTotal}`;
                } else {
                    tdProd.textContent = hechos > 0 ? '0/0' : '—';
                    if (hechos === 0) tdProd.classList.add('text-muted');
                }

                const tdPctVinc = document.createElement('td');
                tdPctVinc.className = 'text-end tabular-nums';
                if (prodTotal > 0) {
                    tdPctVinc.textContent = `${pctVinc}%`;
                    tdPctVinc.classList.add(pctVinc >= 50 ? 'text-success' : (pctVinc > 0 ? 'text-primary' : 'text-muted'));
                } else {
                    tdPctVinc.textContent = '—';
                    tdPctVinc.classList.add('text-muted');
                }

                tr.append(tdRegion, tdCotiz, tdProc, tdPct, tdProd, tdPctVinc);
                tbody.appendChild(tr);
            });
        }

        const vinRegionesToggle = document.getElementById('vin-regiones-toggle');
        const vinRegionesPanel = document.getElementById('vin-regiones-panel');
        const vinRegionesChevron = document.getElementById('vin-regiones-chevron');

        function setVinRegionesPanelAbierto(abierto) {
            if (!vinRegionesPanel) return;
            vinRegionesPanel.classList.toggle('d-none', !abierto);
            if (vinRegionesToggle) {
                vinRegionesToggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
            }
            if (vinRegionesChevron) {
                vinRegionesChevron.classList.toggle('bi-chevron-down', !abierto);
                vinRegionesChevron.classList.toggle('bi-chevron-up', abierto);
            }
        }
        if (vinRegionesToggle && vinRegionesPanel) {
            vinRegionesToggle.addEventListener('click', () => {
                setVinRegionesPanelAbierto(vinRegionesPanel.classList.contains('d-none'));
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

        function setEventosPanelAbierto(abierto) {
            if (!relEventosPanel) return;
            relEventosPanel.classList.toggle('d-none', !abierto);
            if (relEventosToggle) {
                relEventosToggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
            }
            if (relEventosChevron) {
                relEventosChevron.classList.toggle('bi-chevron-down', !abierto);
                relEventosChevron.classList.toggle('bi-chevron-up', abierto);
            }
        }
        if (relEventosToggle && relEventosPanel) {
            relEventosToggle.addEventListener('click', () => {
                setEventosPanelAbierto(relEventosPanel.classList.contains('d-none'));
            });
        }

        function formatearHoraEvento(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return '';
            return d.toLocaleTimeString('es-CL', { hour12: false });
        }

        function badgeClaseEvento(tipo) {
            switch (String(tipo || '')) {
                case 'esperando_worker':
                case 'worker_error':
                case 'mp_pagina_timeout':
                case 'mp_pagina_error':
                    return 'text-bg-warning';
                case 'region_error':
                    return 'text-bg-danger';
                case 'region_ok':
                case 'mp_pagina_ok':
                case 'completada':
                    return 'text-bg-success';
                case 'mp_pagina':
                case 'region':
                    return 'text-bg-info';
                case 'reencolada':
                case 'encolada':
                    return 'text-bg-secondary';
                case 'cancelada':
                    return 'text-bg-dark';
                default:
                    return 'text-bg-light text-dark border';
            }
        }

        function renderEventosCorrida(eventos) {
            if (!relEventos || !relEventosLista) return;
            const lista = Array.isArray(eventos) ? eventos : [];
            if (lista.length === 0) {
                relEventos.classList.add('d-none');
                relEventosLista.innerHTML = '';
                if (relEventosContador) relEventosContador.textContent = '0';
                return;
            }

            relEventos.classList.remove('d-none');
            if (relEventosContador) {
                relEventosContador.textContent = String(lista.length);
            }

            relEventosLista.innerHTML = lista.map((ev) => {
                const hora = formatearHoraEvento(ev?.t);
                const tipo = String(ev?.tipo || 'info');
                const texto = String(ev?.texto || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                return (
                    `<li class="d-flex gap-2 align-items-start py-1 border-bottom border-light-subtle">` +
                    `<span class="text-muted tabular-nums flex-shrink-0">${hora || '—'}</span>` +
                    `<span class="badge ${badgeClaseEvento(tipo)} flex-shrink-0">${tipo}</span>` +
                    `<span class="flex-grow-1">${texto}</span>` +
                    `</li>`
                );
            }).join('');
        }

        let syncParEnCurso = false;

        function formatearFechaSync(iso) {
            if (!iso) return null;
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return null;
            return d.toLocaleString('es-CL', { hour12: false });
        }

        function setSyncDetalleAbierto(prefijo, abierto) {
            const panel = document.getElementById(`sync-${prefijo}-detalle-panel`);
            const toggle = document.getElementById(`sync-${prefijo}-detalle-toggle`);
            const chevron = document.getElementById(`sync-${prefijo}-detalle-chevron`);
            if (!panel) return;
            panel.classList.toggle('d-none', !abierto);
            if (toggle) {
                toggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
            }
            if (chevron) {
                chevron.classList.toggle('bi-chevron-down', !abierto);
                chevron.classList.toggle('bi-chevron-up', abierto);
            }
        }

        function escapeHtmlSync(valor) {
            return String(valor ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function pintarPanelSync(prefijo, bloque, peer) {
            const badge = document.getElementById(`sync-${prefijo}-badge`);
            const resumen = document.getElementById(`sync-${prefijo}-resumen`);
            const detalle = document.getElementById(`sync-${prefijo}-detalle`);
            const errorEl = document.getElementById(`sync-${prefijo}-error`);
            const peerEl = document.getElementById(`sync-${prefijo}-peer`);
            const contador = document.getElementById(`sync-${prefijo}-detalle-contador`);
            const tbody = document.getElementById(`sync-${prefijo}-lotes-tbody`);
            const ultimoProcesoEl = document.getElementById(`sync-${prefijo}-ultimo-proceso`);
            if (!bloque) return;

            const pendientes = Number(bloque.pendientes) || 0;
            const codigos = Array.isArray(bloque.codigos) ? bloque.codigos : [];
            const lotes = Array.isArray(bloque.lotes) ? bloque.lotes : [];
            const ultimoOk = formatearFechaSync(bloque.ultimo_ok_at);
            const ultimoCount = bloque.ultimo_ok_count != null ? Number(bloque.ultimo_ok_count) : null;
            const ultimoError = String(bloque.ultimo_error || '').trim();

            if (badge) {
                badge.textContent = `${pendientes} pendiente${pendientes === 1 ? '' : 's'}`;
                badge.className = 'badge ' + (pendientes > 0 ? 'text-bg-warning' : 'text-bg-success');
            }
            if (peerEl) {
                peerEl.textContent = peer ? `→ ${peer}` : '';
            }
            if (contador) {
                contador.textContent = String(lotes.length);
            }

            const partesResumen = [];
            if (pendientes > 0) {
                partesResumen.push(`${pendientes} lote(s) en cola`);
            } else {
                partesResumen.push('Cola vacía');
            }
            if (prefijo === 'vin') {
                const locales = Number(bloque.locales_procesadas) || 0;
                const conPreview = Number(bloque.locales_con_preview) || 0;
                const sinPreview = Number(bloque.locales_sin_preview) || 0;
                const syncOk = Number(bloque.locales_sync_ok) || 0;
                const syncErr = Number(bloque.locales_sync_error) || 0;
                const syncOmit = Number(bloque.locales_sync_omitidos) || 0;
                const syncPend = Number(bloque.locales_sync_pendientes) || 0;
                if (locales > 0) {
                    partesResumen.push(`${locales} locales`);
                    if (conPreview > 0 || sinPreview > 0) {
                        partesResumen.push(`${conPreview} con preview` + (sinPreview > 0 ? ` · ${sinPreview} sin preview` : ''));
                    }
                    if (syncOk > 0 || syncErr > 0 || syncOmit > 0 || syncPend > 0) {
                        partesResumen.push(`par: ${syncOk} ok` +
                            (syncOmit > 0 ? ` · ${syncOmit} omit.` : '') +
                            (syncErr > 0 ? ` · ${syncErr} error` : '') +
                            (syncPend > 0 ? ` · ${syncPend} pend.` : ''));
                    }
                } else {
                    partesResumen.push('0 locales procesadas (hay que vincular aquí primero)');
                }
            }
            if (ultimoOk) {
                partesResumen.push(`Último OK: ${ultimoOk}` + (ultimoCount != null ? ` (${ultimoCount})` : ''));
            }
            if (resumen) {
                resumen.textContent = partesResumen.join(' · ');
                resumen.classList.toggle('text-muted', pendientes === 0 && !ultimoError);
            }

            if (ultimoProcesoEl) {
                const up = bloque.ultimo_proceso && typeof bloque.ultimo_proceso === 'object'
                    ? bloque.ultimo_proceso
                    : null;
                if (!up || !up.at) {
                    ultimoProcesoEl.innerHTML = '';
                    ultimoProcesoEl.classList.add('d-none');
                } else {
                    const cuando = formatearFechaSync(up.at) || '—';
                    const procesados = Number(up.procesados) || 0;
                    const fallos = Number(up.fallos) || 0;
                    const enProgreso = up.en_progreso === true;
                    const okProc = up.ok === true && fallos === 0;
                    const cods = Array.isArray(up.codigos) ? up.codigos : [];
                    const codsTxt = cods.length > 0
                        ? escapeHtmlSync(cods.slice(0, 8).join(', ') + (cods.length > 8 ? '…' : ''))
                        : '';
                    const errTxt = String(up.ultimo_error || '').trim();
                    let badgeClase;
                    let badgeTxt;
                    if (enProgreso) {
                        badgeClase = 'text-bg-info';
                        badgeTxt = 'Procesando…';
                    } else if (okProc) {
                        badgeClase = 'text-bg-success';
                        badgeTxt = 'OK';
                    } else if (procesados > 0) {
                        badgeClase = 'text-bg-warning';
                        badgeTxt = 'Parcial';
                    } else {
                        badgeClase = 'text-bg-danger';
                        badgeTxt = 'Con errores';
                    }
                    const spinner = enProgreso
                        ? '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> '
                        : '';
                    let html = `<div class="d-flex flex-wrap gap-1 align-items-center">
                        ${spinner}<span class="fw-semibold">Último proceso:</span>
                        <span class="badge ${badgeClase}">${escapeHtmlSync(badgeTxt)}</span>
                        <span class="text-muted tabular-nums">${escapeHtmlSync(cuando)}</span>
                        <span class="text-muted">· ${procesados} ${enProgreso ? 'enviado(s)' : 'procesado(s)'}${fallos > 0 ? ` · ${fallos} con error` : ''}</span>
                    </div>`;
                    if (codsTxt) {
                        html += `<div class="opc-meta text-muted mt-1">Códigos: ${codsTxt}</div>`;
                    }
                    if (errTxt) {
                        html += `<div class="opc-meta text-danger mt-1" title="${escapeHtmlSync(errTxt)}">${escapeHtmlSync(errTxt)}</div>`;
                    }
                    ultimoProcesoEl.innerHTML = html;
                    ultimoProcesoEl.classList.remove('d-none');
                }
            }

            const partesDetalle = [];
            if (codigos.length > 0) {
                partesDetalle.push(`Códigos: ${codigos.join(', ')}`);
            } else if (pendientes === 0) {
                partesDetalle.push('Sin lotes pendientes en cola.');
            } else {
                partesDetalle.push('Lotes sin códigos parseables.');
            }
            if (ultimoOk) {
                partesDetalle.push(`Último OK: ${ultimoOk}` + (ultimoCount != null ? ` (${ultimoCount} ítem(s))` : ''));
            }
            if (detalle) {
                detalle.textContent = partesDetalle.join(' · ');
            }

            if (errorEl) {
                if (ultimoError && pendientes > 0) {
                    errorEl.textContent = ultimoError;
                    errorEl.classList.remove('d-none');
                } else {
                    errorEl.textContent = '';
                    errorEl.classList.add('d-none');
                }
            }

            if (tbody) {
                if (lotes.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Sin lotes en cola.</td></tr>';
                } else {
                    tbody.innerHTML = lotes.map((lote, idx) => {
                        const cods = Array.isArray(lote.codigos) ? lote.codigos : [];
                        const codTxt = cods.length > 0
                            ? escapeHtmlSync(cods.slice(0, 8).join(', ') + (cods.length > 8 ? '…' : ''))
                            : '—';
                        const errTxt = String(lote.ultimo_error || '').trim();
                        const actualizado = formatearFechaSync(lote.updated_at || lote.created_at) || '—';
                        return `<tr>
                            <td class="tabular-nums text-muted">${idx + 1}</td>
                            <td class="small">${codTxt}</td>
                            <td class="text-end tabular-nums">${Number(lote.items) || 0}</td>
                            <td class="text-end tabular-nums">${Number(lote.intentos) || 0}</td>
                            <td class="small ${errTxt ? 'text-danger' : 'text-muted'}">${errTxt ? escapeHtmlSync(errTxt) : '—'}</td>
                            <td class="text-nowrap small tabular-nums">${escapeHtmlSync(actualizado)}</td>
                        </tr>`;
                    }).join('');
                }
            }
        }

        function aplicarSyncPar(syncPar) {
            if (!puedeBuscar || !syncPar) return;
            const peer = String(syncPar.peer || '').trim();
            pintarPanelSync('cot', syncPar.cotizaciones || {}, peer);
            pintarPanelSync('vin', syncPar.vinculaciones || {}, peer);

            const paneles = document.getElementById('sync-par-paneles');
            if (paneles) {
                paneles.classList.toggle('opacity-50', syncPar.url_configurada === false);
            }
        }

        async function postJsonSync(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            return { res, data };
        }

        async function sincronizarParManual(tipo) {
            if (syncParEnCurso) return;
            const usarLotes = Boolean(urls.syncParInicio && urls.syncParLote);
            if (!usarLotes && !urls.syncPar) return;
            syncParEnCurso = true;
            const btnCot = document.getElementById('btn-sync-cotizaciones');
            const btnVin = document.getElementById('btn-sync-vinculaciones');
            if (btnCot) btnCot.disabled = true;
            if (btnVin) btnVin.disabled = true;

            const esVinc = tipo === 'vinculaciones';
            const prefijo = esVinc ? 'vin' : 'cot';
            const btnActivo = esVinc ? btnVin : btnCot;
            const htmlOriginalBtn = btnActivo ? btnActivo.innerHTML : '';
            const resumenEl = document.getElementById(`sync-${prefijo}-resumen`);
            const htmlOriginalResumen = resumenEl ? resumenEl.innerHTML : '';
            const errorEl = document.getElementById(`sync-${prefijo}-error`);
            const spinner = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>';

            const setBtn = (txt) => {
                if (btnActivo) {
                    btnActivo.innerHTML = spinner + escapeHtmlSync(txt);
                }
            };
            const setResumen = (html) => {
                if (resumenEl) {
                    resumenEl.classList.remove('text-muted');
                    resumenEl.innerHTML = html;
                }
            };
            const mostrarError = (msg) => {
                if (errorEl) {
                    errorEl.textContent = msg;
                    errorEl.classList.remove('d-none');
                }
                setSyncDetalleAbierto(prefijo, true);
            };

            setBtn('Procesando…');
            setResumen(spinner + 'Despertando al par…');

            let lastSyncPar = null;
            try {
                if (!usarLotes) {
                    const { res, data } = await postJsonSync(urls.syncPar, { tipo });
                    if (data.sync_par) aplicarSyncPar(data.sync_par);
                    if (data.corrida) aplicarEstadoCorrida(data.corrida);
                    if (resumenEl && (data.mensaje || data.error)) resumenEl.textContent = data.mensaje || data.error;
                    if (!res.ok || data.ok === false) {
                        mostrarError(data.error || data.mensaje || (`HTTP ${res.status}`));
                    }
                    return;
                }

                // 1) Inicio: despierta al par y planifica la cola + reenvío pendiente.
                const inicio = await postJsonSync(urls.syncParInicio, { tipo });
                if (inicio.data.sync_par) {
                    lastSyncPar = inicio.data.sync_par;
                    aplicarSyncPar(lastSyncPar);
                }
                if (!inicio.res.ok || inicio.data.ok === false) {
                    mostrarError(inicio.data.error || (`HTTP ${inicio.res.status}`));
                    return;
                }

                const batch = Math.max(1, Number(inicio.data.batch_size) || 5);
                const colaTotal = Number(inicio.data.cola_total) || 0;
                const reenvioTotal = Number(inicio.data.reenvio_total) || 0;
                const etiqueta = esVinc ? 'vinculaciones' : 'cotizaciones';

                // Procesa una fase por lotes mostrando qué códigos van.
                const procesarFase = async (fase, total, verbo) => {
                    let offset = 0;
                    while (offset < total) {
                        const hasta = Math.min(offset + batch, total);
                        setBtn(verbo + ' ' + (offset + 1) + '–' + hasta + '/' + total);
                        setResumen(spinner + verbo + ' ' + hasta + '/' + total + ' ' + etiqueta + '…');
                        const { res, data } = await postJsonSync(urls.syncParLote, { tipo, fase, offset, limit: batch });
                        if (data.sync_par) {
                            lastSyncPar = data.sync_par;
                            aplicarSyncPar(lastSyncPar);
                        }
                        if (!res.ok || data.ok === false) {
                            mostrarError(data.error || (`HTTP ${res.status}`));
                            return false;
                        }
                        const lote = data.lote || {};
                        const cods = Array.isArray(lote.codigos) ? lote.codigos : [];
                        setResumen(spinner + verbo + ' ' + hasta + '/' + total +
                            (cods.length ? (' · ' + escapeHtmlSync(cods.slice(0, 6).join(', ')) + (cods.length > 6 ? '…' : '')) : ''));
                        offset = (Number(lote.offset) || offset) + (Number(lote.limit) || batch);
                        if (!lote.hay_mas) break;
                    }
                    return true;
                };

                // 2) Fase cola: procesa la cola pendiente del tipo (ambos tipos).
                if (colaTotal > 0) {
                    const ok = await procesarFase('cola', colaTotal, 'Procesando');
                    if (!ok) return;
                }

                // 3) Fase reenvío: solo vinculaciones locales ya procesadas.
                if (esVinc && reenvioTotal > 0) {
                    const ok = await procesarFase('reenvio', reenvioTotal, 'Enviando');
                    if (!ok) return;
                }

                // Repintado final: deja el resumen con el estado real (Último OK / proceso).
                if (lastSyncPar) {
                    aplicarSyncPar(lastSyncPar);
                }
            } catch (e) {
                if (resumenEl) resumenEl.innerHTML = htmlOriginalResumen;
                mostrarError(e.message || String(e));
            } finally {
                syncParEnCurso = false;
                if (btnCot) btnCot.disabled = false;
                if (btnVin) btnVin.disabled = false;
                if (btnActivo) btnActivo.innerHTML = htmlOriginalBtn;
            }
        }

        function aplicarEstadoCorrida(corrida) {
            if (!corrida) return;

            ocultarPageLoaderOportunidades();

            if (ultimaCorridaId !== corrida.id) {
                ultimaCorridaId = corrida.id;
                intentosCambioDia = 0;
                setPasosPanelAbierto(false);
            }

            estado.classList.remove('d-none');
            placeholder.classList.add('d-none');
            const inicio = corrida.inicio ? new Date(corrida.inicio) : null;
            const fin = corrida.fin ? new Date(corrida.fin) : null;
            inicioMs = inicio && !Number.isNaN(inicio.getTime()) ? inicio.getTime() : inicioMs;
            finMs = fin && !Number.isNaN(fin.getTime()) ? fin.getTime() : null;
            relInicio.textContent = inicio ? inicio.toLocaleTimeString('es-CL', {
                hour12: false
            }) : '—';
            relFin.textContent = fin ? fin.toLocaleTimeString('es-CL', {
                hour12: false
            }) : '—';
            if (relUsuario) {
                const usuario = String(corrida.usuario || '').trim();
                relUsuario.textContent = usuario !== '' ? usuario : 'sistema';
            }
            setProgreso(Number(corrida.progreso) || 0);
            const duracionTexto = corrida.duracion_texto ||
                (corrida.duracion_segundos != null ? formatearDuracionSegs(corrida.duracion_segundos) : null) ||
                (inicioMs ? formatearDuracionSegs(((finMs || Date.now()) - inicioMs) / 1000) : null);
            if (duracionTexto) {
                relDuracion.textContent = duracionTexto;
            } else {
                actualizarDuracion();
            }
            relDetalle.textContent = mensajeConTiempo(corrida.mensaje, !corrida.estado || corrida.estado === 'running' ? null : duracionTexto);

            // Solo reemplazar listado cuando el backend envía items (poll liviano no los trae).
            if (Array.isArray(corrida.items)) {
                porCodigo = new Map();
                cargarItems(corrida.items);
                sincronizarVisitasLocalesEnMapa();
            }
            aplicarEstadoVinculo(corrida.vinculo || null);
            actualizarBotonProcesarVinculo(corrida.vinculo || null, corrida.vinculo_aviso || null, corrida.vinculo_pendientes);
            if (corrida.sync_par) {
                aplicarSyncPar(corrida.sync_par);
            }
            const activo = corrida.estado === 'running';
            const esperandoWorker = corridaEsperandoWorker(corrida);
            const cambiandoDia = corrida.estado === 'completed' &&
                Boolean(corrida.fecha_siguiente_pendiente) &&
                intentosCambioDia < 30;
            const vinculoActivo = corrida.vinculo && corrida.vinculo.estado === 'running';
            // Mostrar el listado si hay filas o si la búsqueda está en curso (antes quedaba oculto
            // cuando listarGuardadasHoy() venía vacío en catch-up de días anteriores).
            if (resultados && (porCodigo.size > 0 || activo)) {
                resultados.classList.remove('d-none');
            }
            renderTabla();
            renderPasosCorrida(corrida.pasos_resumen || [], corrida);
            renderEventosCorrida(corrida.eventos || []);
            actualizarDebugDesdeCorrida(corrida);
            if (corrida.fecha_busqueda && relFecha) {
                relFecha.textContent = cambiandoDia ?
                    `(finalizado ${formatearDia(corrida.fecha_busqueda)}; sigue ${formatearDia(corrida.fecha_siguiente_pendiente)})` :
                    `(buscando ${formatearDia(corrida.fecha_busqueda)})`;
            }

            cancelado = corrida.estado === 'cancelled';
            // La búsqueda en curso no bloquea el listado; solo deshabilita «Buscar» y muestra «Cancelar».
            buscando = activo || cambiandoDia;
            if (btn) {
                btn.disabled = @json($palabras === []) ||
                    ((activo && !esperandoWorker) || cambiandoDia);
            }
            if (btnCancelar) {
                const mostrarCancelar = activo || cambiandoDia;
                btnCancelar.classList.toggle('d-none', !mostrarCancelar);
                btnCancelar.disabled = !mostrarCancelar;
            }
            relBar.classList.toggle('progress-bar-animated', (activo && !esperandoWorker) || cambiandoDia);
            if (relProgresoWrap) {
                relProgresoWrap.classList.toggle('d-none', esperandoWorker || !(activo || cambiandoDia));
            }

            if (esperandoWorker && porCodigo.size === 0 && !itemsCargadosEsperaWorker && urls.estado) {
                itemsCargadosEsperaWorker = true;
                getJson(urlEstado({ items: true }))
                    .then((data) => {
                        if (data?.corrida) {
                            aplicarEstadoCorrida(data.corrida);
                        }
                    })
                    .catch(() => {});
            }
            if (!esperandoWorker) {
                itemsCargadosEsperaWorker = false;
            }

            const fallidos = Number(corrida.pasos_fallidos) || 0;
            const ultimoError = corrida.ultimo_error && typeof corrida.ultimo_error === 'object' ?
                corrida.ultimo_error :
                null;
            if (corrida.reanudada_auto) {
                mostrarAvisoPaso(
                    corrida.mensaje ||
                    'La búsqueda se retomó automáticamente desde el último paso guardado.',
                );
            } else if (esperandoWorker) {
                mostrarAvisoPaso(
                    corrida.mensaje ||
                    'Búsqueda en cola esperando worker (RUN_QUEUE_WORKER=true en Render). ' +
                    'Puede cancelar la búsqueda o seguir cotizando con el listado ya guardado.',
                );
            } else if (corrida.worker_stalled) {
                mostrarAvisoPaso(
                    'La búsqueda no avanza en el servidor (posible worker detenido o Mercado Público colgado). ' +
                    'Se intentará retomar automáticamente; si no avanza, verifique RUN_QUEUE_WORKER=true en Render.',
                );
                if (urls.reanudar) {
                    reanudarBusquedaSilencioso();
                }
            } else if (activo && ultimoError) {
                const tipoErr = String(ultimoError.tipo || '').trim();
                const msgErr = String(ultimoError.mensaje || '').trim();
                const pagErr = ultimoError.pagina != null ? ` pág ${ultimoError.pagina}` : '';
                if (tipoErr === 'base_datos' || /sqlstate|postgres|database|pgsql|mysql/i.test(msgErr)) {
                    mostrarErrorFatal(
                        `Error de base de datos${pagErr}: ${msgErr || 'sin detalle'}. ` +
                        'La búsqueda intenta seguir con la siguiente página/región.',
                    );
                } else if (msgErr) {
                    mostrarAvisoPaso(
                        `Error${pagErr}${tipoErr ? ` (${tipoErr})` : ''}: ${msgErr}. ` +
                        'Se continúa con la siguiente página o región.',
                    );
                } else if (String(corrida.mensaje || '').toLowerCase().includes('fallido')) {
                    mostrarAvisoPaso('Un paso falló; la búsqueda sigue con la siguiente región o reintento.');
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

            const pollActivo = activo || cambiandoDia || vinculoActivo;
            if (pollActivo) {
                setRenderKeepAliveProceso(!esperandoWorker);
                detenerPolling();
                if (cambiandoDia) {
                    intentosCambioDia++;
                    relDetalle.textContent = `Día ${formatearDia(corrida.fecha_busqueda)} terminado. Iniciando búsqueda del ${formatearDia(corrida.fecha_siguiente_pendiente)}…`;
                }
                const pollMs = esperandoWorker ? 30000 : 2000;
                pollTimer = setTimeout(consultarEstado, pollMs);
            } else {
                setRenderKeepAliveProceso(false);
                detenerPolling();
                if (tickTimer) {
                    clearInterval(tickTimer);
                    tickTimer = null;
                }
                actualizarDuracion();
            }
        }

        let ultimaVinculoId = null;

        function aplicarEstadoVinculo(vinculo) {
            const card = document.getElementById('vinculo-estado');
            const vinInicio = document.getElementById('vin-inicio');
            const vinFin = document.getElementById('vin-fin');
            const vinDuracion = document.getElementById('vin-duracion');
            const vinUltima = document.getElementById('vin-ultima');
            const vinUltimaHace = document.getElementById('vin-ultima-hace');
            const vinBar = document.getElementById('vin-progreso-bar');
            const vinWrap = document.getElementById('vin-progreso-wrap');
            const vinDetalle = document.getElementById('vin-detalle');
            const vinTxt = document.getElementById('vin-progreso-txt');
            if (!card) {
                return;
            }
            if (!vinculo) {
                ultimaVinculoId = null;
                document.querySelectorAll('.vin-meta-extra').forEach((el) => el.classList.add('d-none'));
                if (vinWrap) vinWrap.classList.add('d-none');
                if (vinDetalle) {
                    vinDetalle.textContent = 'Pulse Procesar vinculaciones para vincular cotizaciones al maestro.';
                    vinDetalle.classList.add('text-muted');
                    vinDetalle.classList.remove('text-warning', 'text-danger');
                }
                renderVinculoRegiones(null);
                return;
            }

            if (ultimaVinculoId !== vinculo.id) {
                ultimaVinculoId = vinculo.id;
                setVinRegionesPanelAbierto(false);
            }

            document.querySelectorAll('.vin-meta-extra').forEach((el) => el.classList.remove('d-none'));
            const inicio = vinculo.inicio ? new Date(vinculo.inicio) : null;
            const fin = vinculo.fin ? new Date(vinculo.fin) : null;
            if (vinInicio) {
                vinInicio.textContent = inicio && !Number.isNaN(inicio.getTime()) ?
                    inicio.toLocaleTimeString('es-CL', {
                        hour12: false
                    }) :
                    '—';
            }
            if (vinFin) {
                vinFin.textContent = fin && !Number.isNaN(fin.getTime()) ?
                    fin.toLocaleTimeString('es-CL', {
                        hour12: false
                    }) :
                    '—';
            }
            const duracionTexto = vinculo.duracion_texto ||
                (vinculo.duracion_segundos != null ? formatearDuracionSegs(vinculo.duracion_segundos) : null);
            if (vinDuracion) {
                vinDuracion.textContent = duracionTexto || '—';
            }
            const ultima = vinculo.ultima_actividad ? new Date(vinculo.ultima_actividad) : null;
            if (vinUltima) {
                vinUltima.textContent = ultima && !Number.isNaN(ultima.getTime())
                    ? ultima.toLocaleTimeString('es-CL', { hour12: false })
                    : '—';
            }
            if (vinUltimaHace) {
                const hace = Number(vinculo.ultima_actividad_hace_segundos);
                if (Number.isFinite(hace) && vinculo.estado === 'running') {
                    if (hace < 60) {
                        vinUltimaHace.textContent = `(hace ${hace}s)`;
                        vinUltimaHace.className = hace > 120
                            ? 'text-danger ms-1'
                            : (hace > 45 ? 'text-warning ms-1' : 'text-muted ms-1');
                    } else {
                        const mins = Math.floor(hace / 60);
                        const segs = hace % 60;
                        vinUltimaHace.textContent = `(hace ${mins}m ${String(segs).padStart(2, '0')}s)`;
                        vinUltimaHace.className = hace > 120 ? 'text-danger ms-1' : 'text-warning ms-1';
                    }
                } else {
                    vinUltimaHace.textContent = '';
                }
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
            // Barra solo mientras la vinculación está en curso.
            if (vinWrap) {
                vinWrap.classList.toggle('d-none', vinculo.estado !== 'running');
            }
            if (vinDetalle) {
                vinDetalle.textContent = String(vinculo.mensaje || 'Vinculación con maestro…');
                if (vinculo.reanudada_auto) {
                    vinDetalle.classList.remove('text-muted');
                    vinDetalle.classList.add('text-warning');
                } else if (vinculo.worker_stalled) {
                    vinDetalle.textContent = String(vinculo.mensaje || '') ||
                        'Vinculación sin avance; se intentará retomar automáticamente…';
                    vinDetalle.classList.remove('text-muted');
                    vinDetalle.classList.add('text-danger');
                } else {
                    vinDetalle.classList.add('text-muted');
                    vinDetalle.classList.remove('text-warning', 'text-danger');
                }
            }
            renderVinculoRegiones(vinculo);
        }

        function actualizarBotonProcesarVinculo(vinculo, aviso, pendientesRaw) {
            const vinculoActivo = vinculo && vinculo.estado === 'running';
            const pendientes = Number(pendientesRaw);
            const nPendientes = Number.isFinite(pendientes) ?
                pendientes :
                (aviso && Number(aviso.pendientes) > 0 ? Number(aviso.pendientes) : 0);

            if (btnIniciarVinculo) {
                btnIniciarVinculo.classList.toggle('d-none', !!vinculoActivo);
                btnIniciarVinculo.disabled = !!vinculoActivo || iniciandoVinculo || cancelandoVinculo;
            }
            if (btnCancelarVinculo) {
                btnCancelarVinculo.classList.toggle('d-none', !vinculoActivo);
                btnCancelarVinculo.disabled = !vinculoActivo || cancelandoVinculo;
            }
            if (btnIniciarVinculoBadge) {
                if (!vinculoActivo && nPendientes > 0) {
                    btnIniciarVinculoBadge.textContent = String(nPendientes);
                    btnIniciarVinculoBadge.classList.remove('d-none');
                } else {
                    btnIniciarVinculoBadge.classList.add('d-none');
                }
            }

            const mostrarAviso = !vinculoActivo && aviso && aviso.puede_iniciar;
            if (vinculoAviso) {
                vinculoAviso.classList.toggle('d-none', !mostrarAviso);
            }
            if (vinculoAvisoTexto && aviso) {
                vinculoAvisoTexto.textContent = String(aviso.mensaje || '');
            }
        }

        let iniciandoVinculo = false;
        let cancelandoVinculo = false;
        async function iniciarVinculoManual() {
            if (!urls.iniciarVinculo || iniciandoVinculo || cancelandoVinculo) return;
            iniciandoVinculo = true;
            if (btnIniciarVinculo) btnIniciarVinculo.disabled = true;
            setVinRegionesPanelAbierto(false);
            try {
                const data = await postJson(urls.iniciarVinculo, {});
                if (data && data.corrida) {
                    aplicarEstadoCorrida(data.corrida);
                }
            } catch (e) {
                if (vinculoAviso) vinculoAviso.classList.remove('d-none');
                if (vinculoAvisoTexto) {
                    vinculoAvisoTexto.textContent = e.message || String(e);
                }
                if (btnIniciarVinculo) {
                    btnIniciarVinculo.classList.remove('d-none');
                    btnIniciarVinculo.disabled = false;
                }
            } finally {
                iniciandoVinculo = false;
            }
        }

        async function cancelarVinculoManual() {
            if (!urls.cancelarVinculo || cancelandoVinculo) return;
            cancelandoVinculo = true;
            if (btnCancelarVinculo) btnCancelarVinculo.disabled = true;
            try {
                const data = await postJson(urls.cancelarVinculo, {});
                if (data && data.corrida) {
                    aplicarEstadoCorrida(data.corrida);
                } else {
                    consultarEstado();
                }
            } catch (e) {
                const vinDetalle = document.getElementById('vin-detalle');
                if (vinDetalle) {
                    vinDetalle.textContent = e.message || String(e);
                    vinDetalle.classList.remove('text-muted');
                    vinDetalle.classList.add('text-danger');
                }
                if (btnCancelarVinculo) btnCancelarVinculo.disabled = false;
            } finally {
                cancelandoVinculo = false;
            }
        }

        let pollEstadoCount = 0;
        let ultimaEncontradasPoll = -1;
        let itemsCargadosEsperaWorker = false;

        function corridaEsperandoWorker(corrida) {
            if (!corrida || corrida.estado !== 'running') {
                return false;
            }
            if (corrida.esperando_worker === true) {
                return true;
            }
            const mensaje = String(corrida.mensaje || '').toLowerCase();
            return mensaje.includes('esperando worker')
                || (mensaje.includes('job en cola sin tomar'));
        }

        function urlEstado(opts) {
            const params = new URLSearchParams();
            if (opts?.items) params.set('items', '1');
            if (opts?.syncPar) params.set('sync_par', '1');
            const q = params.toString();
            return q ? `${urls.estado}?${q}` : urls.estado;
        }

        async function consultarEstado(opts = {}) {
            if (!urls.estado) return;
            try {
                pollEstadoCount += 1;
                const forzarItems = Boolean(opts.items);
                // Items: forzado, 1.er poll, o cada 5 ticks (~10s en búsqueda).
                const pedirItems = forzarItems ||
                    pollEstadoCount === 1 ||
                    pollEstadoCount % 5 === 0;
                const data = await getJson(urlEstado({
                    items: pedirItems,
                    syncPar: Boolean(opts.syncPar),
                }));
                const encontradas = Number(data?.corrida?.oportunidades_encontradas ?? -1);
                if (!pedirItems && encontradas >= 0 && encontradas !== ultimaEncontradasPoll && urls.estado) {
                    ultimaEncontradasPoll = encontradas;
                    const conItems = await getJson(urlEstado({ items: true }));
                    aplicarEstadoCorrida(conItems.corrida);
                    if (conItems.sync_par) aplicarSyncPar(conItems.sync_par);
                    return;
                }
                if (encontradas >= 0) {
                    ultimaEncontradasPoll = encontradas;
                }
                aplicarEstadoCorrida(data.corrida);
                if (data.sync_par) {
                    aplicarSyncPar(data.sync_par);
                }
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
            setPasosPanelAbierto(false);
            relFin.textContent = '—';
            relInicio.textContent = '…';
            setProgreso(0);
            relBar.classList.add('progress-bar-animated');
            if (relProgresoWrap) relProgresoWrap.classList.remove('d-none');
            relDetalle.textContent = 'Iniciando consulta a Mercado Público…';
            renderTabla();

            try {
                const payload = {};
                const chkVentana = document.getElementById('chk-ventana-completa');
                const inpDesde = document.getElementById('inp-cambio-desde');
                if (chkVentana?.checked) {
                    payload.ventana_completa = true;
                }
                const desdeRaw = String(inpDesde?.value || '').trim();
                if (desdeRaw !== '' && !payload.ventana_completa) {
                    // datetime-local → ISO local sin Z (backend parsea en APP_TIMEZONE).
                    payload.cambio_desde = desdeRaw.length === 16 ? `${desdeRaw}:00` : desdeRaw;
                }
                const data = await postJson(urls.iniciar, payload);
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

        // Al abrir: UI liviana; listado/items se piden por AJAX (no bloquean el HTML).
        restaurarFiltros();
        if (Array.isArray(guardadasIniciales) && guardadasIniciales.length > 0) {
            cargarItems(guardadasIniciales);
            sincronizarVisitasLocalesEnMapa();
            if (fechaBusquedaInicial && relFecha) {
                relFecha.textContent = `(${fechaBusquedaInicial})`;
            }
            if (estado && relDetalle) {
                estado.classList.remove('d-none');
                relDetalle.textContent = puedeBuscar ?
                    `${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} vigentes. Pulse Buscar para consultar de nuevo.` :
                    `${porCodigo.size} oportunidad${porCodigo.size === 1 ? '' : 'es'} sincronizadas vigentes.`;
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
        } else if (puedeBuscar) {
            actualizarBotonProcesarVinculo(null, null, vinculoPendientesInicial);
        }

        // Carga diferida del listado; sync al par en segundo plano (no bloquea el listado).
        if (urls.estado) {
            if (estado && relDetalle && porCodigo.size === 0) {
                estado.classList.remove('d-none');
                relDetalle.textContent = 'Cargando oportunidades…';
            }
            (async () => {
                try {
                    await consultarEstado({ items: true, syncPar: false });
                    if (puedeBuscar) {
                        const data = await getJson(urlEstado({ syncPar: true }));
                        if (data.sync_par) {
                            aplicarSyncPar(data.sync_par);
                        }
                    }
                } catch (_e) {
                    // consultarEstado ya reintenta polling
                }
            })();
        }

        if (puedeBuscar) {
            if (syncParInicial) aplicarSyncPar(syncParInicial);
            btn?.addEventListener('click', buscar);
            btnCancelar?.addEventListener('click', cancelarBusqueda);
            btnIniciarVinculo?.addEventListener('click', iniciarVinculoManual);
            btnCancelarVinculo?.addEventListener('click', cancelarVinculoManual);
            document.getElementById('btn-sync-cotizaciones')?.addEventListener('click', () => sincronizarParManual('cotizaciones'));
            document.getElementById('btn-sync-vinculaciones')?.addEventListener('click', () => sincronizarParManual('vinculaciones'));
            document.getElementById('sync-cot-detalle-toggle')?.addEventListener('click', () => {
                const panel = document.getElementById('sync-cot-detalle-panel');
                setSyncDetalleAbierto('cot', !!panel?.classList.contains('d-none'));
            });
            document.getElementById('sync-vin-detalle-toggle')?.addEventListener('click', () => {
                const panel = document.getElementById('sync-vin-detalle-panel');
                setSyncDetalleAbierto('vin', !!panel?.classList.contains('d-none'));
            });
            setSyncDetalleAbierto('cot', false);
            setSyncDetalleAbierto('vin', false);
        }
    })();
</script>
@endpush
