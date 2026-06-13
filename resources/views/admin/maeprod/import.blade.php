@extends('layouts.admin')

@section('title', 'Carga masiva de productos')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Carga masiva de productos</h1>
            <p class="text-muted mb-0">Use la plantilla est&aacute;ndar o suba un CSV con columnas propias y mapee los campos antes de importar.</p>
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

    <ul class="nav nav-tabs mb-4" id="importModeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-template" data-bs-toggle="tab" data-bs-target="#panel-template" type="button" role="tab">
                Plantilla est&aacute;ndar
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-custom" data-bs-toggle="tab" data-bs-target="#panel-custom" type="button" role="tab">
                CSV personalizado
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="panel-template" role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">1. Descargar plantilla</div>
                        <div class="card-body">
                            <p class="text-muted">
                                Archivo CSV con separador <strong>punto y coma (;)</strong> o coma.
                                UTF-8 o Excel Windows (Latin-1).
                            </p>
                            <dl class="small mb-4">
                                <dt class="fw-semibold">Columnas obligatorias</dt>
                                <dd><code>codigo</code>, <code>nombre</code>, <code>familia</code>, <code>precio</code></dd>
                                <dt class="fw-semibold">Columnas opcionales</dt>
                                <dd class="mb-0">
                                    <code>costo</code>, <code>nombre_archivo</code>,
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
                            <form id="importFormTemplate" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label class="form-label">Archivo CSV *</label>
                                    <input type="file" id="importFileTemplate" accept=".csv,text/csv" class="form-control" required @if(!empty($activeImport)) disabled @endif>
                                </div>
                                <div id="importProgressWrapTemplate" class="mb-3 d-none">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span id="importProgressLabelTemplate">Subiendo archivo...</span>
                                        <span id="importProgressPercentTemplate">0%</span>
                                    </div>
                                    <div class="progress">
                                        <div id="importProgressBarTemplate" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                                    </div>
                                </div>
                                <div id="importErrorTemplate" class="alert alert-danger d-none mb-3"></div>
                                <button type="submit" id="importSubmitBtnTemplate" class="btn btn-primary" @if(!empty($activeImport)) disabled @endif>
                                    <i class="bi bi-upload"></i> Importar productos
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="panel-custom" role="tabpanel">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">1. Subir CSV con sus columnas</div>
                <div class="card-body">
                    <form id="importFormCustom" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Archivo CSV *</label>
                            <input type="file" id="importFileCustom" accept=".csv,text/csv" class="form-control" @if(!empty($activeImport)) disabled @endif>
                            <div class="form-text">Primera fila = encabezados. Separador <code>;</code> o <code>,</code>. Hasta 50 MB.</div>
                        </div>
                        <div id="importProgressWrapCustom" class="mb-3 d-none">
                            <div class="d-flex justify-content-between small mb-1">
                                <span id="importProgressLabelCustom">Subiendo archivo...</span>
                                <span id="importProgressPercentCustom">0%</span>
                            </div>
                            <div class="progress">
                                <div id="importProgressBarCustom" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                            </div>
                        </div>
                        <div id="importErrorCustom" class="alert alert-danger d-none mb-0"></div>
                        <button type="submit" id="importSubmitBtnCustom" class="btn btn-outline-primary" @if(!empty($activeImport)) disabled @endif>
                            <i class="bi bi-cloud-upload"></i> Subir y mapear columnas
                        </button>
                    </form>
                </div>
            </div>

            <div id="customMappingCard" class="card shadow-sm mb-4 d-none">
                <div class="card-header bg-white fw-semibold">2. Indique qu&eacute; columna corresponde a cada dato</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Archivo con <strong id="customTotalRows">0</strong> fila(s) de datos.
                        Los campos marcados con * son obligatorios.
                    </p>
                    <div class="row g-3" id="customMappingFields">
                        @foreach($mappableFields as $field)
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label small mb-1">
                                    {{ $field['label'] }}@if($field['required']) *@endif
                                </label>
                                <select class="form-select form-select-sm custom-mapping-select" data-field="{{ $field['field'] }}">
                                    <option value="">— No usar —</option>
                                </select>
                            </div>
                        @endforeach
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="button" id="customPreviewBtn" class="btn btn-secondary">
                            <i class="bi bi-eye"></i> Vista previa (10 filas)
                        </button>
                    </div>
                    <div id="customMappingError" class="alert alert-danger d-none mt-3 mb-0"></div>
                </div>
            </div>

            <div id="customPreviewCard" class="card shadow-sm mb-4 d-none">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-semibold">3. Vista previa (sin grabar en base de datos)</span>
                    <span id="customPreviewSummary" class="small text-muted"></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Fila</th>
                                <th>Acci&oacute;n</th>
                                <th>C&oacute;digo</th>
                                <th>Nombre</th>
                                <th>Familia</th>
                                <th class="text-end">Precio</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="customPreviewBody"></tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <p class="small text-muted mb-3">
                        Revise el resultado. Si est&aacute; conforme, confirme para importar <strong>todas</strong> las filas del archivo.
                    </p>
                    <button type="button" id="customConfirmBtn" class="btn btn-primary">
                        <i class="bi bi-check2-circle"></i> Confirmar e importar todo
                    </button>
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
const previewImportUrl = @json(route('admin.productos.import.preview', [], false));
const prepareImportUrl = @json(route('admin.productos.import.prepare', [], false));
const importStatusUrl = @json(route('admin.productos.import.status', [], false));
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());

let customUploadId = null;
let customColumns = [];

function importErrorMessage(payload, status) {
    if (payload?.message) return payload.message;
    if (payload?.errors) return Object.values(payload.errors).flat().join(' ');
    return `Error del servidor (${status}).`;
}

function setImportLocked(locked, message = '') {
    const banner = document.getElementById('importLockBanner');
    const messageEl = document.getElementById('importLockMessage');
    const inputs = [
        document.getElementById('importFileTemplate'),
        document.getElementById('importSubmitBtnTemplate'),
        document.getElementById('importFileCustom'),
        document.getElementById('importSubmitBtnCustom'),
        document.getElementById('customConfirmBtn'),
    ];

    if (locked) {
        banner?.classList.remove('d-none');
        if (messageEl && message) messageEl.textContent = message;
        inputs.forEach(el => { if (el) el.disabled = true; });
        return;
    }

    banner?.classList.add('d-none');
    inputs.forEach(el => { if (el) el.disabled = false; });
}

async function refreshImportStatus() {
    try {
        const response = await fetch(importStatusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) return;

        if (payload.active && payload.lock) {
            const started = payload.lock.started_at ? new Date(payload.lock.started_at).toLocaleString('es-CL') : '';
            setImportLocked(true, `Iniciada por ${payload.lock.username}${started ? ' (' + started + ')' : ''}. Espere a que termine.`);
        } else {
            setImportLocked(false);
        }
    } catch (error) { /* ignore */ }
}

async function uploadCsvChunks(file, mode, progress) {
    const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    const uploadId = crypto.randomUUID();

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
        const start = chunkIndex * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, file.size);
        const blob = file.slice(start, end);

        const formData = new FormData();
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', String(chunkIndex));
        formData.append('total_chunks', String(totalChunks));
        formData.append('original_name', file.name);
        formData.append('mode', mode);
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
        if (!response.ok) throw new Error(importErrorMessage(payload, response.status));

        const uploadPercent = Math.round(((chunkIndex + 1) / totalChunks) * (mode === 'custom' ? 100 : 45));
        progress.bar.style.width = uploadPercent + '%';
        progress.bar.setAttribute('aria-valuenow', String(uploadPercent));
        progress.percent.textContent = uploadPercent + '%';

        if (payload.done && payload.upload_id) {
            return payload;
        }
    }

    throw new Error('No se completó la carga del archivo.');
}

async function processImportAll(uploadId, progress) {
    progress.label.textContent = 'Importando productos en el servidor...';
    progress.bar.style.width = '55%';
    progress.percent.textContent = '55%';

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
    if (!response.ok) throw new Error(importErrorMessage(payload, response.status));

    progress.bar.style.width = '100%';
    progress.percent.textContent = '100%';
    progress.label.textContent = 'Importación completada, redirigiendo...';

    if (payload.finished && payload.redirect) {
        window.location.href = payload.redirect;
    }
}

function getCustomMapping() {
    const mapping = {};
    document.querySelectorAll('.custom-mapping-select').forEach(select => {
        mapping[select.dataset.field] = select.value;
    });
    return mapping;
}

function populateMappingSelects(columns, suggested) {
    document.querySelectorAll('.custom-mapping-select').forEach(select => {
        const current = select.value;
        select.innerHTML = '<option value="">— No usar —</option>';
        columns.forEach(column => {
            const option = document.createElement('option');
            option.value = column;
            option.textContent = column;
            select.appendChild(option);
        });
        const field = select.dataset.field;
        if (suggested && suggested[field]) {
            select.value = suggested[field];
        } else if (current) {
            select.value = current;
        }
    });
}

function renderPreviewRows(data) {
    const tbody = document.getElementById('customPreviewBody');
    const summary = document.getElementById('customPreviewSummary');
    tbody.innerHTML = '';

    data.rows.forEach(row => {
        const tr = document.createElement('tr');
        const accionBadge = row.accion === 'crear'
            ? '<span class="badge text-bg-success">Crear</span>'
            : row.accion === 'actualizar'
                ? '<span class="badge text-bg-primary">Actualizar</span>'
                : '<span class="badge text-bg-danger">Error</span>';

        const precio = row.precio !== undefined && row.precio !== '' && row.precio !== null
            ? Number(row.precio).toLocaleString('es-CL')
            : '—';

        const estado = row.mensaje
            ? `<span class="text-danger small">${row.mensaje}${row.detalle ? ' (' + row.detalle + ')' : ''}</span>`
            : '<span class="text-success small">OK</span>';

        tr.innerHTML = `
            <td>${row.fila ?? '—'}</td>
            <td>${accionBadge}</td>
            <td><code>${row.codigo || ''}</code></td>
            <td class="small">${row.nombre || ''}</td>
            <td class="small text-muted">${row.familia || ''}</td>
            <td class="text-end">${precio}</td>
            <td>${estado}</td>
        `;
        tbody.appendChild(tr);
    });

    const s = data.summary;
    summary.textContent = `Muestra de ${data.rows.length} filas — Crear: ${s.crear}, Actualizar: ${s.actualizar}, Errores: ${s.error} (total archivo: ${data.total_rows})`;
    document.getElementById('customPreviewCard').classList.remove('d-none');
}

document.getElementById('importFormTemplate').addEventListener('submit', async (event) => {
    event.preventDefault();
    const fileInput = document.getElementById('importFileTemplate');
    const submitBtn = document.getElementById('importSubmitBtnTemplate');
    const progress = {
        wrap: document.getElementById('importProgressWrapTemplate'),
        bar: document.getElementById('importProgressBarTemplate'),
        label: document.getElementById('importProgressLabelTemplate'),
        percent: document.getElementById('importProgressPercentTemplate'),
    };
    const errorBox = document.getElementById('importErrorTemplate');
    const file = fileInput.files[0];
    if (!file) return;

    await refreshImportStatus();
    if (submitBtn.disabled) {
        errorBox.textContent = 'Hay una importación en curso.';
        errorBox.classList.remove('d-none');
        return;
    }

    submitBtn.disabled = true;
    fileInput.disabled = true;
    setImportLocked(true, 'Su importación está en curso. No cierre esta ventana.');
    errorBox.classList.add('d-none');
    progress.wrap.classList.remove('d-none');

    try {
        const payload = await uploadCsvChunks(file, 'template', progress);
        progress.label.textContent = 'Preparando importación...';
        await processImportAll(payload.upload_id, progress);
    } catch (error) {
        errorBox.textContent = error.message || 'Error inesperado.';
        errorBox.classList.remove('d-none');
        await refreshImportStatus();
        if (!submitBtn.disabled) fileInput.disabled = false;
        progress.wrap.classList.add('d-none');
    }
});

document.getElementById('importFormCustom').addEventListener('submit', async (event) => {
    event.preventDefault();
    const fileInput = document.getElementById('importFileCustom');
    const submitBtn = document.getElementById('importSubmitBtnCustom');
    const progress = {
        wrap: document.getElementById('importProgressWrapCustom'),
        bar: document.getElementById('importProgressBarCustom'),
        label: document.getElementById('importProgressLabelCustom'),
        percent: document.getElementById('importProgressPercentCustom'),
    };
    const errorBox = document.getElementById('importErrorCustom');
    const file = fileInput.files[0];
    if (!file) return;

    submitBtn.disabled = true;
    fileInput.disabled = true;
    errorBox.classList.add('d-none');
    progress.wrap.classList.remove('d-none');
    progress.label.textContent = 'Subiendo archivo...';

    document.getElementById('customPreviewCard').classList.add('d-none');
    document.getElementById('customMappingError').classList.add('d-none');

    try {
        const payload = await uploadCsvChunks(file, 'custom', progress);
        customUploadId = payload.upload_id;
        customColumns = payload.columns || [];

        document.getElementById('customTotalRows').textContent = payload.total_rows || 0;
        populateMappingSelects(customColumns, payload.suggested_mapping || {});
        document.getElementById('customMappingCard').classList.remove('d-none');
        progress.label.textContent = 'Archivo listo. Indique el mapeo de columnas.';
    } catch (error) {
        errorBox.textContent = error.message || 'Error al subir el archivo.';
        errorBox.classList.remove('d-none');
        fileInput.disabled = false;
        progress.wrap.classList.add('d-none');
    } finally {
        submitBtn.disabled = false;
    }
});

document.getElementById('customPreviewBtn').addEventListener('click', async () => {
    const errorBox = document.getElementById('customMappingError');
    errorBox.classList.add('d-none');

    if (!customUploadId) {
        errorBox.textContent = 'Primero suba un archivo CSV.';
        errorBox.classList.remove('d-none');
        return;
    }

    const btn = document.getElementById('customPreviewBtn');
    btn.disabled = true;

    try {
        const response = await fetch(previewImportUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                upload_id: customUploadId,
                mapping: getCustomMapping(),
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(importErrorMessage(payload, response.status));

        renderPreviewRows(payload);
    } catch (error) {
        errorBox.textContent = error.message || 'No se pudo generar la vista previa.';
        errorBox.classList.remove('d-none');
    } finally {
        btn.disabled = false;
    }
});

document.getElementById('customConfirmBtn').addEventListener('click', async () => {
    const errorBox = document.getElementById('customMappingError');
    errorBox.classList.add('d-none');

    if (!customUploadId) return;

    const btn = document.getElementById('customConfirmBtn');
    btn.disabled = true;
    setImportLocked(true, 'Importación en curso. No cierre esta ventana.');

    const progress = {
        wrap: document.getElementById('importProgressWrapCustom'),
        bar: document.getElementById('importProgressBarCustom'),
        label: document.getElementById('importProgressLabelCustom'),
        percent: document.getElementById('importProgressPercentCustom'),
    };
    progress.wrap.classList.remove('d-none');
    progress.label.textContent = 'Preparando importación...';

    try {
        const prepareResponse = await fetch(prepareImportUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                upload_id: customUploadId,
                mapping: getCustomMapping(),
            }),
        });

        const preparePayload = await prepareResponse.json().catch(() => ({}));
        if (!prepareResponse.ok) throw new Error(importErrorMessage(preparePayload, prepareResponse.status));

        await processImportAll(preparePayload.upload_id, progress);
    } catch (error) {
        errorBox.textContent = error.message || 'Error al importar.';
        errorBox.classList.remove('d-none');
        await refreshImportStatus();
        btn.disabled = false;
    }
});

refreshImportStatus();
</script>
@endpush
