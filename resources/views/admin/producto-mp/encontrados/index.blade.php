@extends('layouts.admin')

@section('title', 'Productos MP')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Productos MP</h1>
            <p class="text-muted mb-0 small">
                Ítems de Compra Ágil que coinciden con las <strong>frases de búsqueda</strong> del catálogo.
                Recorre <strong>todas</strong> las CA publicadas del día (no solo las de Oportunidades).
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @if($puedeFrases ?? false)
            <a href="{{ route('admin.producto-mp.frases.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-tags"></i> Frases de búsqueda
            </a>
            @endif
            @if($puedeBuscar ?? false)
            <button type="button" id="btn-buscar-producto-mp" class="btn btn-primary btn-sm">
                <i class="bi bi-search"></i> Buscar productos MP
            </button>
            <button type="button" id="btn-cancelar-producto-mp" class="btn btn-outline-danger btn-sm d-none">
                <i class="bi bi-x-circle"></i> Cancelar
            </button>
            @endif
        </div>
    </div>

    @if($puedeBuscar ?? false)
    <div id="producto-mp-estado" class="card shadow-sm mb-3 {{ ($corridaEstado['hay_corrida'] ?? false) ? '' : 'd-none' }}">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-3 align-items-center small mb-2">
                <div>Estado: <strong id="pmp-estado">{{ $corridaEstado['estado'] ?? '—' }}</strong></div>
                <div>CA revisadas: <strong id="pmp-cas" class="tabular-nums">{{ $corridaEstado['cas_revisadas'] ?? 0 }}</strong></div>
                <div>Matches: <strong id="pmp-matches" class="tabular-nums">{{ $corridaEstado['matches_encontrados'] ?? 0 }}</strong></div>
                <div class="ms-auto text-muted" id="pmp-mensaje">{{ $corridaEstado['mensaje'] ?? '' }}</div>
            </div>
            <div class="progress" style="height: 0.75rem;">
                <div id="pmp-barra" class="progress-bar" role="progressbar"
                     style="width: {{ (int) ($corridaEstado['porcentaje'] ?? 0) }}%">
                    {{ (int) ($corridaEstado['porcentaje'] ?? 0) }}%
                </div>
            </div>
        </div>
    </div>
    @endif

    <form method="get" class="row g-2 mb-3" data-no-loader>
        <div class="col-md-4">
            <input type="text" name="q" value="{{ $filtros['q'] ?? '' }}" class="form-control form-control-sm"
                   placeholder="CA, descripción, frase, organismo…">
        </div>
        <div class="col-md-2">
            <input type="text" name="prod_item" value="{{ $filtros['prod_item'] ?? '' }}" class="form-control form-control-sm"
                   placeholder="SKU">
        </div>
        <div class="col-md-3">
            <select name="region" class="form-select form-select-sm">
                <option value="">Todas las regiones</option>
                @foreach($regionesFiltro as $codigo => $nombre)
                    <option value="{{ $codigo }}" @selected((string) ($filtros['region'] ?? '') === (string) $codigo)>{{ $nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filtrar</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>SKU</th>
                        <th>Frase</th>
                        <th>Producto MP</th>
                        <th>Descripción MP</th>
                        <th>Cotización</th>
                        <th>Organismo</th>
                        <th>Región</th>
                        <th>Cierre</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($matches as $row)
                        <tr>
                            <td class="small">
                                <div class="fw-semibold tabular-nums">{{ $row->prod_item }}</div>
                                <div class="text-muted">{{ $row->prod_nombre }}</div>
                            </td>
                            <td><span class="badge text-bg-light border">{{ $row->frase }}</span></td>
                            <td class="tabular-nums small">{{ $row->codigo_producto_mp }}</td>
                            <td class="small">{{ $row->descripcion_mp }}</td>
                            <td class="small">
                                <div class="fw-semibold">{{ $row->codigo }}</div>
                                <div class="text-muted">{{ \Illuminate\Support\Str::limit($row->nombre_ca, 60) }}</div>
                            </td>
                            <td class="small">{{ $row->organismo }}</td>
                            <td class="small">{{ $row->nombre_region }}</td>
                            <td class="small tabular-nums">
                                {{ $row->fecha_cierre?->timezone(config('app.timezone'))->format('d/m H:i') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                Aún no hay productos MP encontrados.
                                @if($puedeFrases ?? false)
                                    Cargue frases y ejecute la búsqueda.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($matches->total() > 0)
            <div class="card-footer bg-white">
                <x-listado-paginacion :paginator="$matches" entity-label="productos MP" screen-key="producto-mp-encontrados" />
            </div>
        @endif
    </div>
</div>
@endsection

@if($puedeBuscar ?? false)
@push('scripts')
<script>
(function () {
    const urls = {
        iniciar: @json(route('admin.producto-mp.encontrados.iniciar')),
        estado: @json(route('admin.producto-mp.encontrados.estado')),
        cancelar: @json(route('admin.producto-mp.encontrados.cancelar')),
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const btnBuscar = document.getElementById('btn-buscar-producto-mp');
    const btnCancelar = document.getElementById('btn-cancelar-producto-mp');
    const card = document.getElementById('producto-mp-estado');
    let timer = null;

    function pintar(corrida) {
        if (!corrida || !corrida.hay_corrida) {
            return;
        }
        card.classList.remove('d-none');
        document.getElementById('pmp-estado').textContent = corrida.estado || '—';
        document.getElementById('pmp-cas').textContent = corrida.cas_revisadas ?? 0;
        document.getElementById('pmp-matches').textContent = corrida.matches_encontrados ?? 0;
        document.getElementById('pmp-mensaje').textContent = corrida.mensaje || '';
        const pct = Number(corrida.porcentaje) || 0;
        const barra = document.getElementById('pmp-barra');
        barra.style.width = pct + '%';
        barra.textContent = pct + '%';
        const running = corrida.running === true;
        btnBuscar.disabled = running;
        btnCancelar.classList.toggle('d-none', !running);
        if (running) {
            programarPoll();
        } else if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function programarPoll() {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(consultarEstado, 2500);
    }

    async function consultarEstado() {
        try {
            const res = await fetch(urls.estado, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const data = await res.json();
            pintar(data.corrida);
            if (data.corrida && data.corrida.estado === 'completed') {
                window.location.reload();
            }
        } catch (e) {
            programarPoll();
        }
    }

    btnBuscar?.addEventListener('click', async function () {
        btnBuscar.disabled = true;
        try {
            const res = await fetch(urls.iniciar, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: '{}',
            });
            const data = await res.json();
            if (!res.ok || data.ok === false) {
                alert(data.error || 'No se pudo iniciar la búsqueda.');
                btnBuscar.disabled = false;
                return;
            }
            pintar(data.corrida);
        } catch (e) {
            alert('No se pudo iniciar la búsqueda.');
            btnBuscar.disabled = false;
        }
    });

    btnCancelar?.addEventListener('click', async function () {
        await fetch(urls.cancelar, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: '{}',
        });
        consultarEstado();
    });

    pintar(@json($corridaEstado));
})();
</script>
@endpush
@endif
