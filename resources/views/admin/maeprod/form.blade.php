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
                            Si la descripci&oacute;n de Compra &Aacute;gil incluye <strong>todas las palabras</strong> de la frase
                            (en cualquier orden y aunque haya texto en medio), se usa este producto
                            (prioridad sobre el aprendizaje autom&aacute;tico).
                            Ejemplo: frase <em>adhesivo barra</em> vincula
                            <em>adhesivo en barra 21 ml</em>.
                            Preferir frases de <strong>2 o m&aacute;s palabras</strong> para evitar falsos positivos.
                            Cada frase solo puede estar en un producto.
                            Al agregar o eliminar se sincroniza con el sitio par (Romulo ↔ Reicol) si la API está configurada.
                        </p>

                        <form id="maeprod-frase-form"
                              method="post"
                              action="{{ route('admin.productos.frases.store', $producto->prod_item) }}"
                              class="mb-3"
                              data-no-loader>
                            @csrf
                            <label class="form-label small mb-1" for="frase">Nueva frase</label>
                            <div class="input-group input-group-sm">
                                <input type="text" name="frase" id="frase"
                                       class="form-control"
                                       maxlength="200" required
                                       placeholder="Ej: lapiz azul"
                                       autocomplete="off">
                                <button type="submit" id="maeprod-frase-submit" class="btn btn-primary">Agregar</button>
                            </div>
                            <div id="maeprod-frase-error" class="invalid-feedback d-none"></div>
                        </form>

                        <p id="maeprod-frases-empty" class="small text-muted mb-0 @if($producto->frases->isNotEmpty()) d-none @endif">
                            Sin frases a&uacute;n.
                        </p>
                        <ul id="maeprod-frases-list" class="list-group list-group-flush @if($producto->frases->isEmpty()) d-none @endif">
                            @foreach($producto->frases as $fraseItem)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2"
                                    data-frase-id="{{ $fraseItem->id }}">
                                    <span class="small">{{ $fraseItem->frase }}</span>
                                    <button type="button"
                                            class="btn btn-outline-danger btn-sm py-0 px-2 maeprod-frase-delete"
                                            title="Eliminar"
                                            data-url="{{ route('admin.productos.frases.destroy', ['prod_item' => $producto->prod_item, 'frase' => $fraseItem->id]) }}">
                                        &times;
                                    </button>
                                </li>
                            @endforeach
                        </ul>
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

    var autoNombre = true;

    if (nombreInput) {
        nombreInput.addEventListener('input', function () {
            autoNombre = nombreInput.value.trim() === '';
        });
    }

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
        if (!nombreInput || !autoNombre) return;
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

    var fraseForm = document.getElementById('maeprod-frase-form');
    if (!fraseForm) return;

    var fraseInput = document.getElementById('frase');
    var fraseSubmit = document.getElementById('maeprod-frase-submit');
    var fraseError = document.getElementById('maeprod-frase-error');
    var frasesList = document.getElementById('maeprod-frases-list');
    var frasesEmpty = document.getElementById('maeprod-frases-empty');
    var csrfToken = fraseForm.querySelector('input[name="_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || '';

    function csrfHeaders() {
        return {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        };
    }

    function showFraseError(msg) {
        if (!fraseError) return;
        fraseError.textContent = msg || '';
        fraseError.classList.toggle('d-none', !msg);
        fraseError.classList.toggle('d-block', !!msg);
        if (fraseInput) {
            fraseInput.classList.toggle('is-invalid', !!msg);
        }
    }

    function syncFrasesEmptyState() {
        var hasItems = frasesList && frasesList.querySelectorAll('[data-frase-id]').length > 0;
        if (frasesList) {
            frasesList.classList.toggle('d-none', !hasItems);
        }
        if (frasesEmpty) {
            frasesEmpty.classList.toggle('d-none', hasItems);
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function appendFraseItem(frase) {
        if (!frasesList || !frase) return;
        var li = document.createElement('li');
        li.className = 'list-group-item px-0 d-flex justify-content-between align-items-center gap-2';
        li.setAttribute('data-frase-id', String(frase.id));
        li.innerHTML =
            '<span class="small">' + escapeHtml(frase.frase) + '</span>' +
            '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 maeprod-frase-delete" title="Eliminar" data-url="' +
            escapeHtml(frase.destroy_url) + '">&times;</button>';
        frasesList.appendChild(li);
        syncFrasesEmptyState();
    }

    function removeFraseItem(id) {
        if (!frasesList) return;
        var li = frasesList.querySelector('[data-frase-id="' + id + '"]');
        if (li) {
            li.remove();
        }
        syncFrasesEmptyState();
    }

    async function parseJsonResponse(res) {
        try {
            return await res.json();
        } catch (e) {
            return null;
        }
    }

    fraseForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        showFraseError('');

        var valor = (fraseInput && fraseInput.value ? fraseInput.value : '').trim();
        if (valor.length < 2) {
            showFraseError('La frase debe tener al menos 2 caracteres.');
            return;
        }

        if (fraseSubmit) {
            fraseSubmit.disabled = true;
            fraseSubmit.textContent = 'Agregando…';
        }

        var body = new FormData(fraseForm);
        if (fraseInput) {
            fraseInput.readOnly = true;
        }

        try {
            var res = await fetch(fraseForm.action, {
                method: 'POST',
                headers: csrfHeaders(),
                body: body,
                credentials: 'same-origin',
            });
            var data = await parseJsonResponse(res);

            if (data && data.producto_eliminado && data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            if (!res.ok) {
                var msg = (data && (data.message || (data.errors && data.errors.frase && data.errors.frase[0]))) ||
                    'No se pudo agregar la frase.';
                showFraseError(msg);
                return;
            }

            if (data && data.frase) {
                appendFraseItem(data.frase);
            }
            if (fraseInput) {
                fraseInput.value = '';
                fraseInput.focus();
            }
            if (data && data.sync_error) {
                showFraseError(data.sync_error);
            } else {
                showFraseError('');
            }
        } catch (e) {
            showFraseError('Error de red al agregar la frase.');
        } finally {
            if (fraseSubmit) {
                fraseSubmit.disabled = false;
                fraseSubmit.textContent = 'Agregar';
            }
            if (fraseInput) {
                fraseInput.readOnly = false;
            }
        }
    });

    document.addEventListener('click', async function (event) {
        var btn = event.target.closest('.maeprod-frase-delete');
        if (!btn || !frasesList || !frasesList.contains(btn)) return;

        var url = btn.getAttribute('data-url');
        if (!url) return;
        if (!window.confirm('¿Eliminar esta frase?')) return;

        var li = btn.closest('[data-frase-id]');
        var fraseId = li ? li.getAttribute('data-frase-id') : null;
        btn.disabled = true;

        try {
            var res = await fetch(url, {
                method: 'POST',
                headers: Object.assign({
                    'Content-Type': 'application/json',
                }, csrfHeaders()),
                body: JSON.stringify({}),
                credentials: 'same-origin',
            });
            var data = await parseJsonResponse(res);

            if (data && data.producto_eliminado && data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            if (!res.ok) {
                window.alert((data && data.message) || 'No se pudo eliminar la frase.');
                btn.disabled = false;
                return;
            }

            if (fraseId) {
                removeFraseItem(fraseId);
            }
        } catch (e) {
            window.alert('Error de red al eliminar la frase.');
            btn.disabled = false;
        }
    });
})();
</script>
@endpush
