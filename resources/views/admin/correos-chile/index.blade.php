@extends('layouts.admin')

@section('title', 'Tarifa Correos Chile')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Importar tarifa CChile</h1>
            <p class="text-muted mb-0 small">
                Tarifa Distribuci&oacute;n Expresa (DEX) B2B de Correos de Chile. Suba el Excel oficial para cargarlo en cotiz.
            </p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold mb-3">Subir Excel</h2>
                    <form method="post" action="{{ route('admin.correos-chile.import') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="archivo" class="form-label small">Archivo (.xlsx / .xls)</label>
                            <input type="file" name="archivo" id="archivo"
                                   class="form-control form-control-sm @error('archivo') is-invalid @enderror"
                                   accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                                   required>
                            @error('archivo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Debe incluir columnas ORIGEN, DESTINO, recargo y tramos de peso (5,9 &hellip; 10000).
                                Al importar se <strong>reemplazan</strong> todas las tarifas anteriores.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-upload"></i> Importar tarifa
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold mb-3">Estado actual</h2>
                    @if($total > 0 && $ultima)
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4 text-muted">Destinos cargados</dt>
                            <dd class="col-sm-8">{{ number_format($total, 0, ',', '.') }}</dd>
                            <dt class="col-sm-4 text-muted">&Uacute;ltimo archivo</dt>
                            <dd class="col-sm-8">{{ $ultima->archivo_origen ?: '—' }}</dd>
                            <dt class="col-sm-4 text-muted">Fecha importaci&oacute;n</dt>
                            <dd class="col-sm-8">{{ $ultima->imported_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?: '—' }}</dd>
                            @if($tramos)
                                <dt class="col-sm-4 text-muted">Tramos (kg)</dt>
                                <dd class="col-sm-8"><code>{{ implode(' · ', $tramos) }}</code></dd>
                            @endif
                        </dl>
                    @else
                        <p class="text-muted small mb-0">A&uacute;n no hay tarifas importadas. Suba el Excel de Correos Chile para comenzar.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Buscar destino</label>
                    <input type="search" name="q" class="form-control form-control-sm"
                           placeholder="Ej. Antofagasta, Viña del Mar..."
                           value="{{ $q }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                    @if($q !== '')
                        <a href="{{ route('admin.correos-chile.index') }}" class="btn btn-link btn-sm">Limpiar</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive" style="overflow-x: auto;">
            <table class="table table-sm table-hover mb-0 align-middle" style="min-width: max-content;">
                <thead class="table-light">
                    <tr>
                        <th class="text-nowrap">Origen</th>
                        <th class="text-nowrap">Destino</th>
                        <th class="text-nowrap">Recargo</th>
                        @foreach($tramos as $tramo)
                            <th class="text-end text-nowrap">{{ $tramo }} kg</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($tarifas as $t)
                        <tr>
                            <td class="text-nowrap">{{ $t->origen }}</td>
                            <td class="text-nowrap">{{ $t->destino }}</td>
                            <td>
                                @if($t->tieneRecargo())
                                    <span class="badge text-bg-warning">{{ $t->recargo_pct }}%</span>
                                @else
                                    <span class="badge text-bg-secondary">NO</span>
                                @endif
                            </td>
                            @foreach($tramos as $tramo)
                                <td class="text-end font-monospace small text-nowrap">
                                    @php $p = $t->tarifas[$tramo] ?? null; @endphp
                                    {{ $p !== null ? '$'.number_format((int) $p, 0, ',', '.') : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 3 + max(count($tramos), 1) }}" class="text-muted text-center py-4">
                                No hay tarifas para mostrar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($tarifas->hasPages())
            <div class="card-footer">
                {{ $tarifas->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
