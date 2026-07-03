@extends('layouts.admin')

@section('title', 'Resultados Compra Ágil')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Resultados Compra Ágil</h1>
        @if($apiConfigurada)
            <button type="button" class="btn btn-primary btn-sm" id="btn-consultar-mp" @disabled($pendientesCount === 0)>
                <i class="bi bi-arrow-repeat"></i> Consultar ahora
            </button>
        @else
            <span class="badge text-bg-warning">Configure MERCADOPUBLICO_TICKET</span>
        @endif
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
            @endif
        </div>
    @else
        <p class="small text-muted mb-3" id="banner-sin-corrida">Aún no se ha ejecutado ninguna consulta. Use «Consultar ahora».</p>
    @endif

    <div class="card shadow-sm mb-4 d-none" id="card-progreso">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <span class="small fw-semibold" id="progreso-texto">Preparando…</span>
                <span class="small text-muted" id="progreso-usuario"></span>
            </div>
            <div class="progress" style="height: 1.25rem;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="progreso-bar" role="progressbar" style="width: 0%">0%</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Cerradas (estadística)</div>
                <div class="fs-4 fw-semibold">{{ number_format($kpi['total'], 0, ',', '.') }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100 border-success"><div class="card-body py-3">
                <div class="text-muted small">Ganadas</div>
                <div class="fs-4 fw-semibold text-success">{{ number_format($kpi['ganadas'], 0, ',', '.') }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100 border-danger"><div class="card-body py-3">
                <div class="text-muted small">Perdidas</div>
                <div class="fs-4 fw-semibold text-danger">{{ number_format($kpi['perdidas'], 0, ',', '.') }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100 border-warning"><div class="card-body py-3">
                <div class="text-muted small">Pendientes seguimiento</div>
                <div class="fs-4 fw-semibold text-warning">{{ number_format($kpi['pendientes'], 0, ',', '.') }}</div>
            </div></div>
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
                        <th>Cambio estado</th>
                        <th>Resultado</th>
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
            <h2 class="h6 mb-0">Cerradas — estadística</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nota</th>
                        <th>Código CA</th>
                        <th>Organismo</th>
                        <th>Estado MP</th>
                        <th>Resultado</th>
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
        consultar: @json(url('/admin/compra-agil/resultados/consultar/__NRO__')),
        finalizar: @json(route('admin.compra-agil.resultados.finalizar')),
        detalle: @json(url('/admin/compra-agil/resultados/detalle/__NRO__')),
    };
    const btnConsultar = document.getElementById('btn-consultar-mp');
    const cardProgreso = document.getElementById('card-progreso');
    const progresoBar = document.getElementById('progreso-bar');
    const progresoTexto = document.getElementById('progreso-texto');
    const progresoUsuario = document.getElementById('progreso-usuario');
    const tbodyNovedades = document.getElementById('tbody-novedades');
    const badgeNovedades = document.getElementById('badge-novedades');

    const resultadoBadge = (r) => {
        const map = {
            ganada: 'success',
            perdida: 'danger',
            pendiente: 'warning',
            desierta: 'secondary',
            cancelada: 'secondary',
            no_participo: 'light text-dark border',
        };
        const labels = {
            ganada: 'Ganada',
            perdida: 'Perdida',
            pendiente: 'Pendiente',
            desierta: 'Desierta',
            cancelada: 'Cancelada',
            no_participo: 'No participó',
        };
        const cls = map[r] || 'secondary';
        const lbl = labels[r] || (r || '—');
        return `<span class="badge text-bg-${cls === 'light text-dark border' ? 'light text-dark border' : cls}">${lbl}</span>`;
    };

    const fmtMonto = (n) => '$' + (Number(n) || 0).toLocaleString('es-CL');

    const sleep = (ms) => new Promise(r => setTimeout(r, ms));

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
        if (!res.ok) throw new Error(data.error || 'Error en la consulta.');
        return data;
    }

    function actualizarProgreso(actual, total, codigo, usuario) {
        const pct = total > 0 ? Math.round((actual / total) * 100) : 0;
        progresoBar.style.width = pct + '%';
        progresoBar.textContent = pct + '%';
        progresoTexto.textContent = `Consultando ${actual} / ${total}` + (codigo ? ` — ${codigo}` : '');
        if (usuario) progresoUsuario.textContent = 'Ejecutado por: ' + usuario;
    }

    function agregarNovedad(r) {
        if (!r.cambio) return;
        document.getElementById('novedades-vacio')?.remove();
        const tr = document.createElement('tr');
        tr.dataset.nronota = r.nronota;
        tr.innerHTML = `
            <td>${r.nronota}</td>
            <td class="font-monospace small">${r.codigo}</td>
            <td class="small">${r.estado_anterior || '—'} <i class="bi bi-arrow-right"></i> <strong>${r.estado_nuevo || '—'}</strong></td>
            <td>${resultadoBadge(r.resultado_propio)}</td>
            <td class="small">${r.razon_social_ganador ? r.razon_social_ganador + '<br><span class="text-muted">' + (r.rut_ganador || '') + '</span>' : '—'}</td>
            <td><button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="${r.nronota}">Detalle</button></td>`;
        tbodyNovedades.prepend(tr);
        badgeNovedades.textContent = tbodyNovedades.querySelectorAll('tr[data-nronota]').length;
    }

    function actualizarBannerCorrida(c) {
        const banner = document.getElementById('banner-ultima-corrida');
        const sin = document.getElementById('banner-sin-corrida');
        if (sin) sin.classList.add('d-none');
        if (!banner) return;
        banner.classList.remove('d-none');
        document.getElementById('ultima-usuario').textContent = c.usuario;
        banner.innerHTML = `<strong>Última consulta</strong><br>
            Usuario: <span id="ultima-usuario">${c.usuario}</span>
            · Inicio: ${c.inicio}
            · Fin: ${c.fin || '—'}
            · Procesadas: ${c.notas_procesadas}
            · Con cambio: ${c.notas_con_cambio}`;
    }

    btnConsultar?.addEventListener('click', async () => {
        btnConsultar.disabled = true;
        cardProgreso.classList.remove('d-none');
        actualizarProgreso(0, 1, '', '');

        try {
            const inicio = await postJson(urls.iniciar, {});
            const pendientes = inicio.pendientes || [];
            const total = pendientes.length;
            const corridaId = inicio.corrida_id;
            progresoUsuario.textContent = 'Ejecutado por: ' + inicio.usuario;

            for (let i = 0; i < pendientes.length; i++) {
                const p = pendientes[i];
                actualizarProgreso(i, total, p.codigo, inicio.usuario);
                try {
                    const res = await postJson(urls.consultar.replace('__NRO__', p.nronota), { corrida_id: corridaId });
                    if (res.resultado?.cambio) agregarNovedad(res.resultado);
                } catch (e) {
                    console.warn('Nota ' + p.nronota + ':', e.message);
                }
                actualizarProgreso(i + 1, total, p.codigo, inicio.usuario);
                await sleep(350);
            }

            const fin = await postJson(urls.finalizar, { corrida_id: corridaId, estado: 'ok' });
            if (fin.corrida) actualizarBannerCorrida(fin.corrida);
            progresoTexto.textContent = 'Consulta finalizada. Recargue para ver estadística actualizada.';
            progresoBar.classList.remove('progress-bar-animated');
        } catch (e) {
            progresoTexto.textContent = e.message;
            progresoBar.classList.add('bg-danger');
        } finally {
            btnConsultar.disabled = false;
        }
    });

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
                Resultado: ${s.resultado_propio || '—'} · Monto: ${fmtMonto(s.monto_total_ganador)}</p>`;
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
