@php
    /** @var \App\Models\NotaMpSeguimiento|null $seg */
    $seg = $seg ?? null;
    $idOc = $seg?->id_orden_compra ?: null;
    $estadoOc = $seg?->estadoOrdenCompraMp();
    $codigoOc = $seg?->textoOrdenCompraMp() ?? '—';
    $empresaOc = trim((string) ($seg?->razon_social_ganador ?? ''));
@endphp
@if(!$idOc && $estadoOc === null)
    —
@else
    <div class="lh-sm">
        @if($idOc)
            <div><span class="text-muted">ID OC:</span> <span class="font-monospace">{{ $idOc }}</span></div>
        @endif
        @if($estadoOc === \App\Enums\EstadoOrdenCompraMp::CODIGO)
            <div><span class="text-muted">Código OC:</span> <span class="font-monospace">{{ $codigoOc }}</span></div>
        @elseif($estadoOc === \App\Enums\EstadoOrdenCompraMp::OTRA_EMPRESA)
            <div class="{{ $estadoOc->claseCss() }}">{{ $estadoOc->etiqueta() }}</div>
            @if($empresaOc !== '')
                <div class="small text-muted">{{ $empresaOc }}</div>
            @endif
        @elseif($estadoOc !== null)
            <div><span class="text-muted">Código OC:</span> <span class="{{ $estadoOc->claseCss() }}">{{ $estadoOc->etiqueta() }}</span></div>
        @else
            <div><span class="text-muted">Código OC:</span> <span class="text-muted">—</span></div>
        @endif
    </div>
@endif
