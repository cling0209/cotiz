@extends('layouts.admin')

@section('title', 'Carga masiva tramos de peso')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Carga masiva de tramos por peso</h1>
            <p class="text-muted mb-0">Importa o actualiza tramos de envío por comuna (fuera de RM) usando el código CUT oficial.</p>
        </div>
        <a href="{{ route('admin.shipping.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver a envíos
        </a>
    </div>

    @if(session('import_errors'))
        <div class="alert alert-warning">
            <strong>Algunas filas no se importaron:</strong>
            <ul class="mb-0 mt-2">
                @foreach(session('import_errors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card admin-card h-100">
                <div class="card-header bg-white fw-semibold">1. Plantilla y datos actuales</div>
                <div class="card-body">
                    <p class="text-muted">
                        Archivo CSV con separador <strong>punto y coma (;)</strong>. UTF-8 o Excel Windows.
                    </p>
                    <dl class="small mb-4">
                        <dt class="fw-semibold">Columnas obligatorias</dt>
                        <dd>
                            <code>codigo_comuna</code> (CUT oficial, 5 dígitos),
                            <code>peso_min_kg</code>,
                            <code>adicional_clp</code>
                        </dd>
                        <dt class="fw-semibold">Columnas de referencia (no editar)</dt>
                        <dd>
                            <code>comuna (no editar)</code>,
                            <code>region (no editar)</code>,
                            <code>etiqueta (no editar)</code>
                            — solo para leer en Excel; la etiqueta se genera automáticamente según
                            <code>peso_min_kg</code> y <code>peso_max_kg</code>.
                        </dd>
                        <dt class="fw-semibold">Columnas opcionales</dt>
                        <dd>
                            <code>id</code> (para actualizar por ID),
                            <code>peso_max_kg</code> (vacío = sin límite),
                            <code>orden</code>, <code>activo</code> (1/0)
                        </dd>
                        <dt class="fw-semibold">Actualización</dt>
                        <dd>
                            Sin <code>id</code>, se busca por <code>codigo_comuna</code> + rango de peso
                            (<code>peso_min_kg</code> / <code>peso_max_kg</code>).
                        </dd>
                        <dt class="fw-semibold">Plantilla</dt>
                        <dd>
                            Incluye todas las comunas fuera de RM con <strong>4 tramos por defecto</strong> cada una.
                            Solo debes ajustar precios o rangos de peso; no borres el <code>codigo_comuna</code>.
                        </dd>
                        <dt class="fw-semibold">Excel</dt>
                        <dd class="mb-0">
                            Formatea <code>codigo_comuna</code> como <strong>texto</strong> para conservar ceros a la izquierda
                            (ej. <code>05101</code>).
                        </dd>
                    </dl>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.shipping.import.template') }}" class="btn btn-outline-primary" data-no-loader>
                            <i class="bi bi-download"></i> Descargar plantilla
                        </a>
                        <a href="{{ route('admin.shipping.export') }}" class="btn btn-outline-success" data-no-loader>
                            <i class="bi bi-file-earmark-spreadsheet"></i> Descargar tramos actuales
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card admin-card h-100">
                <div class="card-header bg-white fw-semibold">2. Subir archivo</div>
                <div class="card-body">
                    <form id="importForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Archivo CSV *</label>
                            <input type="file" id="importFile" accept=".csv,text/csv" class="form-control" required>
                            <div class="form-text">
                                Hasta 50 MB. Se sube en fragmentos de ~6 MB e importa en lotes de 50 filas.
                                Puedes exportar los tramos actuales, editar precios y volver a subirlos.
                            </div>
                        </div>

                        <div id="importProgressWrap" class="mb-3 d-none">
                            <div class="d-flex justify-content-between small mb-1">
                                <span id="importProgressLabel">Subiendo archivo...</span>
                                <span id="importProgressPercent">0%</span>
                            </div>
                            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                                <div id="importProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                            </div>
                        </div>

                        <div id="importError" class="alert alert-danger d-none mb-3"></div>

                        <button type="submit" id="importSubmitBtn" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Importar tramos
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const CHUNK_SIZE = 6 * 1024 * 1024;
const chunkUploadUrl = @json(route('admin.shipping.import.chunk'));
const processImportUrl = @json(route('admin.shipping.import.process'));
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());

function importErrorMessage(payload, status) {
    if (payload?.message) {
        return payload.message;
    }

    if (payload?.errors) {
        return Object.values(payload.errors).flat().join(' ');
    }

    return `Error del servidor (${status}).`;
}

async function processImportBatches(uploadId, batchCount, progressBar, progressLabel, progressPercent) {
    let totalBatches = batchCount;
    let processed = 0;

    while (!totalBatches || processed < totalBatches) {
        const formData = new FormData();
        formData.append('upload_id', uploadId);
        formData.append('_token', csrfToken);

        const response = await fetch(processImportUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(importErrorMessage(payload, response.status));
        }

        if (!totalBatches && payload.total_batches) {
            totalBatches = payload.total_batches;
            progressBar.style.width = '50%';
            progressBar.setAttribute('aria-valuenow', '50');
            progressPercent.textContent = '50%';
            progressLabel.textContent = `Preparado: ${totalBatches} lotes. Importando...`;
            continue;
        }

        processed = payload.processed_batches ?? processed + 1;
        const percent = totalBatches
            ? 50 + Math.round((processed / totalBatches) * 50)
            : 45;
        progressBar.style.width = percent + '%';
        progressBar.setAttribute('aria-valuenow', String(percent));
        progressPercent.textContent = percent + '%';
        progressLabel.textContent = totalBatches
            ? `Importando tramos (${processed}/${totalBatches} lotes)...`
            : 'Preparando importación...';

        if (payload.finished && payload.redirect) {
            window.location.href = payload.redirect;
            return;
        }
    }
}

document.getElementById('importForm').addEventListener('submit', async (event) => {
    event.preventDefault();

    const fileInput = document.getElementById('importFile');
    const submitBtn = document.getElementById('importSubmitBtn');
    const progressWrap = document.getElementById('importProgressWrap');
    const progressBar = document.getElementById('importProgressBar');
    const progressLabel = document.getElementById('importProgressLabel');
    const progressPercent = document.getElementById('importProgressPercent');
    const errorBox = document.getElementById('importError');

    const file = fileInput.files[0];

    if (!file) {
        return;
    }

    const extension = file.name.split('.').pop()?.toLowerCase();

    if (!['csv', 'txt'].includes(extension || '')) {
        errorBox.textContent = 'Solo se permiten archivos CSV.';
        errorBox.classList.remove('d-none');
        return;
    }

    const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    const uploadId = crypto.randomUUID();

    submitBtn.disabled = true;
    fileInput.disabled = true;
    errorBox.classList.add('d-none');
    progressWrap.classList.remove('d-none');
    progressLabel.textContent = 'Subiendo archivo...';

    try {
        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const blob = file.slice(start, end);

            const formData = new FormData();
            formData.append('upload_id', uploadId);
            formData.append('chunk_index', String(chunkIndex));
            formData.append('total_chunks', String(totalChunks));
            formData.append('original_name', file.name);
            formData.append('chunk', blob, `chunk-${chunkIndex}.part`);
            formData.append('_token', csrfToken);

            const response = await fetch(chunkUploadUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(importErrorMessage(payload, response.status));
            }

            const uploadPercent = Math.round(((chunkIndex + 1) / totalChunks) * 45);
            progressBar.style.width = uploadPercent + '%';
            progressBar.setAttribute('aria-valuenow', String(uploadPercent));
            progressPercent.textContent = uploadPercent + '%';

            if (payload.done && payload.upload_id) {
                progressBar.style.width = '45%';
                progressBar.setAttribute('aria-valuenow', '45');
                progressPercent.textContent = '45%';
                progressLabel.textContent = 'Archivo recibido. Preparando lotes...';
                await processImportBatches(
                    payload.upload_id,
                    payload.batch_count || 0,
                    progressBar,
                    progressLabel,
                    progressPercent
                );
                return;
            }
        }
    } catch (error) {
        errorBox.textContent = error.message || 'Error inesperado durante la carga.';
        errorBox.classList.remove('d-none');
        submitBtn.disabled = false;
        fileInput.disabled = false;
        progressWrap.classList.add('d-none');
    }
});
</script>
@endpush
