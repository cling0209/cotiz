@extends('layouts.admin')

@section('title', 'Carga de cotización desde archivo')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Carga de cotización desde archivo</h1>
        <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">Listado cotizaciones</a>
    </div>

    @if($nronotaResultado ?? null)
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2">
            <span>Cotización guardada como nota #{{ $nronotaResultado }}.</span>
            <a href="{{ route('admin.cotizaciones.edit', $nronotaResultado) }}" class="btn btn-primary btn-sm">
                <i class="bi bi-box-arrow-in-right"></i> Ir a la nota
            </a>
        </div>
    @endif

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-danger text-white fw-semibold">Archivo de cotización</div>
        <div class="card-body">
            <form action="{{ route('admin.cotizaciones.carga-archivo.previsualizar') }}" method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                @csrf
                <div class="col-md-8">
                    <label for="archivo" class="form-label">Seleccionar archivo CSV</label>
                    <input type="file" name="archivo" id="archivo" class="form-control form-control-sm" accept=".csv,text/csv" required>
                </div>
                <div class="col-md-4 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-eye"></i> Previsualizar
                    </button>
                    <button type="reset" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('archivo').value=''">
                        Quitar archivo
                    </button>
                    <a href="{{ route('admin.cotizaciones.carga-archivo.plantilla') }}" class="btn btn-success btn-sm">
                        <i class="bi bi-download"></i> Descargar ejemplo
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="alert alert-warning small mb-4">
        <strong>Requisitos del archivo:</strong>
        <ul class="mb-0 mt-2">
            <li><strong>Formato permitido:</strong> solo CSV (.csv)</li>
            <li><strong>Tamaño máximo:</strong> 10 MB</li>
            <li><strong>Delimitador:</strong> punto y coma (;)</li>
            <li>No agregue punto y coma (;) dentro de textos (nombre del cliente, producto, etc.)</li>
            <li>Para procesar: haga clic en <strong>Previsualizar</strong> y, si está correcto, en <strong>Confirmar carga</strong></li>
        </ul>
    </div>

    @if(isset($preview))
        <div id="previewSection" class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white fw-semibold">Previsualización</div>
            <div class="card-body">
                @php $prev = $preview['resumen']; @endphp
                <h2 class="h6 text-muted mb-3">Resumen</h2>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered mb-0">
                        <tbody>
                            <tr>
                                <th class="table-light" style="width:18%">Orden de compra</th>
                                <td>{{ $prev['encargado'] ?? '' }}</td>
                                <th class="table-light" style="width:18%">RUT / Cód. cliente</th>
                                <td>{{ $prev['rutempresa'] ?? '' }}</td>
                            </tr>
                            <tr>
                                <th class="table-light">Nombre cliente</th>
                                <td>{{ $prev['empresa'] ?? '' }}</td>
                                <th class="table-light">Contacto</th>
                                <td>{{ $prev['contacto'] ?? '' }}</td>
                            </tr>
                            <tr>
                                <th class="table-light">Fecha entrega</th>
                                <td>{{ $prev['fechaentrega'] ?? '' }}</td>
                                <th class="table-light">Días entrega</th>
                                <td>{{ $prev['diashabiles'] ?? '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                @if($preview['nronota_existente'] ?? null)
                    <div class="alert alert-info py-2 small">
                        Se reutilizará la nota #{{ $preview['nronota_existente'] }} (misma orden de compra y usuario).
                    </div>
                @endif

                <h2 class="h6 text-muted mb-3">Detalle de productos</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width:4rem">Imagen</th>
                                <th>Código</th>
                                <th>Producto</th>
                                <th class="text-end">Cantidad</th>
                                <th class="text-end">Factor</th>
                                <th>Estado</th>
                                <th>Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($preview['detalle'] as $fila)
                                @php
                                    $imagenUrl = trim((string) ($fila['image_url'] ?? ''));
                                    $prodNombre = trim((string) ($fila['prod_nombre'] ?? $fila['nombre'] ?? ''));
                                    $imagenTitulo = ($fila['codigo'] ?? '') . ($prodNombre !== '' ? ' — ' . $prodNombre : '');
                                @endphp
                                <tr class="{{ ($fila['valido'] ?? false) ? 'text-success' : 'text-danger' }}">
                                    <td class="text-center p-1">
                                        @if($imagenUrl !== '')
                                            <button type="button"
                                                    class="product-image-zoom-trigger cotiz-buscar-thumb-btn border-0 bg-transparent p-0"
                                                    data-image-url="{{ $imagenUrl }}"
                                                    data-image-title="{{ $imagenTitulo }}"
                                                    title="Ver imagen ampliada">
                                                <img src="{{ $imagenUrl }}"
                                                     alt=""
                                                     class="cotiz-buscar-thumb"
                                                     loading="eager"
                                                     decoding="async"
                                                     referrerpolicy="no-referrer"
                                                     onerror="this.onerror=null;this.src='{{ asset('images/no-image.svg') }}'">
                                            </button>
                                        @else
                                            <img src="{{ asset('images/no-image.svg') }}"
                                                 alt=""
                                                 class="cotiz-buscar-thumb"
                                                 loading="eager"
                                                 decoding="async">
                                        @endif
                                    </td>
                                    <td>{{ $fila['codigo'] }}</td>
                                    <td>{{ $prodNombre !== '' ? $prodNombre : '—' }}</td>
                                    <td class="text-end tabular-nums">{{ $fila['cantidad'] }}</td>
                                    <td class="text-end tabular-nums">{{ $fila['factor'] }}</td>
                                    <td>{{ ($fila['valido'] ?? false) ? 'OK' : 'Omitido' }}</td>
                                    <td>{{ $fila['motivo'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <form action="{{ route('admin.cotizaciones.carga-archivo.confirmar') }}" method="post" class="mt-4">
                    @csrf
                    <input type="hidden" name="previewToken" value="{{ $preview['token'] }}">
                    <input type="hidden" name="previewPayload" value="{{ $preview['payload'] }}">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-check2-circle"></i> Confirmar carga
                    </button>
                </form>
            </div>
        </div>
        <div class="modal fade" id="modal-imagen-carga-archivo" tabindex="-1" aria-labelledby="modal-imagen-carga-archivo-titulo" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h2 class="modal-title fs-6" id="modal-imagen-carga-archivo-titulo"></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body text-center p-2 bg-light">
                        <img id="modal-imagen-carga-archivo-img" src="" alt="" class="img-fluid rounded shadow-sm product-image-zoom-preview">
                    </div>
                </div>
            </div>
        </div>
        @push('scripts')
        <script>
            document.getElementById('previewSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });

            (function () {
                const modalEl = document.getElementById('modal-imagen-carga-archivo');
                const modalImg = document.getElementById('modal-imagen-carga-archivo-img');
                const modalTitle = document.getElementById('modal-imagen-carga-archivo-titulo');
                if (!modalEl || !modalImg) return;

                const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

                document.querySelectorAll('#previewSection .product-image-zoom-trigger:not([data-zoom-bound])').forEach(function (trigger) {
                    trigger.dataset.zoomBound = '1';
                    trigger.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const url = trigger.dataset.imageUrl;
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
    @endif
</div>
@endsection
