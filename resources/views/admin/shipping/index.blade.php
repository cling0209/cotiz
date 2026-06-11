@extends('layouts.admin')

@section('title', 'Envíos')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Configuración de envíos</h1>
            <p class="text-muted mb-0">
                RM: tarifa fija. Otras regiones: tarifa fija regional + adicional por tramo de peso según la comuna.
            </p>
        </div>
        <a href="{{ route('admin.shipping.import') }}" class="btn btn-outline-primary">
            <i class="bi bi-upload"></i> Carga masiva tramos
        </a>
    </div>

    @if(session('import_errors'))
        <div class="alert alert-warning">
            <strong>Errores en la importación:</strong>
            <ul class="mb-0 mt-2">
                @foreach(session('import_errors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="card admin-card">
                <div class="card-header bg-white fw-semibold">Configuración general</div>
                <div class="card-body">
                    <form method="post" action="{{ route('admin.shipping.settings') }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label class="form-label">Tarifa fija RM (CLP) *</label>
                            <input type="number" name="rm_flat_rate" min="0" step="1"
                                   class="form-control @error('rm_flat_rate') is-invalid @enderror"
                                   value="{{ old('rm_flat_rate', $rmFlatRate) }}" required>
                            @error('rm_flat_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Monto único para toda la Región Metropolitana.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Peso por defecto producto (kg) *</label>
                            <input type="number" name="default_product_weight_kg" min="0.001" step="0.001"
                                   class="form-control @error('default_product_weight_kg') is-invalid @enderror"
                                   value="{{ old('default_product_weight_kg', $defaultProductWeight) }}" required>
                            @error('default_product_weight_kg')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Si un producto no tiene peso definido.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adicional fijo sin tramo (CLP) *</label>
                            <input type="number" name="fallback_additional_clp" min="0" step="1"
                                   class="form-control @error('fallback_additional_clp') is-invalid @enderror"
                                   value="{{ old('fallback_additional_clp', $fallbackAdditional) }}" required>
                            @error('fallback_additional_clp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                Para todas las comunas fuera de RM: se suma a la tarifa regional cuando no aplica ningún tramo de peso.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Guardar configuración</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card admin-card">
                <div class="card-header bg-white fw-semibold">Tarifa fija por región (fuera de RM)</div>
                <div class="card-body border-bottom py-3 text-muted small">
                    Solo las regiones con <strong>Activa</strong> marcada participan en el cálculo de envío en checkout.
                    Para cada una activa: monto base de la región + tramo de peso de la comuna elegida al pagar.
                </div>
                <form method="post" action="{{ route('admin.shipping.regions') }}">
                    @csrf
                    @method('PUT')
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle admin-table">
                            <thead>
                                <tr>
                                    <th>Región</th>
                                    <th class="text-end" style="width: 180px">Tarifa fija (CLP)</th>
                                    <th class="text-center" style="width: 100px">Activa</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($regionRates as $regionRate)
                                    <tr>
                                        <td>{{ $regionRate->region }}</td>
                                        <td class="text-end">
                                            <input type="number" name="regions[{{ $regionRate->id }}][flat_rate]"
                                                   min="0" step="1" class="form-control form-control-sm text-end"
                                                   value="{{ old('regions.'.$regionRate->id.'.flat_rate', $regionRate->flat_rate) }}"
                                                   required>
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input"
                                                   name="regions[{{ $regionRate->id }}][is_active]" value="1"
                                                   @checked(old('regions.'.$regionRate->id.'.is_active', $regionRate->is_active))>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">
                                            No hay regiones configuradas.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($regionRates->isNotEmpty())
                        <div class="card-body border-top">
                            <button type="submit" class="btn btn-primary">Guardar tarifas regionales</button>
                        </div>
                    @endif
                </form>
            </div>
        </div>

        <div class="col-12" id="comuna-tramos">
            <div class="card admin-card">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-semibold">Tramos por peso por comuna</span>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.shipping.export') }}" class="btn btn-sm btn-outline-success" data-no-loader>
                            <i class="bi bi-download"></i> Exportar CSV
                        </a>
                        @if($selectedRegion && $selectedComuna)
                            <button type="button" class="btn btn-sm btn-primary" onclick="openRateModal()">
                                <i class="bi bi-plus-lg"></i> Nuevo tramo
                            </button>
                        @endif
                    </div>
                </div>
                <div class="card-body border-bottom">
                    <form method="get" action="{{ route('admin.shipping.index') }}#comuna-tramos" class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Región</label>
                            <select name="region" id="adminRegion" class="form-select" onchange="this.form.submit()">
                                @foreach($regionComunas as $regionName => $comunas)
                                    <option value="{{ $regionName }}" @selected($selectedRegion === $regionName)>
                                        {{ $regionName }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Comuna</label>
                            <select name="comuna" id="adminComuna" class="form-select" onchange="this.form.submit()">
                                @foreach($regionComunas[$selectedRegion] ?? [] as $comunaName)
                                    <option value="{{ $comunaName }}" @selected($selectedComuna === $comunaName)>
                                        {{ $comunaName }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                    @if($selectedRegion && $selectedComuna)
                        <p class="text-muted small mb-0 mt-3">
                            Tramos de peso para <strong>{{ $selectedComuna }}</strong>. El adicional se suma a la tarifa fija de la región.
                            Si no aplica ningún tramo activo, se usa el adicional fijo global ({{ clp($fallbackAdditional) }}).
                            Los tramos con <strong>Activo</strong> desmarcado no se toman en el cálculo.
                        </p>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle admin-table">
                        <thead>
                            <tr>
                                <th>Etiqueta</th>
                                <th>Rango (kg)</th>
                                <th class="text-end">Adicional (CLP)</th>
                                <th class="text-center">Orden</th>
                                <th class="text-center">Activo</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($comunaRates as $rate)
                                <tr>
                                    <td>{{ $rate->label }}</td>
                                    <td>
                                        {{ number_format($rate->min_weight_kg, 2, ',', '.') }}
                                        –
                                        @if($rate->max_weight_kg !== null)
                                            {{ number_format($rate->max_weight_kg, 2, ',', '.') }}
                                        @else
                                            ∞
                                        @endif
                                    </td>
                                    <td class="text-end">{{ clp($rate->price) }}</td>
                                    <td class="text-center">{{ $rate->sort_order }}</td>
                                    <td class="text-center">
                                        @if($rate->is_active)
                                            <span class="badge text-bg-success">Sí</span>
                                        @else
                                            <span class="badge text-bg-secondary">No</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick='openRateModal(@json($rate))'>
                                            Editar
                                        </button>
                                        <form method="post" action="{{ route('admin.shipping.rates.destroy', $rate) }}"
                                              class="d-inline" onsubmit="return confirm('¿Eliminar este tramo?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        @if($selectedComuna)
                                            No hay tramos para esta comuna.
                                        @else
                                            Selecciona región y comuna.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="rateForm" method="post" action="{{ route('admin.shipping.rates.store') }}">
                @csrf
                <input type="hidden" name="_method" id="rateFormMethod" value="POST">
                <input type="hidden" name="region" id="rateRegion" value="{{ $selectedRegion }}">
                <input type="hidden" name="comuna" id="rateComuna" value="{{ $selectedComuna }}">
                <div class="modal-header">
                    <h5 class="modal-title" id="rateModalTitle">Nuevo tramo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Peso mínimo (kg) *</label>
                            <input type="number" name="min_weight_kg" id="rateMin" min="0" step="0.001"
                                   class="form-control @error('min_weight_kg') is-invalid @enderror"
                                   value="{{ old('min_weight_kg', '0') }}" required>
                            @error('min_weight_kg')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label">Peso máximo (kg)</label>
                            <input type="number" name="max_weight_kg" id="rateMax" min="0" step="0.001"
                                   class="form-control @error('max_weight_kg') is-invalid @enderror"
                                   value="{{ old('max_weight_kg') }}" placeholder="Vacío = sin límite">
                            @error('max_weight_kg')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Vacío = desde el peso mínimo en adelante, sin límite superior.</div>
                        </div>
                        <div class="col-12">
                            <p class="text-muted small mb-0">
                                <span class="fw-semibold">Etiqueta (automática):</span>
                                <span id="rateLabelPreview" class="text-body">Hasta 0 kg</span>
                                — se genera según el rango de peso; no se edita manualmente.
                            </p>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Adicional (CLP) *</label>
                            <input type="number" name="price" id="ratePrice" min="0" step="1"
                                   class="form-control @error('price') is-invalid @enderror"
                                   value="{{ old('price') }}" required>
                            @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label">Orden</label>
                            <input type="number" name="sort_order" id="rateSort" min="0"
                                   class="form-control @error('sort_order') is-invalid @enderror"
                                   value="{{ old('sort_order', '0') }}">
                            @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Debe ser único entre los tramos de esta comuna.</div>
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="rateActive" value="1" checked>
                        <label class="form-check-label" for="rateActive">Tramo activo</label>
                        <div class="form-text">Si no está marcado, este tramo no se toma en el cálculo de envío.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar tramo</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (window.location.hash === '#comuna-tramos') {
        document.getElementById('comuna-tramos')?.scrollIntoView({ block: 'start' });
    }

    ['rateMin', 'rateMax'].forEach((id) => {
        document.getElementById(id)?.addEventListener('input', updateRateLabelPreview);
    });
});

const rateStoreUrl = @json(route('admin.shipping.rates.store'));
const rateUpdateUrlTemplate = @json(route('admin.shipping.rates.update', ['rate' => 0]));
const selectedRegion = @json($selectedRegion);
const selectedComuna = @json($selectedComuna);

function formatWeightForLabel(kg) {
    return Number(kg).toFixed(3).replace(/\.?0+$/, '');
}

function formatLabelFromWeight(minKg, maxKg) {
    const min = parseFloat(minKg);

    if (Number.isNaN(min)) {
        return '—';
    }

    const maxRaw = maxKg === '' || maxKg === null || maxKg === undefined ? null : parseFloat(maxKg);

    if (maxRaw === null || Number.isNaN(maxRaw)) {
        return min <= 0 ? 'Sin límite de peso' : `Más de ${formatWeightForLabel(min)} kg`;
    }

    if (min <= 0) {
        return `Hasta ${formatWeightForLabel(maxRaw)} kg`;
    }

    return `${formatWeightForLabel(min)} a ${formatWeightForLabel(maxRaw)} kg`;
}

function updateRateLabelPreview() {
    const preview = document.getElementById('rateLabelPreview');

    if (!preview) {
        return;
    }

    preview.textContent = formatLabelFromWeight(
        document.getElementById('rateMin')?.value ?? '',
        document.getElementById('rateMax')?.value ?? '',
    );
}

function openRateModal(rate = null) {
    const form = document.getElementById('rateForm');
    const method = document.getElementById('rateFormMethod');
    document.getElementById('rateModalTitle').textContent = rate ? 'Editar tramo' : 'Nuevo tramo';

    if (rate) {
        form.action = rateUpdateUrlTemplate.replace(/\/0$/, '/' + rate.id);
        method.value = 'PUT';
        document.getElementById('rateRegion').value = rate.region;
        document.getElementById('rateComuna').value = rate.comuna;
        document.getElementById('rateMin').value = rate.min_weight_kg;
        document.getElementById('rateMax').value = rate.max_weight_kg ?? '';
        document.getElementById('ratePrice').value = rate.price;
        document.getElementById('rateSort').value = rate.sort_order;
        document.getElementById('rateActive').checked = !!rate.is_active;
    } else {
        form.action = rateStoreUrl;
        method.value = 'POST';
        form.reset();
        document.getElementById('rateRegion').value = selectedRegion;
        document.getElementById('rateComuna').value = selectedComuna;
        document.getElementById('rateActive').checked = true;
        document.getElementById('rateSort').value = 0;
        document.getElementById('rateMin').value = 0;
        document.getElementById('rateMax').value = '';
    }

    updateRateLabelPreview();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rateModal')).show();
}

@if($errors->hasAny(['min_weight_kg', 'max_weight_kg', 'price', 'sort_order']))
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rateModal')).show();
    updateRateLabelPreview();
@endif
</script>
@endpush
