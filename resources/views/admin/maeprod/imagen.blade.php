@extends('layouts.admin')

@section('title', 'Imagen del producto')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-0">Actualizar imagen</h1>
            <p class="text-muted small mb-0">Solo puede modificar la imagen del producto.</p>
        </div>
        <a href="{{ route('admin.productos.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Listado</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success py-2 small">{{ session('success') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-sm-3 text-muted">C&oacute;digo</dt>
                        <dd class="col-sm-9"><code>{{ $producto->prod_item }}</code></dd>
                        <dt class="col-sm-3 text-muted">Descripci&oacute;n</dt>
                        <dd class="col-sm-9">{{ $producto->prod_nombre }}</dd>
                        <dt class="col-sm-3 text-muted">Familia</dt>
                        <dd class="col-sm-9">{{ $producto->prod_familia ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="post" action="{{ route('admin.productos.imagen.update', $producto->prod_item) }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Archivo imagen</label>
                                <input type="text" name="prod_imagen" id="prod_imagen" class="form-control form-control-sm"
                                       value="{{ old('prod_imagen', $producto->prod_imagen) }}" maxlength="255"
                                       placeholder="ej. {{ $producto->prod_item }}.jpg">
                                <div class="form-text">Por defecto el c&oacute;digo; al subir archivo se agrega su extensi&oacute;n.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Subir imagen (R2)</label>
                                <input type="file" name="imagen" id="imagen" accept="image/jpeg,image/png,image/webp,image/gif"
                                       class="form-control form-control-sm @error('imagen') is-invalid @enderror">
                                @error('imagen')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                @if(empty($storageImagenConfigurado))
                                    <div class="form-text text-warning">R2 no configurado: complete credenciales y R2_PUBLIC_URL en .env.</div>
                                @else
                                    <div class="form-text">Se guarda en {{ config('products.r2_prefix') }}/familia/c&oacute;digo.jpg. Al subir, se ajusta autom&aacute;ticamente a {{ config('products.image_listing_size') }}&times;{{ config('products.image_listing_size') }} px (fondo blanco) para verse bien en listados.</div>
                                @endif
                            </div>
                            <div class="col-12">
                                <p class="small text-muted mb-0">
                                    URL imagen:
                                    <code class="user-select-all">{{ $producto->buildExternalImageUrl() ?: '—' }}</code>
                                </p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary btn-sm">Guardar imagen</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header py-2 small fw-semibold">Vista previa</div>
                <div class="card-body text-center">
                    <x-product-image :maeprod="$producto" variant="admin-preview" />
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/product-image.js') }}" defer></script>
<script>
(function () {
    var imagenInput = document.getElementById('imagen');
    var nombreInput = document.getElementById('prod_imagen');
    if (!nombreInput) return;

    var codigo = @json($producto->prod_item);
    var autoNombre = nombreInput.value.trim() === '' || nombreInput.value.trim() === codigo;

    nombreInput.addEventListener('input', function () {
        autoNombre = nombreInput.value.trim() === '';
    });

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
        if (!autoNombre || !codigo) return;
        var file = imagenInput && imagenInput.files && imagenInput.files[0] ? imagenInput.files[0] : null;
        var ext = file && extensionArchivo(file) ? 'jpg' : null;
        nombreInput.value = ext ? codigo + '.' + ext : codigo;
    }

    if (imagenInput) {
        imagenInput.addEventListener('change', function () {
            autoNombre = true;
            actualizarNombreImagen();
        });
    }
})();
</script>
@endpush
