@extends('layouts.admin')

@section('title', 'Resultados Compra Ágil')

@section('content')
@php
    $corridaActiva = !empty($estadoCorrida['en_curso']);
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Resultados Compra Ágil</h1>
        @if($apiConfigurada)
            <button type="button" class="btn btn-primary btn-sm" id="btn-consultar-mp"
                @disabled($pendientesCount === 0 || $corridaActiva)>
                <i class="bi bi-arrow-repeat"></i> Consultar ahora
            </button>
        @else
            <span class="badge text-bg-warning">Configure MERCADOPUBLICO_TICKET</span>
        @endif
    </div>

    @if($corridaActiva)
        <div class="alert alert-info border small mb-3 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                Consulta en curso iniciada por <strong>{{ $estadoCorrida['usuario'] ?? '—' }}</strong>.
                Puede salir de esta pantalla; el proceso continúa en segundo plano.
            </span>
            <button type="button" class="btn btn-outline-danger btn-sm" id="btn-cancelar-mp">
                <i class="bi bi-x-circle"></i> Cancelar consulta
            </button>
        </div>
    @endif

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
        </div>
    </div>

    @if($ultimaCorrida)
        <div class="card shadow-sm mb-4">
            <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0">Resultado por cotización — última consulta</h2>
                @php
                    $detalleOk = $detalleCorrida->where('exito', true)->count();
                    $detalleError = $detalleCorrida->where('exito', false)->count();
                @endphp
                @if($detalleCorrida->isNotEmpty())
                    <span class="small text-muted">
                        {{ $detalleOk }} ok · {{ $detalleError }} con error
                    </span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nota</th>
                            <th>Código CA</th>
                            <th>Cliente</th>
                            <th>Resultado</th>
                            <th>Estado MP</th>
                            <th>Ganador</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($detalleCorrida as $det)
                            <tr data-nronota="{{ $det->nronota }}">
                                <td>{{ $det->nronota }}</td>
                                <td class="font-monospace small">{{ $det->codigo_proceso }}</td>
                                <td class="small">{{ $det->empresa ?: '—' }}</td>
                                <td>
                                    @if($det->exito)
                                        @include('admin.compra-agil.partials.resultado-badge', ['resultado' => $det->resultado_propio])
                                        @if($det->cambio)
                                            <span class="badge text-bg-info ms-1">Cambio</span>
                                        @endif
                                    @else
                                        <span class="badge text-bg-danger">Error</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($det->exito)
                                        {{ $det->estado_mp_glosa ?: '—' }}
                                    @else
                                        <span class="text-danger">{{ $det->mensaje }}</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($det->razon_social_ganador)
                                        {{ $det->razon_social_ganador }}<br>
                                        <span class="text-muted">{{ $det->rut_ganador }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if($det->exito)
                                        <button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="{{ $det->nronota }}">Detalle</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    Sin detalle registrado en la última consulta.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card shadow-sm mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Novedades — última consulta</h2>
            <span class="badge text-bg-primary" id="badge-novedades">{{ $novedades->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nota</th>
                        <th>Código CA</th>
                        <th>Cambio estado</th>
                        <th>Seguimiento</th>
                        <th>Ganador</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tbody-novedades">
                    @forelse($novedades as $nov)
                        <tr data-nronota="{{ $nov->nronota }}">
                            <td>{{ $nov->nronota }}</td>
                            <td class="font-monospace small">{{ $nov->codigo_proceso }}</td>
                            <td class="small">
                                {{ $nov->estado_anterior ?: '—' }}
                                <i class="bi bi-arrow-right"></i>
                                <strong>{{ $nov->estado_nuevo ?: '—' }}</strong>
                            </td>
                            <td>@include('admin.compra-agil.partials.resultado-badge', ['resultado' => $nov->resultado_propio])</td>
                            <td class="small">
                                @if($nov->razon_social_ganador)
                                    {{ $nov->razon_social_ganador }}<br>
                                    <span class="text-muted">{{ $nov->rut_ganador }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="{{ $nov->nronota }}">Detalle</button>
                            </td>
                        </tr>
                    @empty
                        <tr id="novedades-vacio"><td colspan="6" class="text-center text-muted py-4">Sin cambios en la última consulta.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Cerradas</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nota</th>
                        <th>Código CA</th>
                        <th>Organismo</th>
                        <th>Estado MP</th>
                        <th>Seguimiento</th>
                        <th>Ganador</th>
                        <th class="text-end">Monto</th>
                        <th>Consultado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tbody-cerradas">
                    @forelse($cerradas as $seg)
                        <tr data-nronota="{{ $seg->nronota }}">
                            <td>{{ $seg->nronota }}</td>
                            <td class="font-monospace small">{{ $seg->codigo_proceso }}</td>
                            <td class="small">{{ Str::limit($seg->organismo, 40) }}</td>
                            <td class="small">{{ $seg->estado_mp_glosa ?: $seg->estado_mp_codigo }}</td>
                            <td>@include('admin.compra-agil.partials.resultado-badge', ['resultado' => $seg->resultado_propio])</td>
                            <td class="small">
                                @if($seg->razon_social_ganador)
                                    {{ Str::limit($seg->razon_social_ganador, 30) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end small">
                                @if($seg->monto_total_ganador)
                                    ${{ number_format($seg->monto_total_ganador, 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small text-muted">{{ $seg->ultimo_consultado_en?->format('d/m/Y H:i') }}</td>
                            <td>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="{{ $seg->nronota }}">Detalle</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">Sin procesos cerrados registrados aún.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-detalle-mp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6" id="modal-detalle-titulo">Detalle Mercado Público</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2" id="modal-detalle-body">
                <p class="text-muted small mb-0">Cargando…</p>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urls = {
        iniciar: @json(route('admin.compra-agil.resultados.iniciar')),
        estado: @json(route('admin.compra-agil.resultados.estado')),
        cancelar: @json(route('admin.compra-agil.resultados.cancelar')),
        detalle: @json(url('/admin/compra-agil/resultados/detalle/__NRO__')),
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

    const seguimientoBadge = (r) => {
        const map = {
            cerrada: ['success', 'Cerrada'],
            pendiente: ['warning', 'Pendiente seguimiento'],
            desierta: ['secondary', 'Desierta'],
            cancelada: ['secondary', 'Cancelada'],
        };
        const [cls, lbl] = map[r] || ['secondary', r || '—'];
        return `<span class="badge text-bg-${cls}">${lbl}</span>`;
    };

    const fmtMonto = (n) => '$' + (Number(n) || 0).toLocaleString('es-CL');

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

    function actualizarProgreso(estado) {
        const pct = estado.porcentaje ?? 0;
        const codigo = estado.codigo_actual || '';
        progresoBar.style.width = pct + '%';
        progresoBar.textContent = pct + '%';
        progresoTexto.textContent = `Consultando ${estado.procesadas ?? 0} / ${estado.total ?? 0}`
            + (codigo ? ` — ${codigo}` : '');
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
    }

    if (corridaActiva && estadoInicial.alerta) {
        actualizarProgreso(estadoInicial);
    }

    function setCorridaActiva(activa) {
        corridaActiva = activa;
        if (btnConsultar) {
            btnConsultar.disabled = activa || btnConsultar.dataset.sinPendientes === '1';
        }
        if (btnCancelar) {
            btnCancelar.disabled = !activa;
        }
    }

    function detenerPolling() {
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

    document.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('.btn-detalle-mp');
        if (!btn) return;
        const nronota = btn.dataset.nronota;
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-detalle-mp'));
        const body = document.getElementById('modal-detalle-body');
        document.getElementById('modal-detalle-titulo').textContent = 'Nota ' + nronota;
        body.innerHTML = '<p class="text-muted small">Cargando…</p>';
        modal.show();
        try {
            const res = await fetch(urls.detalle.replace('__NRO__', nronota), { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Error');
            const s = data.seguimiento;
            let html = `<p class="small mb-2"><strong>${s.codigo_proceso}</strong> · ${s.estado_mp_glosa || s.estado_mp_codigo}<br>
                Ganador: ${s.razon_social_ganador || '—'} ${s.rut_ganador ? '(' + s.rut_ganador + ')' : ''}<br>
                Seguimiento: ${({ cerrada: 'Cerrada', pendiente: 'Pendiente seguimiento', desierta: 'Desierta', cancelada: 'Cancelada' }[s.resultado_propio]) || s.resultado_propio || '—'} · Monto: ${fmtMonto(s.monto_total_ganador)}</p>`;
            html += '<h3 class="h6">Ofertas recibidas</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Proveedor</th><th>RUT</th><th class="text-end">Monto</th><th></th></tr></thead><tbody>';
            (data.ofertas || []).forEach(o => {
                html += `<tr class="${o.proveedor_seleccionado ? 'table-success' : ''}${o.es_propio ? ' fw-semibold' : ''}">
                    <td>${o.razon_social || '—'}</td>
                    <td class="small">${o.rut_proveedor || '—'}</td>
                    <td class="text-end">${fmtMonto(o.monto_total)}</td>
                    <td class="small">${o.proveedor_seleccionado ? 'Ganador' : ''}${o.es_propio ? ' · Propio' : ''}${o.inadmisible ? ' · Inadm.' : ''}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            const ganador = (data.ofertas || []).find(o => o.proveedor_seleccionado);
            if (ganador && ganador.lineas && ganador.lineas.length) {
                html += '<h3 class="h6 mt-3">Productos adjudicados</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Producto</th><th>Cant.</th><th class="text-end">P.unit.</th><th class="text-end">Total</th></tr></thead><tbody>';
                ganador.lineas.forEach(l => {
                    html += `<tr><td class="small">${l.descripcion || '—'}</td><td>${l.cantidad ?? '—'}</td><td class="text-end">${fmtMonto(l.precio_unitario)}</td><td class="text-end">${fmtMonto(l.monto_total)}</td></tr>`;
                });
                html += '</tbody></table></div>';
            }
            body.innerHTML = html;
        } catch (e) {
            body.innerHTML = `<p class="text-danger small">${e.message}</p>`;
        }
    });
})();
</script>
@endpush
