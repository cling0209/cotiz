@extends('layouts.admin')

@section('title', 'Colores del sitio')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Colores del sitio</h1>
            <p class="text-muted mb-0">
                Personaliza la apariencia de este despliegue. Deje un campo vacío para usar el color predeterminado.
            </p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="card admin-card">
                <div class="card-header bg-white fw-semibold">Configuración</div>
                <div class="card-body">
                    <form method="post" action="{{ route('admin.colores.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label" for="theme_primary">Color primario</label>
                            <div class="input-group">
                                <input type="color"
                                       class="form-control form-control-color @error('theme_primary') is-invalid @enderror"
                                       id="theme_primary_picker"
                                       value="{{ old('theme_primary', $stored['theme_primary'] ?? $defaults['theme_primary']) }}"
                                       title="Selector de color primario">
                                <input type="text"
                                       name="theme_primary"
                                       id="theme_primary"
                                       class="form-control @error('theme_primary') is-invalid @enderror"
                                       value="{{ old('theme_primary', $stored['theme_primary']) }}"
                                       placeholder="{{ $defaults['theme_primary'] }}"
                                       pattern="^#[0-9A-Fa-f]{6}$"
                                       maxlength="7"
                                       autocomplete="off">
                            </div>
                            @error('theme_primary')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <div class="form-text">Navbar, botones primarios y favicon. Default: {{ $defaults['theme_primary'] }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="theme_primary_hover">Color primario (hover)</label>
                            <div class="input-group">
                                <input type="color"
                                       class="form-control form-control-color @error('theme_primary_hover') is-invalid @enderror"
                                       id="theme_primary_hover_picker"
                                       value="{{ old('theme_primary_hover', $stored['theme_primary_hover'] ?? $defaults['theme_primary_hover']) }}"
                                       title="Selector hover">
                                <input type="text"
                                       name="theme_primary_hover"
                                       id="theme_primary_hover"
                                       class="form-control @error('theme_primary_hover') is-invalid @enderror"
                                       value="{{ old('theme_primary_hover', $stored['theme_primary_hover']) }}"
                                       placeholder="{{ $defaults['theme_primary_hover'] }}"
                                       pattern="^#[0-9A-Fa-f]{6}$"
                                       maxlength="7"
                                       autocomplete="off">
                            </div>
                            @error('theme_primary_hover')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <div class="form-text">Default: {{ $defaults['theme_primary_hover'] }}</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="theme_accent">Color acento</label>
                            <div class="input-group">
                                <input type="color"
                                       class="form-control form-control-color @error('theme_accent') is-invalid @enderror"
                                       id="theme_accent_picker"
                                       value="{{ old('theme_accent', $stored['theme_accent'] ?? $defaults['theme_accent']) }}"
                                       title="Selector acento">
                                <input type="text"
                                       name="theme_accent"
                                       id="theme_accent"
                                       class="form-control @error('theme_accent') is-invalid @enderror"
                                       value="{{ old('theme_accent', $stored['theme_accent']) }}"
                                       placeholder="{{ $defaults['theme_accent'] }}"
                                       pattern="^#[0-9A-Fa-f]{6}$"
                                       maxlength="7"
                                       autocomplete="off">
                            </div>
                            @error('theme_accent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <div class="form-text">Gradientes e icono del favicon. Default: {{ $defaults['theme_accent'] }}</div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Guardar
                            </button>
                        </div>
                    </form>

                    <form method="post" action="{{ route('admin.colores.reset') }}" class="mt-3"
                          onsubmit="return confirm('¿Restaurar los colores predeterminados?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise"></i> Restaurar predeterminados
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card admin-card">
                <div class="card-header bg-white fw-semibold">Vista previa</div>
                <div class="card-body">
                    <div id="theme-preview-navbar" class="rounded-3 px-3 py-2 mb-3 text-white fw-semibold"
                         style="background: linear-gradient(90deg, {{ $resolved['theme_primary'] }} 0%, {{ $resolved['theme_accent'] }} 100%);">
                        {{ config('app.name', 'Cotiz') }}
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" id="theme-preview-btn-primary" class="btn btn-primary btn-sm">Botón primario</button>
                        <button type="button" class="btn btn-outline-primary btn-sm">Outline</button>
                        <span id="theme-preview-badge" class="badge text-bg-primary">Badge</span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <img src="{{ $faviconUrl }}" alt="" width="40" height="40" class="rounded theme-favicon-preview">
                        <span class="text-muted small">Favicon (pestaña del navegador)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const pairs = [
        ['theme_primary', 'theme_primary_picker'],
        ['theme_primary_hover', 'theme_primary_hover_picker'],
        ['theme_accent', 'theme_accent_picker'],
    ];

    const defaults = @json($defaults);

    function effectiveHex(textId) {
        const input = document.getElementById(textId);
        const value = (input?.value || '').trim();
        if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
            return value;
        }
        return defaults[textId] || '#2563eb';
    }

    function syncPickerToText(textId, pickerId) {
        const text = document.getElementById(textId);
        const picker = document.getElementById(pickerId);
        if (!text || !picker) return;
        picker.addEventListener('input', () => {
            text.value = picker.value;
            updatePreview();
        });
        text.addEventListener('input', () => {
            if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) {
                picker.value = text.value;
            }
            updatePreview();
        });
    }

    function updatePreview() {
        const primary = effectiveHex('theme_primary');
        const accent = effectiveHex('theme_accent');
        const navbar = document.getElementById('theme-preview-navbar');
        if (navbar) {
            navbar.style.background = `linear-gradient(90deg, ${primary} 0%, ${accent} 100%)`;
        }
    }

    pairs.forEach(([textId, pickerId]) => syncPickerToText(textId, pickerId));
})();
</script>
@endpush
