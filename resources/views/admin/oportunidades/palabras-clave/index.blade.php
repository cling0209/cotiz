@extends('layouts.admin')

@section('title', 'Palabras clave')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Palabras clave</h1>
            <p class="text-muted mb-0 small">
                Temas que usa este sitio para encontrar oportunidades publicadas en Compra &Aacute;gil
                (solo con <code>MERCADOPUBLICO_ANALISIS_ADMIN=true</code>).
                Las cotizaciones encontradas se sincronizan al sitio par; las palabras clave no.
            </p>
        </div>
        <a href="{{ route('admin.oportunidades.para-cotizar.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-lightning-charge"></i> Ver oportunidades
        </a>
    </div>

    <div class="alert alert-info py-2 small" role="status">
        <i class="bi bi-info-circle"></i>
        <strong>Prioridad de b&uacute;squeda:</strong>
        la lista se consulta de arriba hacia abajo.
        La palabra en la posici&oacute;n 1 se busca primero (dentro de cada regi&oacute;n).
        Puede reordenar con las flechas <i class="bi bi-arrow-up"></i>/<i class="bi bi-arrow-down"></i>
        o arrastrando la fila por el &iacute;cono <i class="bi bi-grip-vertical"></i>.
    </div>

    <div id="orden-feedback" class="alert alert-success py-2 small d-none" role="status"></div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="post" action="{{ route('admin.oportunidades.palabras-clave.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-8 col-lg-6">
                    <label class="form-label small mb-1" for="frase">Nueva palabra clave</label>
                    <input type="text" name="frase" id="frase" class="form-control form-control-sm @error('frase') is-invalid @enderror"
                           maxlength="200" required placeholder="Ej: servicio de aseo, alimentaci&oacute;n..."
                           value="{{ old('frase') }}">
                    @error('frase')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text">Se agrega al final (menor prioridad). Luego puede subirla.</div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Agregar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:2.5rem;"></th>
                        <th style="width:3.5rem;" class="text-center">#</th>
                        <th>Palabra clave</th>
                        <th>Agregada por</th>
                        <th>Fecha</th>
                        <th class="text-end">Prioridad</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="palabras-clave-tbody">
                    @forelse($palabras as $index => $palabra)
                        <tr data-id="{{ $palabra->id }}" class="palabra-fila">
                            <td class="text-muted text-center palabra-drag-handle" title="Arrastrar para reordenar" style="cursor:grab;">
                                <i class="bi bi-grip-vertical"></i>
                            </td>
                            <td class="text-center tabular-nums small text-muted palabra-pos">{{ $index + 1 }}</td>
                            <td class="fw-medium">{{ $palabra->frase }}</td>
                            <td class="small text-muted">
                                {{ $palabra->creador?->fullName() ?: ($palabra->creador?->username ?: '—') }}
                            </td>
                            <td class="small text-muted tabular-nums">
                                {{ $palabra->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                            </td>
                            <td class="text-end text-nowrap">
                                <form method="post"
                                      action="{{ route('admin.oportunidades.palabras-clave.mover', $palabra) }}"
                                      class="d-inline">
                                    @csrf
                                    <input type="hidden" name="direccion" value="up">
                                    <button type="submit"
                                            class="btn btn-outline-secondary btn-sm py-0 px-1"
                                            title="Subir prioridad (buscar antes)"
                                            @disabled($index === 0)>
                                        <i class="bi bi-arrow-up"></i>
                                    </button>
                                </form>
                                <form method="post"
                                      action="{{ route('admin.oportunidades.palabras-clave.mover', $palabra) }}"
                                      class="d-inline">
                                    @csrf
                                    <input type="hidden" name="direccion" value="down">
                                    <button type="submit"
                                            class="btn btn-outline-secondary btn-sm py-0 px-1"
                                            title="Bajar prioridad (buscar después)"
                                            @disabled($index === $palabras->count() - 1)>
                                        <i class="bi bi-arrow-down"></i>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end">
                                <form method="post"
                                      action="{{ route('admin.oportunidades.palabras-clave.destroy', $palabra) }}"
                                      class="d-inline"
                                      data-confirm="¿Eliminar la palabra clave «{{ $palabra->frase }}»?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr class="palabra-vacia">
                            <td colspan="7" class="text-center text-muted py-4">
                                A&uacute;n no hay palabras clave. Agregue al menos una para buscar oportunidades.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
(() => {
    const tbody = document.getElementById('palabras-clave-tbody');
    const feedback = document.getElementById('orden-feedback');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const urlReordenar = @json(route('admin.oportunidades.palabras-clave.reordenar'));

    if (!tbody || typeof Sortable === 'undefined' || tbody.querySelector('.palabra-vacia')) {
        return;
    }

    function renumerarPosiciones() {
        tbody.querySelectorAll('tr.palabra-fila').forEach((tr, i) => {
            const pos = tr.querySelector('.palabra-pos');
            if (pos) pos.textContent = String(i + 1);
            const forms = tr.querySelectorAll('form');
            forms.forEach((form) => {
                const dir = form.querySelector('input[name="direccion"]')?.value;
                const btn = form.querySelector('button[type="submit"]');
                if (!btn || !dir) return;
                if (dir === 'up') btn.disabled = i === 0;
                if (dir === 'down') {
                    btn.disabled = i === tbody.querySelectorAll('tr.palabra-fila').length - 1;
                }
            });
        });
    }

    function mostrarFeedback(msg, ok = true) {
        if (!feedback) return;
        feedback.textContent = msg;
        feedback.classList.toggle('d-none', !msg);
        feedback.classList.toggle('alert-success', ok);
        feedback.classList.toggle('alert-danger', !ok);
    }

    Sortable.create(tbody, {
        handle: '.palabra-drag-handle',
        animation: 150,
        draggable: 'tr.palabra-fila',
        ghostClass: 'table-secondary',
        onEnd: async () => {
            renumerarPosiciones();
            const ids = Array.from(tbody.querySelectorAll('tr.palabra-fila')).map((tr) => Number(tr.dataset.id));
            try {
                const res = await fetch(urlReordenar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ ids }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.ok === false) {
                    throw new Error(data.error || data.message || `HTTP ${res.status}`);
                }
                let msg = data.mensaje || 'Prioridad de búsqueda actualizada. En Oportunidades se busca en este orden.';
                if (data.info) msg += ' ' + data.info;
                mostrarFeedback(msg, !data.error);
            } catch (e) {
                mostrarFeedback(e.message || 'No se pudo guardar el orden.', false);
                window.location.reload();
            }
        },
    });
})();
</script>
@endpush
