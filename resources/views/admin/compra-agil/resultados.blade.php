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
            <div class="d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label for="input-limite-corrida" class="form-label small mb-1">Cantidad a consultar</label>
                    <input type="number" class="form-control form-control-sm" id="input-limite-corrida"
                        min="1" max="{{ $limiteCorridaMax }}"
                        value="{{ min(5, $pendientesCount ?: 5) }}"
                        style="width: 6rem;"
                        @disabled($pendientesCount === 0 || $corridaActiva)>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="btn-consultar-mp"
                    @disabled($pendientesCount === 0 || $corridaActiva)>
                    <i class="bi bi-arrow-repeat"></i> Consultar ahora
                </button>
            </div>
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
        </div>
    </div>

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
                        <th>Publicación</th>
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
                            <td class="small text-muted">{{ $nov->seguimiento?->fecha_publicacion?->format('d/m/Y H:i') ?? '—' }}</td>
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
                        <tr id="novedades-vacio"><td colspan="7" class="text-center text-muted py-4">Sin cambios en la última consulta.</td></tr>
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
    const inputLimite = document.getElementById('input-limite-corrida');
    const limiteCorridaMax = @json($limiteCorridaMax);
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

        const limite = parseInt(inputLimite?.value ?? '5', 10);
        const body = {
            limite: Number.isNaN(limite) ? 5 : Math.min(limiteCorridaMax, Math.max(1, limite)),
        };

        const { res, data } = await postJson(urls.iniciar, body);
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
