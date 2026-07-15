@extends('layouts.admin')

@section('title', 'Oportunidades')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Oportunidades</h1>
            <p class="text-muted mb-0 small">
                Compras &Aacute;giles publicadas que coinciden con sus palabras clave.
                Orden: presupuesto m&aacute;s alto y regi&oacute;n m&aacute;s cerca de Santiago.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-tags"></i> Palabras clave
            </a>
            <a href="{{ route('admin.oportunidades.para-cotizar.index') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-clockwise"></i> Actualizar
            </a>
        </div>
    </div>

    @if($palabras === [])
        <div class="alert alert-warning">
            No hay palabras clave configuradas.
            <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="alert-link">Agr&eacute;guelas aqu&iacute;</a>
            para ver oportunidades.
        </div>
    @else
        <div class="mb-3">
            <span class="small text-muted me-1">Buscando:</span>
            @foreach($palabras as $frase)
                <span class="badge text-bg-light border me-1">{{ $frase }}</span>
            @endforeach
        </div>
    @endif

    @if($errorApi)
        <div class="alert alert-danger">{{ $errorApi }}</div>
    @endif

    @if($palabras !== [] && ! $errorApi)
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>C&oacute;digo</th>
                            <th>Nombre</th>
                            <th class="text-end">Presupuesto</th>
                            <th>Regi&oacute;n</th>
                            <th>Coincide con</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            @php
                                $monto = (int) ($item['monto_presupuesto_clp'] ?? 0);
                                $regionCodigo = isset($item['region']) ? (int) $item['region'] : null;
                                $regionNombre = trim((string) ($item['nombre_region'] ?? ''));
                                if ($regionNombre === '' && $regionCodigo) {
                                    $regionNombre = \App\Services\CompraAgilRegionScope::nombreRegion($regionCodigo);
                                }
                            @endphp
                            <tr>
                                <td><code>{{ $item['codigo'] ?? '—' }}</code></td>
                                <td>
                                    <div class="fw-medium">{{ $item['nombre'] ?? '—' }}</div>
                                    @if(!empty($item['organismo']))
                                        <div class="small text-muted">{{ $item['organismo'] }}</div>
                                    @endif
                                </td>
                                <td class="text-end tabular-nums text-nowrap">
                                    @if($monto > 0)
                                        ${{ number_format($monto, 0, ',', '.') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small">{{ $regionNombre ?: '—' }}</td>
                                <td>
                                    @foreach($item['palabras_coinciden'] ?? [] as $match)
                                        <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle me-1">{{ $match }}</span>
                                    @endforeach
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('admin.cotizaciones.create') }}"
                                       class="btn btn-primary btn-sm py-0"
                                       title="Crear cotización e importar este código {{ $item['codigo'] ?? '' }}">
                                        Cotizar
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No se encontraron compras publicadas con esas palabras clave.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(count($items) > 0)
                <div class="card-body border-top py-2 small text-muted">
                    {{ count($items) }} oportunidad{{ count($items) === 1 ? '' : 'es' }} encontrada{{ count($items) === 1 ? '' : 's' }}.
                    Los resultados se refrescan cada pocos minutos.
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
