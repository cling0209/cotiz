@extends('layouts.admin')

@section('title', 'Listado cotizaciones')

@section('content')
@php
    $listadoRetorno = \App\Support\CotizacionListadoRetorno::paraListado(
        $filtros,
        (int) $cotizaciones->currentPage(),
    );
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Listado cotizaciones</h1>
        <div class="d-flex gap-2">
            @if($cotizacionPendienteSinNumero ?? null)
                <a href="{{ route('admin.cotizaciones.edit', $cotizacionPendienteSinNumero->nronota) }}" class="btn btn-warning btn-sm">
                    <i class="bi bi-exclamation-circle"></i> Completar #{{ $cotizacionPendienteSinNumero->nronota }}
                </a>
            @else
                <form action="{{ route('admin.cotizaciones.create') }}" method="post">@csrf
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva</button>
                </form>
                <form action="{{ route('admin.cotizaciones.create') }}" method="post">@csrf
                    <input type="hidden" name="es_interna" value="1">
                    <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva interna</button>
                </form>
            @endif
            <a href="{{ route('admin.cotizaciones.retomar') }}" class="btn btn-outline-secondary btn-sm">Retomar &uacute;ltima</a>
            <a href="{{ route('admin.cotizaciones.carga-archivo.index') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-upload"></i> Cargar cotización
            </a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="{{ route('admin.cotizaciones.index') }}" class="row g-3 align-items-end">
                <input type="hidden" name="orden_campo" value="{{ $filtros['orden_campo'] }}">
                <input type="hidden" name="orden_dir" value="{{ $filtros['orden_dir'] }}">
                <div class="col-md-2">
                    <label class="form-label">Desde</label>
                    <input type="date" name="fechadesde" class="form-control form-control-sm" value="{{ $filtros['fechadesde'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="fechahasta" class="form-control form-control-sm" value="{{ $filtros['fechahasta'] }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar por fecha</button>
                </div>
                <div class="col-md-2">
                    <label class="form-label">N&ordm; nota</label>
                    <input type="number" name="nronota" class="form-control form-control-sm" value="{{ $filtros['nronota'] ?: '' }}" min="0">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar nota</button>
                </div>
                <div class="col-md-3">
                    <label class="form-label">N&ordm; cotizaci&oacute;n (encargado)</label>
                    <input type="text" name="cotizacion" class="form-control form-control-sm" value="{{ $filtros['cotizacion'] }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar cotiz.</button>
                </div>
                <div class="col-md-auto">
                    <div class="form-check mb-0">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            name="solo_asignadas"
                            id="filtro-solo-asignadas"
                            value="1"
                            @checked(!empty($filtros['solo_asignadas']))
                            onchange="this.form.submit()"
                        >
                        <label class="form-check-label" for="filtro-solo-asignadas">Solo asignadas</label>
                    </div>
                </div>
                @if($puedeVerEstadoMp ?? false)
                    <div class="col-md-2">
                        <label class="form-label" for="filtro-estado-mp">Estado MP</label>
                        <select name="estado_mp" id="filtro-estado-mp" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            @foreach(($estadosMpFiltro ?? []) as $valor => $label)
                                <option value="{{ $valor }}" @selected(($filtros['estado_mp'] ?? '') === $valor)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-secondary btn-sm">Filtrar estado MP</button>
                    </div>
                @endif
            </form>
        </div>
    </div>

    @if(($segundoLlamadoParaPostular ?? collect())->isNotEmpty())
        <div class="alert alert-warning border-warning shadow-sm mb-3 alerta-segundo-llamado" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-0"></i>
                <div>
                    <strong>Atención:</strong>
                    @if($segundoLlamadoParaPostular->count() === 1)
                        hay 1 cotización lista para postular a segundo llamado.
                    @else
                        hay {{ $segundoLlamadoParaPostular->count() }} cotizaciones listas para postular a segundo llamado.
                    @endif
                    <div class="small mt-1 mb-0">
                        Notas:
                        @foreach($segundoLlamadoParaPostular as $item)
                            @php
                                $cierreSegundo = $item->fecha_cierre_segundo_llamado
                                    ? \Illuminate\Support\Carbon::parse($item->fecha_cierre_segundo_llamado)->format('d/m/Y H:i')
                                    : null;
                            @endphp
                            <div>
                                <a href="{{ route('admin.cotizaciones.edit', array_merge(['nronota' => $item->nronota], $listadoRetorno)) }}" class="fw-semibold text-decoration-underline">
                                    #{{ $item->nronota }}
                                    @if($item->encargado)
                                        ({{ $item->encargado }})
                                    @endif
                                </a>
                                @if($cierreSegundo)
                                    <span class="text-dark"> — cierre 2° llamado: {{ $cierreSegundo }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        @php
                            $sortLink = fn ($campo, $dir) => route('admin.cotizaciones.index', array_merge($filtros, ['orden_campo' => $campo, 'orden_dir' => $dir, 'page' => 1]));
                            $nronotasSegundoLlamado = $nronotasSegundoLlamado ?? [];
                            $segundoLlamadoPorNota = ($segundoLlamadoParaPostular ?? collect())->keyBy('nronota');
                        @endphp
                        <th>
                            Nota
                            <a href="{{ $sortLink('nronota', 'ASC') }}" class="text-white-50 small">&#9650;</a>
                            <a href="{{ $sortLink('nronota', 'DESC') }}" class="text-white-50 small">&#9660;</a>
                        </th>
                        <th>Nota origen</th>
                        <th>
                            Fecha
                            <a href="{{ $sortLink('fecha', 'ASC') }}" class="text-white-50 small">&#9650;</a>
                            <a href="{{ $sortLink('fecha', 'DESC') }}" class="text-white-50 small">&#9660;</a>
                        </th>
                        <th>Empresa</th>
                        <th class="text-end">
                            Total
                            <a href="{{ $sortLink('total', 'ASC') }}" class="text-white-50 small">&#9650;</a>
                            <a href="{{ $sortLink('total', 'DESC') }}" class="text-white-50 small">&#9660;</a>
                        </th>
                        <th>Cotizaci&oacute;n</th>
                        <th>Usuario</th>
                        <th>Obs. ejecutivo</th>
                        <th>Estado</th>
                        <th>OC / env&iacute;o</th>
                            @if($puedeVerEstadoMp ?? false)
                                <th>Estado MP</th>
                                <th>Ganador / OC</th>
                            @endif
                        <th class="text-end" style="min-width:18rem">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $colspanListado = ($puedeVerEstadoMp ?? false) ? 13 : 11;
                    @endphp
                    @forelse($cotizaciones as $nota)
                        @php
                            $estaAceptada = strtolower(trim((string) $nota->estado)) === 'aceptada';
                            $sinUsuario = trim((string) $nota->usuario) === '';
                            $esSegundoLlamado = in_array((int) $nota->nronota, $nronotasSegundoLlamado, true);
                            $esInterna = $nota->esCotizacionInterna();
                            $estadoMp = $esInterna
                                ? 'no_aplica'
                                : ($nota->mpSeguimiento?->resultado_propio ?: 'sin_consultar');
                            $esGanadorPropio = $estadoMp === 'cerrada' && ! empty($nota->mpSeguimiento?->es_ganador_propio);
                            $esGanadorGrupo = $nota->mpSeguimiento?->esGanadorGrupo() ?? false;
                            $esGanadorPropioMp = $nota->mpSeguimiento?->esGanadorPropio() ?? false;
                            $estadoOcMp = $nota->mpSeguimiento?->estadoOrdenCompraMp();
                            $textoOcMp = $nota->mpSeguimiento?->textoOrdenCompraMp() ?? '—';
                        @endphp
                        <tr @class([
                            'table-warning fila-segundo-llamado' => $esSegundoLlamado,
                            'fila-ganador-propio' => $esGanadorPropio,
                        ])>
                            <td>
                                {{ $nota->nronota }}
                                @if($esSegundoLlamado)
                                    <span class="badge text-bg-warning ms-1">2° llamado</span>
                                @endif
                            </td>
                            <td>
                                @if($nota->fueRecibidaPorApi())
                                    {{ $nota->notaorigen }}
                                @else
                                    &mdash;
                                @endif
                            </td>
                            <td>{{ $nota->fecha?->format('d/m/Y') }}</td>
                            <td>{{ $nota->empresa }}</td>
                            <td class="text-end">${{ number_format($nota->total_calculado ?? 0, 0, ',', '.') }}</td>
                            <td>
                                <strong>{{ $nota->encargado }}</strong>
                                @if($nota->esCopiaDeCotizacion())
                                    <span class="badge text-bg-secondary ms-1" title="Copia {{ $nota->correlativo }} del mismo c&oacute;digo de Mercado P&uacute;blico">
                                        Copia {{ $nota->correlativo }}
                                    </span>
                                @endif
                                @if($esSegundoLlamado)
                                    @php
                                        $cierreFila = optional($segundoLlamadoPorNota->get($nota->nronota))->fecha_cierre_segundo_llamado;
                                        $cierreFilaFmt = $cierreFila
                                            ? \Illuminate\Support\Carbon::parse($cierreFila)->format('d/m/Y H:i')
                                            : null;
                                    @endphp
                                    @if($cierreFilaFmt)
                                        <div class="small text-muted">Cierre 2°: {{ $cierreFilaFmt }}</div>
                                    @endif
                                @endif
                            </td>
                            <td>
                                {{ $nota->usuarioRel?->fullName() ?: $nota->usuario }}
                                @if(trim((string) ($nota->asignado_por ?? '')) !== '')
                                    @php
                                        $asignadoPorNombre = $nota->asignadoPorRel?->fullName() ?: $nota->asignado_por;
                                        $asignadoAtFmt = $nota->asignado_at
                                            ? $nota->asignado_at->timezone(config('app.timezone'))->format('d/m/Y H:i')
                                            : null;
                                    @endphp
                                    <div class="small text-muted">
                                        Asignada por {{ $asignadoPorNombre }}@if($asignadoAtFmt) · {{ $asignadoAtFmt }}@endif
                                    </div>
                                @endif
                            </td>
                            <td>
                                @php
                                    $obsEjecutivo = trim((string) ($nota->observacion_ejecutivo ?? ''));
                                    $obsEjecutivoTitle = $obsEjecutivo !== ''
                                        ? \Illuminate\Support\Str::of($obsEjecutivo)->replace(["\r\n", "\n", "\r"], ' ')->trim()->toString()
                                        : '';
                                @endphp
                                @if($obsEjecutivo === '')
                                    —
                                @else
                                    <span class="d-inline-block text-truncate align-middle" style="max-width: 9rem;" title="{{ $obsEjecutivoTitle }}">
                                        {{ \Illuminate\Support\Str::limit($obsEjecutivo, 40) }}
                                    </span>
                                @endif
                            </td>
                            <td>{{ $nota->estado ?: '—' }}</td>
                            <td class="small text-nowrap">
                                @include('admin.cotizaciones.partials.celda-oc-envio', ['nota' => $nota])
                            </td>
                            @if($puedeVerEstadoMp ?? false)
                                <td>@include('admin.compra-agil.partials.resultado-badge', ['resultado' => $estadoMp])</td>
                                <td>
                                    @if($esGanadorGrupo)
                                        <span class="badge {{ $esGanadorPropioMp ? 'text-bg-success' : 'text-bg-secondary' }}{{ $esGanadorPropio ? ' badge-ganador-propio-destello' : '' }}">
                                            Ganador {{ $nota->mpSeguimiento->etiquetaGanadorGrupo() }}
                                        </span>
                                        @if($estadoOcMp !== null)
                                            <div class="small mt-1">
                                                OC:
                                                <span class="{{ $estadoOcMp->claseCss() }}">{{ $textoOcMp }}</span>
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                            @endif
                            <td class="text-end">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <a href="{{ route('admin.cotizaciones.edit', array_merge(['nronota' => $nota->nronota], $listadoRetorno)) }}" class="btn btn-outline-primary btn-sm">Ver</a>

                                    @if(trim((string) $nota->encargado) !== '')
                                        <form method="post" action="{{ route('admin.cotizaciones.duplicar', $nota->nronota) }}" class="d-inline js-duplicar-cotizacion">
                                            @csrf
                                            <input type="hidden" name="copiar_detalle" value="1">
                                            @include('admin.cotizaciones._filtros_ocultos', ['filtros' => $filtros, 'page' => $cotizaciones->currentPage()])
                                            <button type="submit" class="btn btn-outline-secondary btn-sm">Duplicar</button>
                                        </form>
                                    @endif

                                    @if((int) $nota->enviadoapi === 0 && ! $nota->fueRecibidaPorApi())
                                        @php
                                            $parEnvio = $parEnvioPorNota[$nota->nronota] ?? null;
                                        @endphp
                                        @if(($parEnvio['existe'] ?? false) === true)
                                            <span class="small text-danger" title="{{ $parEnvio['mensaje'] ?? '' }}">
                                                Ya existe en el otro sitio
                                            </span>
                                        @else
                                        <form method="post" action="{{ route('admin.cotizaciones.enviar', $nota->nronota) }}" class="d-inline"
                                              data-confirm="¿Enviar la cotización #{{ $nota->nronota }}{{ trim((string) $nota->encargado) !== '' ? ' («'.trim((string) $nota->encargado).'»)' : '' }} al otro sitio (Romulo ↔ Reicol)? En el otro sitio queda registrada a nombre de {{ trim((string) $nota->usuario) !== '' ? $nota->usuario : 'su ejecutivo' }}. Solo se puede enviar una vez.">
                                            @csrf
                                            @include('admin.cotizaciones._filtros_ocultos', ['filtros' => $filtros, 'page' => $cotizaciones->currentPage()])
                                            <button type="submit" class="btn btn-outline-secondary btn-sm">Enviar</button>
                                        </form>
                                        @endif
                                    @endif

                                    @if($puedeGestionar)
                                        @if($sinUsuario)
                                            <a href="{{ route('admin.cotizaciones.asignar', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Asignar</a>
                                        @endif

                                        @if(!$estaAceptada)
                                            <button type="button"
                                                    class="btn btn-outline-success btn-sm js-abrir-aceptar-cotizacion"
                                                    data-nronota="{{ $nota->nronota }}"
                                                    data-action="{{ route('admin.cotizaciones.aceptar', $nota->nronota) }}"
                                                    data-ocompra-manual="{{ trim((string) $nota->ocompra) }}"
                                                    data-ocompra-mp="{{ $nota->mpSeguimiento?->ocompraMp() ?? '' }}"
                                                    data-fecha-mp="{{ $nota->mpSeguimiento?->oc_fecha_envio?->timezone(config('app.timezone'))->format('Y-m-d\TH:i') ?? '' }}">
                                                Aceptar
                                            </button>
                                        @else
                                            <form method="post" action="{{ route('admin.cotizaciones.no-aceptar', $nota->nronota) }}" class="d-inline"
                                                  data-confirm="¿Quitar estado aceptada de la cotización #{{ $nota->nronota }}?">
                                                @csrf
                                                @include('admin.cotizaciones._filtros_ocultos', ['filtros' => $filtros, 'page' => $cotizaciones->currentPage()])
                                                <button type="submit" class="btn btn-outline-warning btn-sm">No aceptar</button>
                                            </form>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $colspanListado }}" class="text-center text-muted py-4">Sin cotizaciones.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer border-top-0 pt-0">
            <x-listado-paginacion :paginator="$cotizaciones" entity-label="cotizaciones" />
        </div>

        @if($puedeGestionar)
            <div class="card-footer d-flex flex-wrap gap-2 justify-content-end">
                <a href="{{ route('admin.cotizaciones.export.sin-codigo-softland') }}" class="btn btn-secondary btn-sm" data-no-loader
                   title="Solo productos de cotizaciones aceptadas sin código Softland en el maestro">
                    Descargar sin c&oacute;digo Softland
                </a>
                <a href="{{ route('admin.cotizaciones.export.detalle-sin-codigo-softland') }}" class="btn btn-secondary btn-sm" data-no-loader
                   title="Detalle CSV para comparar productos aceptados sin código Softland">
                    Detalle sin c&oacute;digo Softland
                </a>
                @php
                    $exportFechaQuery = array_filter([
                        'fechadesde' => $filtros['fechadesde'] ?? null,
                        'fechahasta' => $filtros['fechahasta'] ?? null,
                    ]);
                @endphp
                <a href="{{ route('admin.cotizaciones.export.aceptadas', $exportFechaQuery) }}" class="btn btn-secondary btn-sm">
                    Descargar aceptadas
                </a>
                <a href="{{ route('admin.cotizaciones.export.aceptadas-totales-producto', $exportFechaQuery) }}" class="btn btn-secondary btn-sm" data-no-loader
                   title="Totales acumulados por producto de cotizaciones aceptadas (según fechas del listado)">
                    Descargar aceptadas totales por producto
                </a>
                <a href="{{ route('admin.cotizaciones.export.aceptadas-detalle-producto', $exportFechaQuery) }}" class="btn btn-secondary btn-sm" data-no-loader
                   title="Detalle por línea de producto de cotizaciones aceptadas (según fechas del listado)">
                    Descargar aceptadas detalle por producto
                </a>
            </div>
        @endif
    </div>

    @if($puedeGestionar)
        <div class="modal fade" id="modalAceptarCotizacion" tabindex="-1" aria-labelledby="modalAceptarCotizacionLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" action="#" id="formAceptarCotizacion">
                        @csrf
                        @include('admin.cotizaciones._filtros_ocultos', ['filtros' => $filtros, 'page' => $cotizaciones->currentPage()])
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalAceptarCotizacionLabel">Aceptar cotizaci&oacute;n</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-3" id="modalAceptarCotizacionTexto"></p>
                            <div class="mb-3" id="wrapAceptarOcompraManual">
                                <label class="form-label" for="ocompra">Orden de compra</label>
                                <input type="text" name="ocompra" id="ocompra" class="form-control form-control-sm" maxlength="20" autocomplete="off">
                            </div>
                            <div class="mb-3" id="wrapAceptarOcompraMp" hidden>
                                <span class="form-label d-block">Orden de compra (Mercado P&uacute;blico)</span>
                                <p class="form-control-plaintext small mb-0 py-1" id="ocompraMpDisplay"></p>
                                <p class="form-text mb-0">Obtenida desde la API; no se puede modificar.</p>
                            </div>
                            <div class="mb-2" id="wrapAceptarFechaManual">
                                <label class="form-label" for="fecha_envio_oc">Fecha env&iacute;o OC</label>
                                <input type="datetime-local" name="fecha_envio_oc" id="fecha_envio_oc" class="form-control form-control-sm" required>
                            </div>
                            <div class="mb-2" id="wrapAceptarFechaMp" hidden>
                                <span class="form-label d-block">Fecha env&iacute;o OC (Mercado P&uacute;blico)</span>
                                <p class="form-control-plaintext small mb-0 py-1" id="fechaMpDisplay"></p>
                                <p class="form-text mb-0">Obtenida desde la API; no se puede modificar.</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success btn-sm">Aceptar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('head')
<style>
@keyframes alerta-segundo-llamado-destello {
    0%, 100% { background-color: #fff3cd; box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.45); }
    50% { background-color: #ffe08a; box-shadow: 0 0 0 4px rgba(255, 193, 7, 0.25); }
}
.alerta-segundo-llamado {
    animation: alerta-segundo-llamado-destello 1.2s ease-in-out infinite;
}
.fila-segundo-llamado td {
    font-weight: 500;
}
@keyframes ganador-propio-destello {
    0%, 100% { background-color: #198754; box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.55); }
    50% { background-color: #146c43; box-shadow: 0 0 0 5px rgba(25, 135, 84, 0.35); }
}
.badge-ganador-propio-destello {
    animation: ganador-propio-destello 1.2s ease-in-out infinite;
}
.fila-ganador-propio td {
    font-weight: 500;
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('js-duplicar-cotizacion')) {
        return;
    }
    if (form.dataset.duplicarConfirmado === '1') {
        return;
    }

    e.preventDefault();

    const input = form.querySelector('[name="copiar_detalle"]');
    const msg = '¿Copiar también el detalle (productos)? El encabezado se copia siempre.';
    const opts = { okText: 'Sí, copiar detalle', cancelText: 'No, solo encabezado' };

    const ask = (window.AdminDialog && typeof window.AdminDialog.confirm === 'function')
        ? window.AdminDialog.confirm(msg, opts)
        : Promise.resolve(window.confirm(msg));

    ask.then(function (ok) {
        if (input) {
            input.value = ok ? '1' : '0';
        }
        form.dataset.duplicarConfirmado = '1';
        form.submit();
    });
});

(function () {
    const modalEl = document.getElementById('modalAceptarCotizacion');
    const form = document.getElementById('formAceptarCotizacion');
    if (!modalEl || !form) {
        return;
    }
    const inputOcompra = document.getElementById('ocompra');
    const inputFecha = document.getElementById('fecha_envio_oc');
    const texto = document.getElementById('modalAceptarCotizacionTexto');
    const wrapOcompraManual = document.getElementById('wrapAceptarOcompraManual');
    const wrapOcompraMp = document.getElementById('wrapAceptarOcompraMp');
    const ocompraMpDisplay = document.getElementById('acompraMpDisplay');
    const wrapFechaManual = document.getElementById('wrapAceptarFechaManual');
    const wrapFechaMp = document.getElementById('wrapAceptarFechaMp');
    const fechaMpDisplay = document.getElementById('fechaMpDisplay');
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    function formatearFechaMpLocal(isoLocal) {
        if (!isoLocal) {
            return '';
        }
        const partes = isoLocal.split('T');
        if (partes.length !== 2) {
            return isoLocal;
        }
        const d = partes[0].split('-');
        const h = partes[1].slice(0, 5);
        if (d.length !== 3) {
            return isoLocal;
        }
        return d[2] + '/' + d[1] + '/' + d[0] + ' ' + h;
    }

    document.querySelectorAll('.js-abrir-aceptar-cotizacion').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const nronota = btn.getAttribute('data-nronota') || '';
            const action = btn.getAttribute('data-action') || '';
            const ocompraManual = btn.getAttribute('data-ocompra-manual') || '';
            const ocompraMp = btn.getAttribute('data-ocompra-mp') || '';
            const fechaMp = btn.getAttribute('data-fecha-mp') || '';
            const bloquearOcompraMp = ocompraManual === '' && ocompraMp !== '';
            const bloquearFechaMp = fechaMp !== '';
            form.action = action;
            if (texto) {
                texto.textContent = 'Cotizaci\u00f3n #' + nronota + '. Revise la orden de compra y la fecha de env\u00edo antes de aceptar.';
            }
            if (wrapOcompraManual) {
                wrapOcompraManual.hidden = bloquearOcompraMp;
            }
            if (wrapOcompraMp) {
                wrapOcompraMp.hidden = !bloquearOcompraMp;
            }
            if (ocompraMpDisplay) {
                ocompraMpDisplay.textContent = bloquearOcompraMp ? ocompraMp : '';
            }
            if (inputOcompra) {
                inputOcompra.value = bloquearOcompraMp ? '' : ocompraManual;
                inputOcompra.required = !bloquearOcompraMp;
            }
            if (wrapFechaManual) {
                wrapFechaManual.hidden = bloquearFechaMp;
            }
            if (wrapFechaMp) {
                wrapFechaMp.hidden = !bloquearFechaMp;
            }
            if (fechaMpDisplay) {
                fechaMpDisplay.textContent = bloquearFechaMp ? formatearFechaMpLocal(fechaMp) : '';
            }
            if (inputFecha) {
                inputFecha.value = bloquearFechaMp ? '' : '';
                inputFecha.required = !bloquearFechaMp;
            }
            bsModal.show();
        });
    });
})();
</script>
@endpush
