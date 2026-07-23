@extends('layouts.admin')

@section('title', 'Carga masiva de productos')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Carga masiva de productos</h1>
            <p class="text-muted mb-0">Use la plantilla est&aacute;ndar (CSV o Excel) o suba un archivo con columnas propias y mapee los campos antes de importar.</p>
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
        <button type="button" id="importUnlockBtn" class="btn btn-sm btn-outline-warning ms-2 @if(empty($activeImport)) d-none @endif">
            Liberar carga atascada
        </button>
    </div>

    <ul class="nav nav-tabs mb-4" id="importModeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-template" data-bs-toggle="tab" data-bs-target="#panel-template" type="button" role="tab">
                Plantilla est&aacute;ndar
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-custom" data-bs-toggle="tab" data-bs-target="#panel-custom" type="button" role="tab">
                CSV / Excel personalizado
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
                                Formatos: <strong>CSV</strong> (<code>;</code> o <code>,</code>) o <strong>Excel</strong> (.xlsx, .xls).
                                La primera hoja del libro se importa.
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
                                    <i class="bi bi-download"></i> Plantilla CSV
                                </a>
                                <a href="{{ route('admin.productos.import.template.excel') }}" class="btn btn-outline-primary" data-no-loader>
                                    <i class="bi bi-file-earmark-excel"></i> Plantilla Excel
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
                            <form id="importFormTemplate" enctype="multipart/form-data" data-no-loader>
                                <div class="mb-3">
                                    <label class="form-label">Archivo CSV o Excel *</label>
                                    <input type="file" id="importFileTemplate" accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" class="form-control" required @if(!empty($activeImport)) disabled @endif>
                                    <div class="form-text">CSV o Excel (.xlsx, .xls) hasta 50 MB (subida fragmentada en trozos de 6 MB).</div>
                                </div>
                                <div id="importProgressWrapTemplate" class="mb-3 d-none">
                                    <div class="d-flex justify-content-between align-items-center small mb-1 gap-2">
                                        <span id="importProgressStageTemplate" class="badge text-bg-primary">Paso 1 de 3</span>
                                        <span id="importProgressPercentTemplate" class="fw-semibold">0%</span>
                                    </div>
                                    <div id="importProgressLabelTemplate" class="small text-muted mb-2">Iniciando...</div>
                                    <div class="progress" style="height:1.25rem">
                                        <div id="importProgressBarTemplate" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
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
                <div class="card-header bg-white fw-semibold">1. Subir archivo con sus columnas</div>
                <div class="card-body">
                    <form id="importFormCustom" enctype="multipart/form-data" data-no-loader>
                        <div class="mb-3">
                            <label class="form-label">Archivo CSV o Excel *</label>
                            <input type="file" id="importFileCustom" accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" class="form-control" @if(!empty($activeImport)) disabled @endif>
                            <div class="form-text">Primera fila = encabezados. CSV o Excel (.xlsx, .xls) hasta 50 MB (subida fragmentada).</div>
                        </div>
                        <div id="importProgressWrapCustom" class="mb-3 d-none">
                            <div class="d-flex justify-content-between align-items-center small mb-1 gap-2">
                                <span id="importProgressStageCustom" class="badge text-bg-primary">Paso 1 de 2</span>
                                <span id="importProgressPercentCustom" class="fw-semibold">0%</span>
                            </div>
                            <div id="importProgressLabelCustom" class="small text-muted mb-2">Iniciando...</div>
                            <div class="progress" style="height:1.25rem">
                                <div id="importProgressBarCustom" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
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
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
const CHUNK_SIZE = 6 * 1024 * 1024;
const CLIENT_EXCEL_MAX_BYTES = 15 * 1024 * 1024;
const MAX_IMPORT_BYTES = 50 * 1024 * 1024;
const BACKGROUND_IMPORT_ENABLED = @json((bool) config('cotiz.import.background', true));
const chunkUploadUrl = @json(route('admin.productos.import.chunk', [], false));
const initializeImportUrl = @json(route('admin.productos.import.initialize', [], false));
const prepareTemplateImportUrl = @json(route('admin.productos.import.prepare.template', [], false));
const processImportUrl = @json(route('admin.productos.import.process', [], false));
const startBackgroundImportUrl = @json(route('admin.productos.import.background', [], false));
const importProgressUrl = @json(route('admin.productos.import.progress', [], false));
const previewImportUrl = @json(route('admin.productos.import.preview', [], false));
const prepareImportUrl = @json(route('admin.productos.import.prepare', [], false));
const importStatusUrl = @json(route('admin.productos.import.status', [], false));
const unlockImportUrl = @json(route('admin.productos.import.unlock', [], false));
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());
const currentUserId = @json((int) auth()->id());

let customUploadId = null;
let customColumns = [];
let importResumeRunning = false;

const ALLOWED_IMPORT_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

function fileExtension(name) {
    return name.split('.').pop()?.toLowerCase() || '';
}

function isAllowedImportFile(name) {
    return ALLOWED_IMPORT_EXTENSIONS.includes(fileExtension(name));
}

async function convertExcelToCsvFile(file, progress, plan) {
    if (typeof XLSX === 'undefined') {
        throw new Error('No se pudo cargar la librería de conversión Excel.');
    }

    setImportProgress(progress, {
        step: plan.step,
        totalSteps: plan.totalSteps,
        stage: plan.stage,
        percent: plan.start + (plan.span * 0.15),
        detail: 'Convirtiendo Excel a CSV en el navegador...',
    });

    const buffer = await file.arrayBuffer();
    const workbook = XLSX.read(buffer, { type: 'array', dense: true });
    const sheetName = workbook.SheetNames[0];

    if (!sheetName) {
        throw new Error('El archivo Excel no contiene hojas.');
    }

    const csv = XLSX.utils.sheet_to_csv(workbook.Sheets[sheetName], { FS: ';' });
    const baseName = file.name.replace(/\.(xlsx|xls)$/i, '');
    const blob = new Blob(['\ufeff', csv], { type: 'text/csv;charset=utf-8' });

    return new File([blob], `${baseName}.csv`, { type: 'text/csv' });
}

async function normalizeImportFile(file, progress, plan) {
    const extension = fileExtension(file.name);

    if (!['xlsx', 'xls'].includes(extension)) {
        return file;
    }

    if (file.size > CLIENT_EXCEL_MAX_BYTES) {
        return file;
    }

    try {
        return await convertExcelToCsvFile(file, progress, plan);
    } catch (error) {
        console.warn('Conversión Excel en navegador falló; se usará el servidor.', error);
        return file;
    }
}

function isTransientHttpStatus(status) {
    return [502, 503, 504].includes(status);
}

function looksLikeHtmlResponse(text) {
    const trimmed = text.trim().toLowerCase();
    return trimmed.startsWith('<!doctype') || trimmed.startsWith('<html');
}

function importErrorMessage(payload, status) {
    if (payload?.message && !looksLikeHtmlResponse(payload.message)) {
        return payload.message;
    }
    if (payload?.errors) return Object.values(payload.errors).flat().join(' ');
    if (status === 401) {
        return 'Sesión expirada. Recargue la página (Ctrl+F5) e inicie sesión nuevamente.';
    }
    if (status === 419) {
        return 'Token de seguridad expirado. Recargue la página (Ctrl+F5) e intente de nuevo.';
    }
    if (status === 502) {
        return 'Error del servidor (502). La importación puede seguir en segundo plano: recargue la página para retomar el progreso.';
    }
    if (status === 503 || status === 504) {
        return `Error del servidor (${status}). Espere unos segundos y recargue la página para retomar el progreso.`;
    }
    if (status === 500) {
        return 'Error del servidor (500). Si usa Render, confirme QUEUE_CONNECTION=database y que el worker esté activo.';
    }
    return `Error del servidor (${status}).`;
}

function rethrowFetchNetworkError(error) {
    if (error instanceof TypeError && /failed to fetch|networkerror|load failed/i.test(error.message || '')) {
        throw new Error(
            'No se pudo conectar con el servidor durante la subida. Recargue la página (Ctrl+F5), confirme que está en http://localhost:8082 e intente de nuevo.',
        );
    }

    throw error;
}

async function readJsonResponse(response) {
    const text = await response.text();

    if (!text) {
        return {};
    }

    try {
        return JSON.parse(text);
    } catch {
        if (looksLikeHtmlResponse(text)) {
            return {};
        }

        const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 200);
        return { message: snippet };
    }
}

function formatBytes(bytes) {
    if (!bytes || bytes < 1024) return `${bytes || 0} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function setImportProgress(progress, { step, totalSteps, stage, percent, detail }) {
    const pct = Math.max(0, Math.min(100, Math.round(percent)));

    if (progress.stage) {
        progress.stage.textContent = `Paso ${step} de ${totalSteps}: ${stage}`;
    }

    progress.percent.textContent = `${pct}%`;
    progress.bar.style.width = `${pct}%`;
    progress.bar.setAttribute('aria-valuenow', String(pct));
    progress.label.textContent = detail || stage;
}

function buildProgressRefs(prefix) {
    return {
        wrap: document.getElementById(`importProgressWrap${prefix}`),
        stage: document.getElementById(`importProgressStage${prefix}`),
        bar: document.getElementById(`importProgressBar${prefix}`),
        label: document.getElementById(`importProgressLabel${prefix}`),
        percent: document.getElementById(`importProgressPercent${prefix}`),
    };
}

function setImportLocked(locked, message = '') {
    const banner = document.getElementById('importLockBanner');
    const messageEl = document.getElementById('importLockMessage');
    const unlockBtn = document.getElementById('importUnlockBtn');
    const inputs = [
        document.getElementById('importFileTemplate'),
        document.getElementById('importSubmitBtnTemplate'),
        document.getElementById('importFileCustom'),
        document.getElementById('importSubmitBtnCustom'),
        document.getElementById('customConfirmBtn'),
    ];

    if (locked) {
        banner?.classList.remove('d-none');
        unlockBtn?.classList.remove('d-none');
        if (messageEl && message) messageEl.textContent = message;
        inputs.forEach(el => { if (el) el.disabled = true; });
        return;
    }

    banner?.classList.add('d-none');
    unlockBtn?.classList.add('d-none');
    inputs.forEach(el => { if (el) el.disabled = false; });
}

async function releaseImportLock(uploadId = null) {
    const formData = new FormData();
    if (uploadId) formData.append('upload_id', uploadId);
    formData.append('_token', csrfToken);

    await fetch(unlockImportUrl, {
        method: 'POST',
        body: formData,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        credentials: 'same-origin',
    }).catch(() => {});
}

async function refreshImportStatus() {
    try {
        const response = await fetch(importStatusUrl, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const payload = await readJsonResponse(response);
        if (response.status === 401) {
            return null;
        }
        if (!response.ok) return null;

        if (payload.active && payload.lock) {
            const started = payload.lock.started_at ? new Date(payload.lock.started_at).toLocaleString('es-CL') : '';
            const fileLabel = payload.lock.original_name ? ` (${payload.lock.original_name})` : '';
            setImportLocked(
                true,
                `Iniciada por ${payload.lock.username}${started ? ' (' + started + ')' : ''}${fileLabel}. Puede seguir el progreso abajo.`,
            );
            return payload.lock;
        }

        setImportLocked(false);
        return null;
    } catch (error) {
        return null;
    }
}

function resolveResumePollPlan(progressPayload) {
    const phase = progressPayload.phase || 'queued';
    const mode = progressPayload.mode || 'template';
    const totalSteps = mode === 'custom' ? 2 : 3;

    if (phase === 'process') {
        return {
            step: mode === 'custom' ? 2 : 3,
            totalSteps,
            start: 12,
            span: 88,
        };
    }

    return {
        step: 2,
        totalSteps,
        start: 12,
        span: 88,
    };
}

function activateImportTabForMode(mode) {
    if (mode !== 'custom') {
        return;
    }

    const customTab = document.getElementById('tab-custom');
    if (customTab && typeof bootstrap !== 'undefined') {
        bootstrap.Tab.getOrCreateInstance(customTab).show();
    } else {
        customTab?.click();
    }
}

function applyPollProgressPayload(payload, progress, pollPlan) {
    const percent = typeof payload.percent === 'number'
        ? payload.percent
        : pollPlan.start + (pollPlan.span * 0.5);
    let detail = payload.detail || 'Procesando...';

    if (payload.stale_warning) {
        detail = `${detail} — ${payload.stale_warning}`;
    }

    setImportProgress(progress, {
        step: payload.phase === 'process'
            ? (pollPlan.totalSteps >= 3 ? 3 : 2)
            : (pollPlan.totalSteps >= 3 ? 2 : 1),
        totalSteps: pollPlan.totalSteps,
        stage: payload.stage || 'Procesando en segundo plano',
        percent,
        detail,
    });
}

async function fetchImportProgress(uploadId) {
    const response = await fetch(`${importProgressUrl}?upload_id=${encodeURIComponent(uploadId)}`, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    });

    return {
        response,
        payload: await readJsonResponse(response),
    };
}

async function resumeActiveImport(lock) {
    if (!lock?.upload_id || importResumeRunning) {
        return;
    }

    if (lock.user_id && Number(lock.user_id) !== Number(currentUserId)) {
        return;
    }

    importResumeRunning = true;
    const uploadId = lock.upload_id;

    try {
        const { response, payload } = await fetchImportProgress(uploadId);

        if (response.status === 404) {
            const fileLabel = lock.original_name ? ` (${lock.original_name})` : '';
            setImportLocked(true, `Importación en curso${fileLabel}. Si acaba de subir el archivo, espere unos segundos y recargue la página.`);
            return;
        }

        if (!response.ok) {
            throw new Error(importErrorMessage(payload, response.status));
        }

        if (payload.phase === 'completed') {
            if (payload.redirect) {
                window.location.href = payload.redirect;
                return;
            }

            setImportLocked(false);
            return;
        }

        if (payload.phase === 'failed') {
            const mode = payload.mode || 'template';
            const errorBox = document.getElementById(mode === 'custom' ? 'importErrorCustom' : 'importErrorTemplate');
            if (errorBox) {
                errorBox.textContent = payload.error || payload.detail || 'La importación falló.';
                errorBox.classList.remove('d-none');
            }
            return;
        }

        const mode = payload.mode || 'template';
        activateImportTabForMode(mode);

        if (mode === 'custom') {
            customUploadId = uploadId;
        }

        const progress = buildProgressRefs(mode === 'custom' ? 'Custom' : 'Template');
        const plan = resolveResumePollPlan(payload);

        progress.wrap.classList.remove('d-none');
        applyPollProgressPayload(payload, progress, plan);

        await pollBackgroundImport(uploadId, progress, plan);
    } catch (error) {
        const errorBox = document.getElementById('importErrorTemplate');
        if (errorBox) {
            errorBox.textContent = error.message || 'No se pudo retomar la importación.';
            errorBox.classList.remove('d-none');
        }
    } finally {
        importResumeRunning = false;
        await refreshImportStatus();
    }
}

async function uploadCsvChunks(file, mode, progress, uploadPlan) {
    const extension = fileExtension(file.name);
    const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    const uploadId = crypto.randomUUID();
    const plan = uploadPlan || { step: 1, totalSteps: 1, stage: 'Subiendo archivo', start: 0, span: 100 };

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
        const start = chunkIndex * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, file.size);
        const blob = file.slice(start, end);

        setImportProgress(progress, {
            step: plan.step,
            totalSteps: plan.totalSteps,
            stage: plan.stage,
            percent: plan.start + (((chunkIndex + 1) / totalChunks) * plan.span),
            detail: `Fragmento ${chunkIndex + 1} de ${totalChunks} (${formatBytes(end)} de ${formatBytes(file.size)})`,
        });

        const formData = new FormData();
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', String(chunkIndex));
        formData.append('total_chunks', String(totalChunks));
        formData.append('original_name', file.name);
        formData.append('mode', mode);
        formData.append('chunk', blob, `chunk-${chunkIndex}.part`);
        formData.append('_token', csrfToken);

        let response = null;
        let payload = {};

        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                response = await fetch(chunkUploadUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                });
            } catch (networkError) {
                rethrowFetchNetworkError(networkError);
            }

            if (response.redirected && response.url.includes('/admin/login')) {
                throw new Error('Sesión expirada. Recargue la página (Ctrl+F5) e inicie sesión nuevamente.');
            }

            payload = await readJsonResponse(response);

            if (response.ok || ![502, 503, 504].includes(response.status)) {
                break;
            }

            setImportProgress(progress, {
                step: plan.step,
                totalSteps: plan.totalSteps,
                stage: plan.stage,
                percent: plan.start + (((chunkIndex + 0.5) / totalChunks) * plan.span),
                detail: `Reintentando fragmento ${chunkIndex + 1} (intento ${attempt + 2} de 3)...`,
            });

            await new Promise(resolve => setTimeout(resolve, 1500 * (attempt + 1)));
        }

        if (!response.ok) throw new Error(importErrorMessage(payload, response.status));

        if (payload.done && payload.upload_id) {
            setImportProgress(progress, {
                step: plan.step,
                totalSteps: plan.totalSteps,
                stage: plan.stage,
                percent: plan.start + plan.span,
                detail: 'Archivo recibido en el servidor.',
            });

            return payload;
        }
    }

    throw new Error('No se completó la carga del archivo.');
}

function estimateImportBatchCount(totalRows) {
    if (!totalRows || totalRows < 1) {
        return 1;
    }

    return Math.max(1, Math.ceil(totalRows / 5000));
}

async function processImportBatches(uploadId, batchCount, progress, importPlan, options = {}) {
    let processed = 0;
    let totalBatches = Math.max(1, batchCount || 1);
    const plan = importPlan || { step: 1, totalSteps: 1, stage: 'Grabando productos', start: 0, span: 100 };
    let streamMode = options.streamMode === true;
    let rowsTotal = options.totalRows ?? null;

    if (streamMode && rowsTotal > 0 && totalBatches <= 1) {
        totalBatches = estimateImportBatchCount(rowsTotal);
    }

    const maxStepPercent = plan.start + plan.span;

    while (true) {
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

        const payload = await readJsonResponse(response);
        if (!response.ok) throw new Error(importErrorMessage(payload, response.status));

        if (payload.import_mode === 'stream' || payload.import_mode === 'excel_direct') {
            streamMode = true;
        }

        if (payload.total_batches) {
            totalBatches = Math.max(1, payload.total_batches);
        }

        if (payload.total_rows) {
            rowsTotal = payload.total_rows;
        }

        processed = payload.processed_batches ?? processed + 1;
        const result = payload.result || {};
        const created = result.created ?? 0;
        const updated = result.updated ?? 0;
        const skipped = result.skipped ?? 0;
        const percent = payload.finished
            ? maxStepPercent
            : Math.min(maxStepPercent - 1, plan.start + ((processed / totalBatches) * plan.span));
        const detail = `Lote ${processed} de ${totalBatches} — creados: ${created.toLocaleString('es-CL')}, actualizados: ${updated.toLocaleString('es-CL')}, omitidos: ${skipped.toLocaleString('es-CL')}`;

        setImportProgress(progress, {
            step: plan.step,
            totalSteps: plan.totalSteps,
            stage: plan.stage,
            percent,
            detail,
        });

        if (payload.finished && payload.redirect) {
            setImportProgress(progress, {
                step: plan.totalSteps,
                totalSteps: plan.totalSteps,
                stage: 'Completado',
                percent: 100,
                detail: 'Importación finalizada. Redirigiendo al resultado...',
            });
            window.location.href = payload.redirect;
            return;
        }

        if (payload.finished) {
            break;
        }
    }
}

function updatePrepareProgress(progress, payload, plan) {
    const processed = payload.processed_rows ?? 0;
    const total = payload.total_rows ?? null;
    let percent;
    let detail;

    if (total && total > 0) {
        percent = plan.start + ((processed / total) * plan.span);
        detail = `${processed.toLocaleString('es-CL')} de ${total.toLocaleString('es-CL')} filas convertidas a CSV`;
    } else if (processed > 0) {
        percent = plan.start + (Math.min(0.85, processed / 50000) * plan.span);
        detail = `${processed.toLocaleString('es-CL')} filas convertidas a CSV`;
    } else {
        percent = plan.start + (plan.span * 0.12);
        detail = 'Convirtiendo Excel a CSV en el servidor (por trozos)...';
    }

    setImportProgress(progress, {
        step: plan.step,
        totalSteps: plan.totalSteps,
        stage: plan.stage,
        percent,
        detail,
    });
}

async function prepareImportUntilFinished(url, fetchOptions, progress, plan) {
    let payload = null;
    let isFirstRequest = true;

    do {
        if (isFirstRequest) {
            setImportProgress(progress, {
                step: plan.step,
                totalSteps: plan.totalSteps,
                stage: plan.stage,
                percent: plan.start + (plan.span * 0.08),
                detail: 'Convirtiendo Excel a CSV (primer trozo)...',
            });
            isFirstRequest = false;
        }

        const response = await fetch(url, fetchOptions);
        payload = await readJsonResponse(response);
        if (!response.ok) throw new Error(importErrorMessage(payload, response.status));
        updatePrepareProgress(progress, payload, plan);
    } while (payload.prepare_finished !== true);

    setImportProgress(progress, {
        step: plan.step,
        totalSteps: plan.totalSteps,
        stage: plan.stage,
        percent: plan.start + plan.span,
        detail: `${(payload.processed_rows ?? payload.total_rows ?? 0).toLocaleString('es-CL')} filas listas para importar.`,
    });

    return payload;
}

async function prepareTemplateImport(uploadId, progress) {
    const formData = new FormData();
    formData.append('upload_id', uploadId);
    formData.append('_token', csrfToken);

    return prepareImportUntilFinished(
        prepareTemplateImportUrl,
        {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        },
        progress,
        {
            step: 2,
            totalSteps: 3,
            stage: 'Convirtiendo Excel a CSV',
            start: 12,
            span: 26,
        },
    );
}

async function startBackgroundImport(uploadId, mode = 'template', mapping = null) {
    const body = { upload_id: uploadId, mode };
    if (mapping) {
        body.mapping = mapping;
    }

    const response = await fetch(startBackgroundImportUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const payload = await readJsonResponse(response);
    if (!response.ok) {
        throw new Error(importErrorMessage(payload, response.status));
    }

    return payload;
}

async function pollBackgroundImport(uploadId, progress, plan) {
    const pollPlan = plan || { step: 2, totalSteps: 3, start: 12, span: 88 };
    let transientFailures = 0;
    const maxTransientFailures = 30;

    if (window.CotizRenderKeepAlive) {
        window.CotizRenderKeepAlive.start();
    }

    try {
    while (true) {
        await new Promise(resolve => setTimeout(resolve, 2000));

        let response;
        let payload;

        try {
            response = await fetch(`${importProgressUrl}?upload_id=${encodeURIComponent(uploadId)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            payload = await readJsonResponse(response);
        } catch (error) {
            transientFailures++;
            if (transientFailures >= maxTransientFailures) {
                throw new Error('No se pudo consultar el progreso. La importación puede seguir en segundo plano: recargue la página para retomar.');
            }

            setImportProgress(progress, {
                step: pollPlan.totalSteps >= 3 ? 3 : 2,
                totalSteps: pollPlan.totalSteps,
                stage: pollPlan.totalSteps >= 3 ? 'Grabando en base de datos' : 'Procesando en segundo plano',
                percent: Math.max(0, progress.bar?.getAttribute('aria-valuenow') || pollPlan.start),
                detail: `Conexión interrumpida, reintentando (${transientFailures}/${maxTransientFailures})...`,
            });
            continue;
        }

        if (!response.ok) {
            if (isTransientHttpStatus(response.status) && transientFailures < maxTransientFailures) {
                transientFailures++;
                setImportProgress(progress, {
                    step: pollPlan.totalSteps >= 3 ? 3 : 2,
                    totalSteps: pollPlan.totalSteps,
                    stage: pollPlan.totalSteps >= 3 ? 'Grabando en base de datos' : 'Procesando en segundo plano',
                    percent: Math.max(0, Number(progress.bar?.getAttribute('aria-valuenow') || pollPlan.start)),
                    detail: `Servidor ocupado (${response.status}), reintentando progreso (${transientFailures}/${maxTransientFailures})...`,
                });
                continue;
            }

            throw new Error(importErrorMessage(payload, response.status));
        }

        transientFailures = 0;

        applyPollProgressPayload(payload, progress, pollPlan);

        if (payload.phase === 'completed') {
            setImportProgress(progress, {
                step: pollPlan.totalSteps,
                totalSteps: pollPlan.totalSteps,
                stage: 'Completado',
                percent: 100,
                detail: 'Importación finalizada. Redirigiendo...',
            });

            if (payload.redirect) {
                window.location.href = payload.redirect;
            }

            setImportLocked(false);
            return payload;
        }

        if (payload.phase === 'failed') {
            throw new Error(payload.error || payload.detail || 'La importación en segundo plano falló.');
        }
    }
    } finally {
        if (window.CotizRenderKeepAlive) {
            window.CotizRenderKeepAlive.stop();
        }
    }
}

async function processImportAll(uploadId, progress, options = {}) {
    const pendingParse = options.pendingParse === true;
    const streamMode = options.streamMode === true;
    const totalSteps = pendingParse ? 3 : 2;
    let totalRows = options.totalRows ?? null;
    let batchCount = options.batchCount ?? 1;

    if (BACKGROUND_IMPORT_ENABLED) {
        await startBackgroundImport(uploadId, 'template');
        await pollBackgroundImport(uploadId, progress, {
            step: pendingParse ? 2 : 2,
            totalSteps,
            start: pendingParse ? 12 : 12,
            span: 88,
        });
        return;
    }

    if (pendingParse) {
        const prepared = await prepareTemplateImport(uploadId, progress);
        batchCount = prepared.batch_count ?? estimateImportBatchCount(prepared.total_rows);
        totalRows = prepared.total_rows ?? totalRows;
        options.streamMode = prepared.stream_mode === true || streamMode;
    } else if (streamMode && totalRows > 0 && batchCount <= 1) {
        batchCount = estimateImportBatchCount(totalRows);
    }

    await processImportBatches(uploadId, batchCount, progress, {
        step: pendingParse ? 3 : 2,
        totalSteps,
        stage: 'Grabando en base de datos',
        start: pendingParse ? 38 : 12,
        span: pendingParse ? 62 : 88,
    }, {
        streamMode: options.streamMode === true,
        totalRows,
    });
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

function showImportProgressImmediate(progress, detail = 'Preparando importación...', totalSteps = 3) {
    window.PageLoader?.hide();
    progress.wrap.classList.remove('d-none');
    setImportProgress(progress, {
        step: 1,
        totalSteps,
        stage: 'Iniciando',
        percent: 0,
        detail,
    });
}

document.getElementById('importFormTemplate').addEventListener('submit', async (event) => {
    event.preventDefault();
    const fileInput = document.getElementById('importFileTemplate');
    const submitBtn = document.getElementById('importSubmitBtnTemplate');
    const progress = buildProgressRefs('Template');
    const errorBox = document.getElementById('importErrorTemplate');
    const file = fileInput.files[0];
    if (!file) return;

    if (!isAllowedImportFile(file.name)) {
        errorBox.textContent = 'Solo se permiten archivos CSV o Excel (.xlsx, .xls).';
        errorBox.classList.remove('d-none');
        return;
    }

    if (file.size > MAX_IMPORT_BYTES) {
        errorBox.textContent = 'El archivo supera el máximo de 50 MB. Exporte a CSV o divida el archivo.';
        errorBox.classList.remove('d-none');
        return;
    }

    errorBox.classList.add('d-none');
    submitBtn.disabled = true;
    fileInput.disabled = true;
    showImportProgressImmediate(progress);

    const activeLock = await refreshImportStatus();
    if (activeLock) {
        errorBox.textContent = 'Hay una importación en curso.';
        errorBox.classList.remove('d-none');
        fileInput.disabled = false;
        submitBtn.disabled = false;
        progress.wrap.classList.add('d-none');
        return;
    }

    setImportLocked(true, 'Su importación está en curso. No cierre esta ventana.');

    setImportProgress(progress, {
        step: 1,
        totalSteps: 2,
        stage: 'Subiendo archivo',
        percent: 0,
        detail: 'Iniciando subida fragmentada...',
    });

    try {
        const uploadPlan = {
            step: 1,
            totalSteps: 2,
            stage: 'Subiendo archivo',
            start: 0,
            span: 12,
        };
        const normalizedFile = await normalizeImportFile(file, progress, uploadPlan);
        const willParseOnServer = ['xlsx', 'xls'].includes(fileExtension(file.name))
            && fileExtension(normalizedFile.name) !== 'csv';
        const totalSteps = willParseOnServer ? 3 : 2;

        const payload = await uploadCsvChunks(normalizedFile, 'template', progress, {
            step: 1,
            totalSteps,
            stage: 'Subiendo archivo',
            start: 0,
            span: willParseOnServer ? 12 : 12,
        });

        await processImportAll(payload.upload_id, progress, {
            pendingParse: payload.pending_parse === true,
            streamMode: payload.stream_mode === true,
            totalRows: payload.total_rows ?? null,
            batchCount: payload.batch_count ?? estimateImportBatchCount(payload.total_rows),
        });
    } catch (error) {
        await releaseImportLock();
        errorBox.textContent = error.message || 'Error inesperado.';
        errorBox.classList.remove('d-none');
        await refreshImportStatus();
        setImportLocked(false);
        fileInput.disabled = false;
        submitBtn.disabled = false;
        progress.wrap.classList.add('d-none');
    }
});

document.getElementById('importFormCustom').addEventListener('submit', async (event) => {
    event.preventDefault();
    const fileInput = document.getElementById('importFileCustom');
    const submitBtn = document.getElementById('importSubmitBtnCustom');
    const progress = buildProgressRefs('Custom');
    const errorBox = document.getElementById('importErrorCustom');
    const file = fileInput.files[0];
    if (!file) return;

    if (!isAllowedImportFile(file.name)) {
        errorBox.textContent = 'Solo se permiten archivos CSV o Excel (.xlsx, .xls).';
        errorBox.classList.remove('d-none');
        return;
    }

    submitBtn.disabled = true;
    fileInput.disabled = true;
    errorBox.classList.add('d-none');
    showImportProgressImmediate(progress, 'Iniciando subida fragmentada...', 2);

    document.getElementById('customPreviewCard').classList.add('d-none');
    document.getElementById('customMappingError').classList.add('d-none');

    try {
        const payload = await uploadCsvChunks(file, 'custom', progress, {
            step: 1,
            totalSteps: 2,
            stage: 'Subiendo archivo',
            start: 0,
            span: 80,
        });
        customUploadId = payload.upload_id;

        let staging = payload;
        if (payload.pending_parse) {
            setImportProgress(progress, {
                step: 2,
                totalSteps: 2,
                stage: 'Leyendo columnas',
                percent: 82,
                detail: 'Analizando encabezados del archivo Excel...',
            });

            const initResponse = await fetch(initializeImportUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ upload_id: customUploadId }),
            });
            const initPayload = await readJsonResponse(initResponse);
            if (!initResponse.ok) throw new Error(importErrorMessage(initPayload, initResponse.status));
            staging = initPayload;
        }

        customColumns = staging.columns || [];

        document.getElementById('customTotalRows').textContent = staging.total_rows || 0;
        populateMappingSelects(customColumns, staging.suggested_mapping || {});
        document.getElementById('customMappingCard').classList.remove('d-none');

        setImportProgress(progress, {
            step: 2,
            totalSteps: 2,
            stage: 'Listo para mapear',
            percent: 100,
            detail: `Archivo recibido: ${(staging.total_rows || 0).toLocaleString('es-CL')} filas, ${customColumns.length} columnas detectadas.`,
        });
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

        const payload = await readJsonResponse(response);
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

    const progress = buildProgressRefs('Custom');
    showImportProgressImmediate(progress, 'Validando mapeo de columnas...', 2);

    try {
        const mapping = getCustomMapping();

        if (BACKGROUND_IMPORT_ENABLED) {
            await startBackgroundImport(customUploadId, 'custom', mapping);
            await pollBackgroundImport(customUploadId, progress, {
                step: 1,
                totalSteps: 2,
                start: 0,
                span: 100,
            });
            return;
        }

        const preparePayload = await prepareImportUntilFinished(
            prepareImportUrl,
            {
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
                    mapping,
                }),
            },
            progress,
            {
                step: 1,
                totalSteps: 2,
                stage: 'Preparando importación',
                start: 0,
                span: 35,
            },
        );

        await processImportBatches(preparePayload.upload_id, preparePayload.batch_count, progress, {
            step: 2,
            totalSteps: 2,
            stage: 'Grabando en base de datos',
            start: 35,
            span: 65,
        }, {
            streamMode: preparePayload.stream_mode === true,
            totalRows: preparePayload.total_rows ?? null,
        });
    } catch (error) {
        errorBox.textContent = error.message || 'Error al importar.';
        errorBox.classList.remove('d-none');
        await refreshImportStatus();
        btn.disabled = false;
    }
});

document.getElementById('importUnlockBtn')?.addEventListener('click', async () => {
    const btn = document.getElementById('importUnlockBtn');
    if (btn) btn.disabled = true;

    try {
        await releaseImportLock();
        await refreshImportStatus();
        setImportLocked(false);
    } finally {
        if (btn) btn.disabled = false;
    }
});

refreshImportStatus().then(lock => {
    if (lock) {
        resumeActiveImport(lock);
    }
});
</script>
@endpush
