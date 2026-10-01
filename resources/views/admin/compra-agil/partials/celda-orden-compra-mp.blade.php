@php
    /** @var \App\Models\NotaMpSeguimiento|null $seg */
    $seg = $seg ?? null;
    $idOc = $seg?->id_orden_compra ?: null;
    $estadoOc = $seg?->estadoOrdenCompraMp();
    $ocNota = $seg?->ocompraNota() ?? '';
    $ocMp = $seg?->ocompraMp() ?? '';
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
            @if($ocNota !== '')
                <div><span class="text-muted">OC nota:</span> <span class="font-monospace">{{ $ocNota }}</span></div>
            @endif
            @if($ocMp !== '')
                <div><span class="text-muted">OC MP:</span> <span class="font-monospace">{{ $ocMp }}</span></div>
            @endif
            @if($seg->ocompraNoCoincide())
                <div><span class="badge text-bg-danger" title="El código de la nota no coincide con el de Mercado Público">OC no coincide</span></div>
            @endif
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
