@extends('layouts.admin')

@section('title', $producto ? 'Editar producto' : 'Nuevo producto')

@section('content')
@php
    $esNuevo = ! $producto;
    $puedeEditarSoftland = $puedeEditarSoftland ?? auth()->user()?->isSuperAdmin();
    $listadoQuery = $listadoQuery ?? [];
    $action = $esNuevo
        ? route('admin.productos.store')
        : route('admin.productos.update', $producto->prod_item);
@endphp

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h4 mb-0">{{ $esNuevo ? 'Nuevo producto' : 'Editar producto' }}</h1>
        @if(auth()->user()->isSuperAdmin() || auth()->user()->isEjecutivo())
            <a href="{{ route('admin.productos.index', $listadoQuery) }}" class="btn btn-outline-secondary btn-sm">&larr; Listado</a>
        @else
            <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Cotizaciones</a>
        @endif
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="post" action="{{ $action }}" enctype="multipart/form-data">
                        @csrf
                        @if(! $esNuevo) @method('PUT') @endif
                        @foreach($listadoQuery as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">C&oacute;digo <span class="text-danger">*</span></label>
                                @if($esNuevo)
                                    <input type="text" name="prod_item" id="prod_item" class="form-control form-control-sm @error('prod_item') is-invalid @enderror"
                                           value="{{ old('prod_item') }}" maxlength="50" required autofocus>
                                    @error('prod_item')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                @else
                                    <input type="text" class="form-control form-control-sm" value="{{ $producto->prod_item }}" readonly>
                                @endif
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Descripci&oacute;n <span class="text-danger">*</span></label>
                                <input type="text" name="prod_nombre" class="form-control form-control-sm @error('prod_nombre') is-invalid @enderror"
                                       value="{{ old('prod_nombre', $producto?->prod_nombre) }}" maxlength="255" required>
                                @error('prod_nombre')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Familia @if($esNuevo)<span class="text-danger">*</span>@endif</label>
                                @php
                                    $familiaActual = old('prod_familia', $producto?->prod_familia);
                                    $codigosFamilia = $familias->pluck('codigo');
                                @endphp
                                <select name="prod_familia" id="prod_familia"
                                        class="form-select form-select-sm @error('prod_familia') is-invalid @enderror"
                                        @if($esNuevo) required @endif>
                                    <option value="">— Seleccione —</option>
                                    @foreach($familias as $fam)
                                        <option value="{{ $fam->codigo }}" @selected($familiaActual === $fam->codigo)>
                                            {{ $fam->nombre ?: $fam->codigo }}
                                        </option>
                                    @endforeach
                                    @if($familiaActual && ! $codigosFamilia->contains($familiaActual))
                                        <option value="{{ $familiaActual }}" selected>{{ $familiaActual }}</option>
                                    @endif
                                </select>
                                @error('prod_familia')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gramaje @if($gramajes->isNotEmpty() && $esNuevo)<span class="text-danger">*</span>@endif</label>
                                @php
                                    $gramajeActual = old('prod_gramaje', $producto?->prod_gramaje);
                                    $nombresGramaje = $gramajes->pluck('nombre');
                                @endphp
                                @if($gramajes->isNotEmpty())
                                    <select name="prod_gramaje" id="prod_gramaje"
                                            class="form-select form-select-sm @error('prod_gramaje') is-invalid @enderror"
                                            @if($esNuevo) required @endif>
                                        <option value="">— Seleccione —</option>
                                        @foreach($gramajes as $gramaje)
                                            <option value="{{ $gramaje->nombre }}" @selected($gramajeActual === $gramaje->nombre)>
                                                {{ $gramaje->nombre }}
                                            </option>
                                        @endforeach
                                        @if($gramajeActual && ! $nombresGramaje->contains($gramajeActual))
                                            <option value="{{ $gramajeActual }}" selected>{{ $gramajeActual }}</option>
                                        @endif
                                    </select>
                                @else
                                    <input type="text" name="prod_gramaje" id="prod_gramaje" class="form-control form-control-sm @error('prod_gramaje') is-invalid @enderror"
                                           value="{{ $gramajeActual }}" maxlength="120">
                                @endif
                                @error('prod_gramaje')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Archivo imagen</label>
                                <input type="text" name="prod_imagen" id="prod_imagen" class="form-control form-control-sm"
                                       value="{{ old('prod_imagen', $producto?->prod_imagen) }}" maxlength="255"
                                       placeholder="ej. 73027.jpg">
                                <div class="form-text">Por defecto el c&oacute;digo; al subir archivo se agrega su extensi&oacute;n.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Subir imagen (R2)</label>
                                <input type="file" name="imagen" id="imagen" accept="image/jpeg,image/png,image/webp,image/gif"
                                       class="form-control form-control-sm @error('imagen') is-invalid @enderror">
                                @error('imagen')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                @if(empty($storageImagenConfigurado))
                                    <div class="form-text text-warning">R2 no configurado: complete credenciales y R2_PUBLIC_URL en .env.</div>
                                @else
                                    <div class="form-text">Se guarda en {{ config('products.r2_prefix') }}/familia/c&oacute;digo.jpg. Al subir, se ajusta a {{ config('products.image_listing_size') }}&times;{{ config('products.image_listing_size') }} px para listados.</div>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Precio venta <span class="text-danger">*</span></label>
                                <input type="number" name="prod_valor" class="form-control form-control-sm @error('prod_valor') is-invalid @enderror"
                                       value="{{ old('prod_valor', $producto?->prod_valor ?? 0) }}" min="0" required>
                                @error('prod_valor')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Precio costo</label>
                                <input type="number" name="prod_valor_costo" class="form-control form-control-sm"
                                       value="{{ old('prod_valor_costo', $producto?->prod_valor_costo ?? 0) }}" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Peso (kg)</label>
                                <input type="number" name="peso_kg" id="peso_kg" min="0" step="0.001"
                                       class="form-control form-control-sm @error('peso_kg') is-invalid @enderror"
                                       value="{{ old('peso_kg', isset($producto) && $producto->peso_kg !== null ? $producto->peso_kg : '') }}"
                                       placeholder="Opcional">
                                <div class="form-text">Opcional. En cotizaci&oacute;n DEX: peso &times; cantidad.</div>
                                @error('peso_kg')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Stock real</label>
                                <input type="number" name="prod_stock_real" class="form-control form-control-sm"
                                       value="{{ old('prod_stock_real', $producto?->prod_stock_real) }}" min="0">
                            </div>
                            @if($puedeEditarSoftland)
                            <div class="col-md-4">
                                <label class="form-label">C&oacute;d. Softland</label>
                                <input type="text" name="prod_item_softland" class="form-control form-control-sm"
                                       value="{{ old('prod_item_softland', $producto?->prod_item_softland) }}" maxlength="50">
                            </div>
                            @endif
                            @if($producto)
                            <div class="col-12">
                                <p class="small mb-0">
                                    <code class="admin-image-url">{{ $producto->buildExternalImageUrl() ?: '—' }}</code>
                                </p>
                            </div>
                        @endif
                        @if($producto?->prod_valor_fecha)
                                <div class="col-12">
                                    <p class="small text-muted mb-0">
                                        &Uacute;ltima act. precio: {{ $producto->prod_valor_fecha->format('d/m/Y H:i') }}
                                        @if($producto->prod_user_upd) — {{ $producto->prod_user_upd }} @endif
                                    </p>
                                </div>
                            @endif
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @if($producto)
            <div class="col-lg-4">
                <div class="card shadow-sm mb-3">
                    <div class="card-header py-2 small fw-semibold">Vista previa</div>
                    <div class="card-body text-center">
                        <x-product-image :maeprod="$producto" variant="admin-preview" />
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header py-2 small fw-semibold">Frases para vincular (Agile)</div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">
                            Si la descripci&oacute;n de Compra &Aacute;gil <strong>contiene</strong> alguna de estas frases,
                            se usa este producto (prioridad sobre el aprendizaje autom&aacute;tico).
                            Cada frase solo puede estar en un producto.
                        </p>

                        <form method="post" action="{{ route('admin.productos.frases.store', $producto->prod_item) }}" class="mb-3">
                            @csrf
                            @foreach($listadoQuery as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <label class="form-label small mb-1" for="frase">Nueva frase</label>
                            <div class="input-group input-group-sm">
                                <input type="text" name="frase" id="frase"
                                       class="form-control @error('frase') is-invalid @enderror"
                                       maxlength="200" required
                                       placeholder="Ej: lapiz azul"
                                       value="{{ old('frase') }}">
                                <button type="submit" class="btn btn-primary">Agregar</button>
                            </div>
                            @error('frase')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </form>

                        @if($producto->frases->isEmpty())
                            <p class="small text-muted mb-0">Sin frases a&uacute;n.</p>
                        @else
                            <ul class="list-group list-group-flush">
                                @foreach($producto->frases as $fraseItem)
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                        <span class="small">{{ $fraseItem->frase }}</span>
                                        <form method="post"
                                              action="{{ route('admin.productos.frases.destroy', [$producto->prod_item, $fraseItem]) }}"
                                              onsubmit="return confirm('¿Eliminar esta frase?');">
                                            @csrf
                                            @method('DELETE')
                                            @foreach($listadoQuery as $key => $value)
                                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                            @endforeach
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Eliminar">
                                                &times;
                                            </button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/product-image.js') }}" defer></script>
<script>
(function () {
    var codigoInput = document.getElementById('prod_item');
    var imagenInput = document.getElementById('imagen');
    var nombreInput = document.getElementById('prod_imagen');
    if (!nombreInput) return;

    var autoNombre = true;

    nombreInput.addEventListener('input', function () {
        autoNombre = nombreInput.value.trim() === '';
    });

    function codigoProducto() {
        if (codigoInput) {
            return codigoInput.value.trim();
        }
        return @json($producto?->prod_item ?? '');
    }

    function extensionArchivo(file) {
        if (!file || !file.name) return null;
        var partes = file.name.split('.');
        if (partes.length < 2) return null;
        var ext = partes.pop().toLowerCase();
        if (ext === 'jpeg') ext = 'jpg';
        if (['jpg', 'png', 'webp', 'gif'].indexOf(ext) === -1) return null;
        return ext;
    }

    function actualizarNombreImagen() {
        if (!autoNombre) return;
        var codigo = codigoProducto();
        if (!codigo) return;
        var file = imagenInput && imagenInput.files && imagenInput.files[0] ? imagenInput.files[0] : null;
        var ext = file && extensionArchivo(file) ? 'jpg' : null;
        nombreInput.value = ext ? codigo + '.' + ext : codigo;
    }

    if (codigoInput) {
        codigoInput.addEventListener('input', actualizarNombreImagen);
    }
    if (imagenInput) {
        imagenInput.addEventListener('change', function () {
            autoNombre = true;
            actualizarNombreImagen();
        });
        imagenInput.addEventListener('input', function () {
            if (!imagenInput.files || !imagenInput.files[0]) {
                actualizarNombreImagen();
            }
        });
    }
})();
</script>
@endpush
