@extends('layouts.admin')

@section('title', 'Resultados Compra Ágil')

@section('content')
@php
    $corridaActiva = !empty($estadoCorrida['en_curso']);
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-0">Resultados Compra Ágil</h1>
            @if($apiConfigurada && $pendientesCount > 0)
                <p class="small text-muted mb-0 mt-1">
                    {{ $pendientesCount }} cotización(es) pendiente(s) de consultar a MP.
                </p>
            @endif
        </div>
        @if($apiConfigurada)
            <button type="button" class="btn btn-primary btn-sm" id="btn-consultar-mp"
                @disabled($pendientesCount === 0 || $corridaActiva)>
                <i class="bi bi-arrow-repeat"></i> Consultar ahora
            </button>
        @else
            <span class="badge text-bg-warning">Configure MERCADOPUBLICO_TICKET</span>
        @endif
    </div>

    <div class="alert alert-info border small mb-3 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2 {{ $corridaActiva ? '' : 'd-none' }}" id="banner-corrida-activa">
        <span>
            Consulta en curso iniciada por <strong id="banner-corrida-usuario">{{ $estadoCorrida['usuario'] ?? '—' }}</strong>.
            Puede salir de esta pantalla; el proceso continúa en segundo plano.
        </span>
        <button type="button" class="btn btn-outline-danger btn-sm" id="btn-cancelar-mp">
            <i class="bi bi-x-circle"></i> Cancelar consulta
        </button>
    </div>

    @if($ultimaCorrida)
        <div class="alert alert-light border small mb-3 py-2" id="banner-ultima-corrida">
            <strong>Última consulta</strong><br>
            Usuario: <span id="ultima-usuario">{{ $ultimaCorrida->usuario }}</span>
            · Inicio: {{ $ultimaCorrida->inicio->format('d/m/Y H:i:s') }}
            · Fin: {{ $ultimaCorrida->fin?->format('d/m/Y H:i:s') ?? '—' }}
            · Procesadas: {{ $ultimaCorrida->notas_procesadas }}
            · Con cambio: {{ $ultimaCorrida->notas_con_cambio }}
            @if($ultimaCorrida->estado === 'error')
                · <span class="text-danger">{{ $ultimaCorrida->mensaje }}</span>
            @elseif($ultimaCorrida->estado === 'cancelled')
                · <span class="text-muted">{{ $ultimaCorrida->mensaje }}</span>
            @elseif(filled($ultimaCorrida->mensaje))
                · <span class="text-warning">{{ $ultimaCorrida->mensaje }}</span>
            @endif
        </div>
    @else
        <p class="small text-muted mb-3" id="banner-sin-corrida">Aún no se ha ejecutado ninguna consulta. Use «Consultar ahora».</p>
    @endif

    <div class="d-flex gap-2 mb-3">
        @if($ultimaCorrida)
            <a href="{{ route('admin.compra-agil.resultados.resultado') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-check"></i> Resultado último proceso
                @if($detalleCorrida->isNotEmpty())
                    <span class="badge text-bg-secondary ms-1">{{ $detalleCorrida->count() }}</span>
                @endif
            </a>
        @endif
        <a href="{{ route('admin.compra-agil.resultados.cerradas') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-lock-fill"></i> Cerradas
            <span class="badge text-bg-secondary ms-1">{{ $cerradasCount }}</span>
        </a>
        <a href="{{ route('admin.compra-agil.resultados.pendientes') }}" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-hourglass-split"></i> Pendientes seguimiento
            <span class="badge text-bg-warning ms-1">{{ $pendientesSeguimientoCount }}</span>
        </a>
        <a href="{{ route('admin.compra-agil.resultados.todas') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list-ul"></i> Todas las notas
            <span class="badge text-bg-primary ms-1">{{ $todasCount }}</span>
        </a>
        <a href="{{ route('admin.compra-agil.resultados.analisis-precios') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-bar-chart-line"></i> Análisis de precios
        </a>
    </div>

    <div class="card shadow-sm mb-4 {{ $corridaActiva ? '' : 'd-none' }}" id="card-progreso">
        <div class="card-body py-3">
            <div class="alert alert-warning small py-2 d-none mb-2" id="progreso-alerta"></div>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <span class="small fw-semibold" id="progreso-texto">Preparando…</span>
                <span class="small text-muted" id="progreso-usuario"></span>
            </div>
            <div class="progress" style="height: 1.25rem;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="progreso-bar" role="progressbar" style="width: 0%">0%</div>
            </div>
            <div class="small mt-2 d-none" id="progreso-recientes"></div>
            <div class="small text-muted mt-2 d-none text-break" id="progreso-ultimo-detalle"></div>
            <div class="small text-muted mt-1 d-none" id="progreso-tiempo">
                <i class="bi bi-clock"></i> Total: <span id="progreso-tiempo-total"></span>
                · Última nota: <span id="progreso-tiempo-nota"></span>
                · <span class="text-success">OK: <span id="progreso-ok">0</span></span>
                · <span class="text-danger">Fallos: <span id="progreso-fallos">0</span></span>
            </div>
            <div class="small text-muted mt-1 d-none" id="progreso-config-mp"></div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="h6 mb-0 d-inline">Novedades recientes</h2>
                <p class="text-muted small mb-0 mt-1">
                    Hasta 40 cotizaciones (una fila por nota con su último cambio en MP), ordenadas por fecha de último cambio.
                    @if($ultimaCorrida)
                        Las marcadas como <span class="badge text-bg-info">Nueva</span> cambiaron en la última consulta
                        ({{ $ultimaCorrida->fin?->format('d/m/Y H:i') ?? $ultimaCorrida->inicio->format('d/m/Y H:i') }}).
                    @endif
                    Filas en verde: ganador propio ({{ config('cotiz.sistema') }}).
                    Use «Consultar MP» en filas pendientes para actualizar sin recargar la página.
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                @php $novedadesNuevas = $novedades->where('cambio_ultima_consulta', true)->count(); @endphp
                @if($novedadesNuevas > 0)
                    <span class="badge text-bg-info" id="badge-novedades-nuevas">{{ $novedadesNuevas }} nueva{{ $novedadesNuevas === 1 ? '' : 's' }}</span>
                @endif
                <span class="badge text-bg-primary" id="badge-novedades">{{ $novedades->count() }} total</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nota</th>
                        <th>Código CA</th>
                        <th>Publicación</th>
                        <th>Últ. cambio</th>
                        <th>Cambio estado</th>
                        <th>Seguimiento</th>
                        <th>Prov. seleccionado</th>
                        <th class="text-end">Monto</th>
                        <th>OC</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tbody-novedades">
                    @forelse($novedades as $nov)
                        <tr data-nronota="{{ $nov->nronota }}"
                            class="novedad-data-row {{ !empty($nov->es_ganador_propio) ? 'table-success' : '' }}{{ !empty($nov->cambio_ultima_consulta) ? ' border-start border-3 border-info' : '' }}">
                            <td>
                                {{ $nov->nronota }}
                                @if(!empty($nov->cambio_ultima_consulta))
                                    <span class="badge text-bg-info ms-1">Nueva</span>
                                @endif
                            </td>
                            <td class="font-monospace small">{{ $nov->codigo_proceso }}</td>
                            <td class="small text-muted">{{ $nov->seguimiento?->fecha_publicacion?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small text-muted">{{ $nov->seguimiento?->fecha_ultimo_cambio?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small cell-cambio-estado">
                                {{ $nov->estado_anterior ?: '—' }}
                                <i class="bi bi-arrow-right"></i>
                                <strong>{{ $nov->estado_nuevo ?: '—' }}</strong>
                            </td>
                            <td class="cell-seguimiento">@include('admin.compra-agil.partials.resultado-badge', ['resultado' => $nov->resultado_propio])</td>
                            <td class="small cell-proveedor">
                                @if($nov->razon_social_ganador)
                                    {{ $nov->razon_social_ganador }}<br>
                                    <span class="text-muted">{{ $nov->rut_ganador }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end small cell-monto">
                                @if($nov->seguimiento?->monto_total_ganador)
                                    ${{ number_format($nov->seguimiento->monto_total_ganador, 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small cell-oc">{{ $nov->seguimiento?->id_orden_compra ?: '—' }}</td>
                            <td class="text-nowrap cell-acciones">
                                @if(($nov->resultado_propio ?? '') === 'pendiente' && $apiConfigurada)
                                    <button type="button"
                                        class="btn btn-outline-info btn-sm btn-consultar-mp-individual"
                                        data-nronota="{{ $nov->nronota }}"
                                        title="Consultar estado en Mercado Público">
                                        <i class="bi bi-cloud-download"></i> Consultar MP
                                    </button>
                                @endif
                                <button type="button" class="btn btn-outline-primary btn-sm btn-comparar-mp" data-nronota="{{ $nov->nronota }}" title="Comparar precios Prov. seleccionado vs {{ config('cotiz.sistema') }}"><i class="bi bi-arrow-left-right"></i> Comparar</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="{{ $nov->nronota }}">Detalle</button>
                            </td>
                        </tr>
                        @if(($nov->resultado_propio ?? '') === 'pendiente' && $apiConfigurada)
                        <tr class="consulta-mp-feedback d-none" data-nronota="{{ $nov->nronota }}">
                            <td colspan="10" class="py-2 bg-light">
                                <div class="progress" style="height: 0.5rem;">
                                    <div class="progress-bar consulta-mp-progress-bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="consulta-mp-mensaje small mt-1 text-muted"></div>
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr id="novedades-vacio"><td colspan="10" class="text-center text-muted py-4">Sin novedades registradas con fecha de último cambio en Mercado Público.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@include('admin.compra-agil.partials.modal-detalle-mp')
@endsection

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urls = {
        iniciar: @json(route('admin.compra-agil.resultados.iniciar')),
        estado: @json(route('admin.compra-agil.resultados.estado')),
        cancelar: @json(route('admin.compra-agil.resultados.cancelar')),
    };
    const estadoInicial = @json($estadoCorrida);
    const btnConsultar = document.getElementById('btn-consultar-mp');
    const btnCancelar = document.getElementById('btn-cancelar-mp');
    const cardProgreso = document.getElementById('card-progreso');
    const progresoBar = document.getElementById('progreso-bar');
    const progresoTexto = document.getElementById('progreso-texto');
    const progresoUsuario = document.getElementById('progreso-usuario');
    const progresoAlerta = document.getElementById('progreso-alerta');
    let pollTimer = null;
    let corridaActiva = !!estadoInicial.en_curso;
    let monitoreando = corridaActiva;

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body || {}),
        });
        const data = await res.json().catch(() => ({}));
        return { res, data };
    }

    const progresoUltimoDetalle = document.getElementById('progreso-ultimo-detalle');
    const progresoRecientes = document.getElementById('progreso-recientes');
    const progresoTiempo = document.getElementById('progreso-tiempo');
    const progresoTiempoTotal = document.getElementById('progreso-tiempo-total');
    const progresoTiempoNota = document.getElementById('progreso-tiempo-nota');
    const progresoConfigMp = document.getElementById('progreso-config-mp');
    const umbralAlertaNotaSeg = @json((int) config('cotiz.mercadopublico.resultados_nota_alerta_segundos', 180));
    const umbralMaxNotaSeg = @json((int) config('cotiz.mercadopublico.resultados_nota_max_segundos', 180));
    let segTotalServidor = 0;
    let syncTotalMs = 0;
    let syncNotaMs = null;
    let ultimaNotaId = null;
    let ultimoCodigoActual = '';
    let relojTimer = null;

    function fmtTiempo(seg) {
        seg = Math.max(0, seg);
        const h = Math.floor(seg / 3600);
        const m = Math.floor((seg % 3600) / 60);
        const s = seg % 60;
        return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    }

    function iniciarReloj() {
        if (relojTimer) return;
        progresoTiempo?.classList.remove('d-none');
        relojTimer = setInterval(() => {
            const now = Date.now();
            const totalSeg = segTotalServidor + Math.floor((now - syncTotalMs) / 1000);
            const notaSeg = syncNotaMs !== null ? Math.floor((now - syncNotaMs) / 1000) : null;
            if (progresoTiempoTotal) progresoTiempoTotal.textContent = fmtTiempo(totalSeg);
            if (progresoTiempoNota) {
                progresoTiempoNota.textContent = notaSeg !== null ? fmtTiempo(notaSeg) : '—';
            }
            if (progresoAlerta && ultimoCodigoActual && notaSeg !== null && notaSeg >= umbralAlertaNotaSeg) {
                progresoAlerta.textContent = 'Consultando ' + ultimoCodigoActual + ' lleva '
                    + fmtTiempo(notaSeg) + '. Al superar ' + umbralMaxNotaSeg
                    + ' s se registrará como fallo y continuará con la siguiente.';
                progresoAlerta.classList.remove('d-none');
            }
        }, 1000);
    }

    function detenerReloj() {
        if (relojTimer) { clearInterval(relojTimer); relojTimer = null; }
        progresoTiempo?.classList.add('d-none');
    }

    function actualizarProgreso(estado) {
        const pct = estado.porcentaje ?? 0;
        const enCurso = Array.isArray(estado.notas_en_curso) ? estado.notas_en_curso : [];
        const concurrencia = estado.concurrencia
            ?? estado.config_mp?.concurrencia
            ?? (enCurso.length || 1);
        const codigosEnCurso = enCurso
            .map((x) => (x && x.codigo) ? x.codigo : '')
            .filter(Boolean);
        const codigo = estado.codigo_actual || (codigosEnCurso[0] || '');
        progresoBar.style.width = pct + '%';
        progresoBar.textContent = pct + '%';
        progresoTexto.textContent = `Consultando ${estado.procesadas ?? 0} / ${estado.total ?? 0}`
            + (concurrencia > 1 ? ` · ${Math.min(concurrencia, Math.max(enCurso.length, 1))} en paralelo` : '')
            + (codigo && concurrencia <= 1 ? ` — ${codigo}` : '');
        if (estado.usuario) {
            progresoUsuario.textContent = 'Ejecutado por: ' + estado.usuario;
        }
        if (progresoAlerta) {
            if (estado.alerta) {
                progresoAlerta.textContent = estado.alerta;
                progresoAlerta.classList.remove('d-none');
            } else {
                progresoAlerta.textContent = '';
                progresoAlerta.classList.add('d-none');
            }
        }
        if (progresoRecientes) {
            const recientes = Array.isArray(estado.recientes) ? estado.recientes : [];
            if (recientes.length > 0) {
                const filas = recientes.slice(0, 5).map((r) => {
                    const ms = Number(r.ms || 0);
                    const tiempo = ms >= 1000
                        ? ((ms / 1000).toFixed(1).replace('.', ',') + ' s')
                        : (ms + ' ms');
                    const ok = !!r.exito;
                    const icon = ok
                        ? '<span class="text-success">✓</span>'
                        : '<span class="text-danger">✗</span>';
                    const marca = ok
                        ? '<span class="text-success">OK</span>'
                        : '<span class="text-danger">Fallo</span>';
                    const msg = (!ok && r.mensaje)
                        ? ` <span class="text-muted">(${String(r.mensaje).replace(/[<>&]/g, '')})</span>`
                        : '';
                    return `<div class="d-flex flex-wrap align-items-baseline gap-2 py-0">${icon}`
                        + ` <span class="font-monospace">${r.codigo || '—'}</span>`
                        + ` ${marca}`
                        + ` <span class="text-muted tabular-nums">${tiempo}</span>`
                        + msg
                        + `</div>`;
                });
                progresoRecientes.innerHTML = '<div class="fw-semibold text-muted mb-1">Últimas</div>' + filas.join('');
                progresoRecientes.classList.remove('d-none');
            } else {
                progresoRecientes.innerHTML = '';
                progresoRecientes.classList.add('d-none');
            }
        }
        if (estado.codigo_actual) {
            ultimoCodigoActual = estado.codigo_actual;
        } else if (codigosEnCurso.length) {
            ultimoCodigoActual = codigosEnCurso.join(', ');
        } else {
            ultimoCodigoActual = '';
        }
        if (estado.segundos_en_curso !== undefined) {
            segTotalServidor = estado.segundos_en_curso;
            syncTotalMs = Date.now();
        }
        if (estado.segundos_en_nota_actual !== undefined && estado.segundos_en_nota_actual !== null) {
            syncNotaMs = Date.now() - (estado.segundos_en_nota_actual * 1000);
            ultimaNotaId = estado.nronota_actual || estado.codigo_actual || ultimaNotaId;
        } else if (estado.codigo_actual && estado.segundos_en_curso !== undefined) {
            const notaActual = estado.nronota_actual || estado.codigo_actual || null;
            if (notaActual !== ultimaNotaId) {
                ultimaNotaId = notaActual;
                syncNotaMs = Date.now();
            }
        } else if (!estado.codigo_actual && codigosEnCurso.length === 0) {
            syncNotaMs = null;
            ultimaNotaId = null;
        }
        if (estado.segundos_en_curso !== undefined) {
            iniciarReloj();
        }
        const elOk = document.getElementById('progreso-ok');
        const elFallos = document.getElementById('progreso-fallos');
        if (elOk) elOk.textContent = estado.detalle_ok ?? 0;
        if (elFallos) elFallos.textContent = estado.detalle_fallos ?? 0;
        if (progresoUltimoDetalle && estado.ultimo_detalle) {
            const d = estado.ultimo_detalle;
            let txt = `Última procesada: ${d.codigo} (nota ${d.nronota})`;
            if (d.exito) {
                txt += ` — ${d.estado_mp || '—'}`;
                if (d.resultado) txt += ` · ${d.resultado}`;
            } else {
                txt += ` — <span class="text-danger">Error: ${d.mensaje || 'desconocido'}</span>`;
            }
            progresoUltimoDetalle.innerHTML = txt;
            progresoUltimoDetalle.classList.remove('d-none');
        }
        if (progresoConfigMp && estado.config_mp) {
            const c = estado.config_mp;
            progresoConfigMp.textContent = 'MP en servidor: timeout '
                + (c.timeout_seg ?? '?') + ' s, connect '
                + (c.connect_timeout_seg ?? '?') + ' s, low-speed '
                + ((c.low_speed_limit_bytes ?? 0) > 0
                    ? ((c.low_speed_seg ?? '?') + ' s / ' + c.low_speed_limit_bytes + ' B/s')
                    : 'off')
                + ', '
                + (c.reintentos ?? '?') + ' reintento(s), disparo cada '
                + (c.stagger_ms ?? 2000) + ' ms, máx en vuelo '
                + (c.concurrencia ?? concurrencia);
            progresoConfigMp.classList.remove('d-none');
        }
    }

    if (corridaActiva) {
        actualizarProgreso(estadoInicial);
    }

    const bannerCorridaActiva = document.getElementById('banner-corrida-activa');

    function setCorridaActiva(activa) {
        corridaActiva = activa;
        if (btnConsultar) {
            btnConsultar.disabled = activa || btnConsultar.dataset.sinPendientes === '1';
        }
        if (btnCancelar) {
            btnCancelar.disabled = !activa;
        }
        if (bannerCorridaActiva) {
            bannerCorridaActiva.classList.toggle('d-none', !activa);
        }
    }

    function detenerPolling() {
        detenerReloj();
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    async function pollEstado() {
        try {
            const res = await fetch(urls.estado, { headers: { 'Accept': 'application/json' } });
            const estado = await res.json();
            if (estado.en_curso) {
                cardProgreso.classList.remove('d-none');
                progresoBar.classList.add('progress-bar-animated');
                actualizarProgreso(estado);
                setCorridaActiva(true);
                return;
            }
            detenerPolling();
            setCorridaActiva(false);
            if (monitoreando) {
                monitoreando = false;
                progresoTexto.textContent = 'Consulta finalizada. Actualizando…';
                progresoBar.classList.remove('progress-bar-animated');
                window.location.reload();
            }
        } catch (e) {
            console.warn('poll estado:', e);
        }
    }

    function iniciarPolling() {
        detenerPolling();
        monitoreando = true;
        cardProgreso.classList.remove('d-none');
        pollEstado();
        pollTimer = setInterval(pollEstado, 2500);
    }

    btnConsultar?.addEventListener('click', async () => {
        btnConsultar.disabled = true;
        cardProgreso.classList.remove('d-none');
        actualizarProgreso({ procesadas: 0, total: 1, porcentaje: 0 });

        const { res, data } = await postJson(urls.iniciar, {});
        if (res.status === 409 && data.estado?.en_curso) {
            iniciarPolling();
            return;
        }
        if (!res.ok) {
            progresoTexto.textContent = data.error || 'Error al iniciar.';
            progresoBar.classList.add('bg-danger');
            btnConsultar.disabled = corridaActiva;
            return;
        }
        if (data.estado) {
            actualizarProgreso(data.estado);
        }
        iniciarPolling();
    });

    btnCancelar?.addEventListener('click', async () => {
        if (!confirm('¿Cancelar la consulta en curso?')) {
            return;
        }
        btnCancelar.disabled = true;
        const { res, data } = await postJson(urls.cancelar, {});
        detenerPolling();
        monitoreando = false;
        if (!res.ok) {
            alert(data.error || 'No se pudo cancelar.');
            btnCancelar.disabled = false;
            return;
        }
        window.location.reload();
    });

    if (btnConsultar && btnConsultar.disabled && !corridaActiva) {
        btnConsultar.dataset.sinPendientes = '1';
    }

    if (corridaActiva) {
        actualizarProgreso(estadoInicial);
        iniciarPolling();
    }

})();
</script>
@endpush
