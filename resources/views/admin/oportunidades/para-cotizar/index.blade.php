@extends('layouts.admin')

@section('title', 'Oportunidades')

@section('content')
@php
    $fmtFecha = static function (?string $valor): string {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return '—';
        }
        try {
            return \Illuminate\Support\Carbon::parse($valor)
                ->timezone(config('app.timezone'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $valor;
        }
    };
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Oportunidades</h1>
            <p class="text-muted mb-0 small">
                Busque Compras &Aacute;giles publicadas seg&uacute;n sus palabras clave.
                Orden: presupuesto m&aacute;s alto y regi&oacute;n m&aacute;s cerca de Santiago.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-tags"></i> Palabras clave
            </a>
            <form method="get" action="{{ route('admin.oportunidades.para-cotizar.index') }}" class="d-inline">
                <input type="hidden" name="buscar" value="1">
                <button type="submit" class="btn btn-primary btn-sm" @disabled($palabras === [])>
                    <i class="bi bi-search"></i> Buscar cotizaciones
                </button>
            </form>
        </div>
    </div>

    @if($palabras === [])
        <div class="alert alert-warning">
            No hay palabras clave configuradas.
            <a href="{{ route('admin.oportunidades.palabras-clave.index') }}" class="alert-link">Agr&eacute;guelas aqu&iacute;</a>
            para poder buscar.
        </div>
    @else
        <div class="mb-3">
            <span class="small text-muted me-1">Palabras clave:</span>
            @foreach($palabras as $frase)
                <span class="badge text-bg-light border me-1">{{ $frase }}</span>
            @endforeach
        </div>
    @endif

    @if($errorApi)
        <div class="alert alert-danger">{{ $errorApi }}</div>
    @endif

    @if(! $busquedaRealizada && $palabras !== [])
        <div class="card shadow-sm">
            <div class="card-body text-center text-muted py-5">
                Pulse <strong>Buscar cotizaciones</strong> para consultar Mercado P&uacute;blico con sus palabras clave.
            </div>
        </div>
    @elseif($busquedaRealizada && ! $errorApi)
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Cotizaci&oacute;n</th>
                            <th>Fecha publicaci&oacute;n</th>
                            <th>Fecha cierre</th>
                            <th class="text-end">Presupuesto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            @php
                                $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                                $monto = (int) ($item['monto_presupuesto_clp'] ?? 0);
                                $urlCotizar = $codigo !== ''
                                    ? route('admin.cotizaciones.create', ['codigo' => $codigo])
                                    : null;
                            @endphp
                            <tr @if($urlCotizar) class="oportunidad-fila" role="button" tabindex="0"
                                data-href="{{ $urlCotizar }}"
                                title="Cotizar {{ $codigo }}" @endif>
                                <td>
                                    <code>{{ $codigo !== '' ? $codigo : '—' }}</code>
                                    @if(!empty($item['nombre']))
                                        <div class="small text-muted text-truncate" style="max-width: 28rem;">{{ $item['nombre'] }}</div>
                                    @endif
                                </td>
                                <td class="small text-nowrap">{{ $fmtFecha($item['fecha_publicacion'] ?? null) }}</td>
                                <td class="small text-nowrap">{{ $fmtFecha($item['fecha_cierre'] ?? null) }}</td>
                                <td class="text-end tabular-nums text-nowrap">
                                    @if($monto > 0)
                                        ${{ number_format($monto, 0, ',', '.') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    No se encontraron compras publicadas con esas palabras clave.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(count($items) > 0)
                <div class="card-body border-top py-2 small text-muted">
                    {{ count($items) }} oportunidad{{ count($items) === 1 ? '' : 'es' }}.
                    Haga clic en una fila para cotizarla.
                </div>
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.oportunidad-fila[data-href]').forEach((row) => {
        const go = () => { window.location.href = row.getAttribute('data-href'); };
        row.addEventListener('click', go);
        row.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                go();
            }
        });
    });
})();
</script>
@endpush
