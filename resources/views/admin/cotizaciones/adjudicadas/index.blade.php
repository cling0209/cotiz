@extends('layouts.admin')

@section('title', 'Cotizaciones adjudicadas')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Cotizaciones adjudicadas</h1>
        <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Listado general
        </a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="{{ route('admin.cotizaciones.adjudicadas.index') }}" class="row g-3 align-items-end" id="form-adjudicadas-filtros">
                <div class="col-md-2">
                    <label class="form-label" for="nronota">N&ordm; nota</label>
                    <input type="number" name="nronota" id="nronota" class="form-control form-control-sm" value="{{ $filtros['nronota'] ?: '' }}" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="fechaentregadesde">Fecha entrega desde</label>
                    <input type="date" name="fechaentregadesde" id="fechaentregadesde" class="form-control form-control-sm" value="{{ $filtros['fechaentregadesde'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="fechaentregahasta">Fecha entrega hasta</label>
                    <input type="date" name="fechaentregahasta" id="fechaentregahasta" class="form-control form-control-sm" value="{{ $filtros['fechaentregahasta'] }}">
                </div>
                <div class="col-md-auto d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-limpiar-adjudicadas">Borrar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nota</th>
                        <th>Fecha</th>
                        <th>Empresa</th>
                        <th>Usuario</th>
                        <th>Fecha entrega</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cotizaciones as $nota)
                        <tr>
                            <td>{{ $nota->nronota }}</td>
                            <td>{{ $nota->fecha?->format('d/m/Y') }}</td>
                            <td>{{ $nota->empresa }}</td>
                            <td>{{ $nota->usuarioRel?->fullName() ?: $nota->usuario }}</td>
                            <td>{{ $nota->fechaentrega?->format('d/m/Y') ?: '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.cotizaciones.edit', ['nronota' => $nota->nronota, 'from' => 'adjudicadas']) }}" class="btn btn-outline-primary btn-sm">
                                    Ver cotizaci&oacute;n
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Sin cotizaciones adjudicadas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($cotizaciones->hasPages())
            <div class="card-footer">{{ $cotizaciones->links() }}</div>
        @endif

        <div class="card-footer d-flex flex-wrap gap-2 justify-content-end">
            <a href="{{ route('admin.cotizaciones.adjudicadas.export.sin-codigo-softland') }}" class="btn btn-secondary btn-sm" data-no-loader
               title="Productos de cotizaciones aceptadas sin código Softland">
                Descargar sin c&oacute;digo Softland
            </a>
            <a href="{{ route('admin.cotizaciones.adjudicadas.export.detalle', array_filter([
                'nronota' => $filtros['nronota'] ?: null,
                'fechaentregadesde' => $filtros['fechaentregadesde'],
                'fechaentregahasta' => $filtros['fechaentregahasta'],
            ])) }}" class="btn btn-secondary btn-sm" data-no-loader>
                Descargar detalle
            </a>
        </div>
    </div>
</div>

<script>
document.getElementById('btn-limpiar-adjudicadas')?.addEventListener('click', function () {
    const form = document.getElementById('form-adjudicadas-filtros');
    if (!form) return;
    form.querySelector('#nronota').value = '';
    form.querySelector('#fechaentregadesde').value = '';
    form.querySelector('#fechaentregahasta').value = '';
    form.submit();
});
</script>
@endsection
