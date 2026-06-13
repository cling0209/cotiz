@extends('layouts.admin')

@section('title', 'Productos')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Mantenedor de productos</h1>
            <p class="text-muted mb-0 small">Cat&aacute;logo maestro (maeprod).</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.productos.import') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-upload"></i> Carga masiva
            </a>
            <a href="{{ route('admin.productos.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Nuevo producto
            </a>
        </div>
    </div>

    <x-maeprod-import-errors
        :errors="session('import_errors', [])"
        :total="session('import_errors_total')"
        :download-token="session('import_errors_token')"
    />

    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Buscar</label>
                    <input type="search" name="q" class="form-control form-control-sm" placeholder="C&oacute;digo, nombre o Softland..."
                           value="{{ $filtros['q'] }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Familia</label>
                    <select name="familia" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        @foreach($familias as $fam)
                            <option value="{{ $fam->codigo }}" @selected($filtros['familia'] === $fam->codigo)>
                                {{ $fam->nombre ?: $fam->codigo }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                    <a href="{{ route('admin.productos.index') }}" class="btn btn-link btn-sm">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:52px"></th>
                        <th>C&oacute;digo</th>
                        <th>Descripci&oacute;n</th>
                        <th>Familia</th>
                        <th class="text-end">Precio</th>
                        <th class="text-end">Costo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($productos as $producto)
                        @php $imageUrl = $producto->buildExternalImageUrl(); @endphp
                        <tr>
                            <td class="p-1">
                                @if($imageUrl)
                                    <button type="button"
                                            class="product-image-zoom-trigger"
                                            data-image-url="{{ $imageUrl }}"
                                            data-image-title="{{ $producto->prod_item }} — {{ $producto->prod_nombre }}"
                                            title="Ver imagen ampliada">
                                        <x-product-image :maeprod="$producto" variant="admin-thumb" />
                                    </button>
                                @else
                                    <x-product-image :maeprod="$producto" variant="admin-thumb" />
                                @endif
                            </td>
                            <td><code>{{ $producto->prod_item }}</code></td>
                            <td>{{ $producto->prod_nombre }}</td>
                            <td class="text-muted small">{{ $producto->prod_familia }}</td>
                            <td class="text-end">${{ number_format((int) $producto->prod_valor, 0, ',', '.') }}</td>
                            <td class="text-end">${{ number_format((int) ($producto->prod_valor_costo ?? 0), 0, ',', '.') }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.productos.edit', $producto->prod_item) }}" class="btn btn-outline-primary btn-sm py-0">Editar</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Sin productos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($productos->hasPages())
            <div class="card-body border-top py-2">{{ $productos->links() }}</div>
        @endif
    </div>
</div>

<div class="modal fade" id="modal-imagen-producto" tabindex="-1" aria-labelledby="modal-imagen-producto-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title fs-6" id="modal-imagen-producto-titulo"></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center p-2 bg-light">
                <img id="modal-imagen-producto-img" src="" alt="" class="img-fluid rounded shadow-sm product-image-zoom-preview">
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var modalEl = document.getElementById('modal-imagen-producto');
    var modalImg = document.getElementById('modal-imagen-producto-img');
    var modalTitle = document.getElementById('modal-imagen-producto-titulo');
    if (!modalEl || !modalImg) return;

    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    document.querySelectorAll('.product-image-zoom-trigger').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var url = trigger.dataset.imageUrl;
            if (!url) return;
            modalImg.src = url;
            modalImg.alt = trigger.dataset.imageTitle || 'Imagen producto';
            if (modalTitle) {
                modalTitle.textContent = trigger.dataset.imageTitle || 'Imagen producto';
            }
            bsModal.show();
        });
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        modalImg.removeAttribute('src');
        modalImg.alt = '';
    });
})();
</script>
@endpush
