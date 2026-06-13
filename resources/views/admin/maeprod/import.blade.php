@extends('layouts.admin')

@section('title', 'Carga masiva de productos')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Carga masiva de productos</h1>
            <p class="text-muted mb-0">Descarga la plantilla CSV, compl&eacute;tala y s&uacute;bela para crear o actualizar productos en el maestro (maeprod).</p>
        </div>
        <a href="{{ route('admin.productos.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
    </div>

    <div id="importLockBanner" class="alert alert-info @if(empty($activeImport)) d-none @endif">
        <strong>Importaci&oacute;n en curso.</strong>
        <span id="importLockMessage">
            @if(!empty($activeImport))
                Iniciada por <strong>{{ $activeImport['username'] }}</strong>
                ({{ \Illuminate\Support\Carbon::parse($activeImport['started_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}).
                Espere a que termine antes de iniciar otra carga.
            @endif
        </span>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">1. Descargar plantilla</div>
                <div class="card-body">
                    <p class="text-muted">
                        Archivo CSV con separador <strong>punto y coma (;)</strong>.
                        UTF-8 o Excel Windows (Latin-1); el sistema convierte la codificaci&oacute;n autom&aacute;ticamente.
                        Incluye una fila de ejemplo.
                    </p>
                    <dl class="small mb-4">
                        <dt class="fw-semibold">Columnas obligatorias</dt>
                        <dd><code>codigo</code>, <code>nombre</code>, <code>familia</code>, <code>precio</code></dd>
                        <dt class="fw-semibold">Columnas opcionales</dt>
                        <dd class="mb-0">
                            <code>costo</code>, <code>nombre_archivo</code> (ej. <code>90503_medium.jpg</code>),
                            <code>gramaje</code>, <code>stock</code>, <code>softland</code>
                        </dd>
                    </dl>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.productos.import.template') }}" class="btn btn-outline-primary" data-no-loader>
                            <i class="bi bi-download"></i> Descargar plantilla
                        </a>
                        <a href="{{ route('admin.productos.export') }}" class="btn btn-outline-success" data-no-loader>
                            <i class="bi bi-file-earmark-spreadsheet"></i> Descargar productos actuales
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">2. Subir archivo</div>
                <div class="card-body">
                    <form id="importForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Archivo CSV *</label>
                            <input type="file" id="importFile" accept=".csv,text/csv" class="form-control" required @if(!empty($activeImport)) disabled @endif>
                            <div class="form-text">
                                Hasta 50 MB. Solo puede haber <strong>una importaci&oacute;n activa</strong> a la vez en el sistema.
                                Si el c&oacute;digo ya existe, el producto se actualiza; si no, se crea.
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

                        <button type="submit" id="importSubmitBtn" class="btn btn-primary" @if(!empty($activeImport)) disabled @endif>
                            <i class="bi bi-upload"></i> Importar productos
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
const chunkUploadUrl = @json(route('admin.productos.import.chunk', [], false));
const processImportUrl = @json(route('admin.productos.import.process', [], false));
const importStatusUrl = @json(route('admin.productos.import.status', [], false));
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

function setImportLocked(locked, message = '') {
    const banner = document.getElementById('importLockBanner');
    const messageEl = document.getElementById('importLockMessage');
    const fileInput = document.getElementById('importFile');
    const submitBtn = document.getElementById('importSubmitBtn');

    if (locked) {
        banner?.classList.remove('d-none');
        if (messageEl && message) {
            messageEl.textContent = message;
        }
        if (fileInput) fileInput.disabled = true;
        if (submitBtn) submitBtn.disabled = true;
        return;
    }

    banner?.classList.add('d-none');
    if (fileInput) fileInput.disabled = false;
    if (submitBtn) submitBtn.disabled = false;
}

async function refreshImportStatus() {
    try {
        const response = await fetch(importStatusUrl, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            return;
        }

        if (payload.active && payload.lock) {
            const started = payload.lock.started_at ? new Date(payload.lock.started_at).toLocaleString('es-CL') : '';
            setImportLocked(true, `Iniciada por ${payload.lock.username}${started ? ' (' + started + ')' : ''}. Espere a que termine antes de iniciar otra carga.`);
        } else {
            setImportLocked(false);
        }
    } catch (error) {
        // ignore polling errors
    }
}

async function processImportAll(uploadId, progressBar, progressLabel, progressPercent) {
    progressLabel.textContent = 'Importando productos en el servidor...';
    progressBar.style.width = '55%';
    progressBar.setAttribute('aria-valuenow', '55');
    progressPercent.textContent = '55%';

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

    progressBar.style.width = '100%';
    progressBar.setAttribute('aria-valuenow', '100');
    progressPercent.textContent = '100%';
    progressLabel.textContent = 'Importación completada, redirigiendo...';

    if (payload.finished && payload.redirect) {
        window.location.href = payload.redirect;
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

    await refreshImportStatus();
    if (submitBtn.disabled) {
        errorBox.textContent = 'Hay una importación en curso. Espere a que termine.';
        errorBox.classList.remove('d-none');
        return;
    }

    const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    const uploadId = crypto.randomUUID();

    submitBtn.disabled = true;
    fileInput.disabled = true;
    setImportLocked(true, 'Su importación está en curso. No cierre esta ventana hasta que finalice.');
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
                progressLabel.textContent = 'Preparando importación...';
                await processImportAll(
                    payload.upload_id,
                    progressBar,
                    progressLabel,
                    progressPercent
                );
                return;
            }
        }
    } catch (error) {
        const message = error instanceof TypeError && error.message === 'Failed to fetch'
            ? 'No se pudo conectar con el servidor. Verifique su conexión o que APP_URL coincida con la URL del sitio.'
            : (error.message || 'Error inesperado durante la carga.');
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
        await refreshImportStatus();
        if (!submitBtn.disabled) {
            fileInput.disabled = false;
        }
        progressWrap.classList.add('d-none');
    }
});

refreshImportStatus();
</script>
@endpush
