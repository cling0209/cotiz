@php
    /** @var \App\Models\NotaMpSeguimiento|null $seg */
    $seg = $seg ?? null;
    $idOc = $seg?->id_orden_compra ?: null;
    $codigoOc = $seg?->textoOrdenCompraMp() ?? '—';
@endphp
@if(!$idOc && $codigoOc === '—')
    —
@else
    <div class="lh-sm">
        @if($idOc)
            <div><span class="text-muted">ID OC:</span> <span class="font-monospace">{{ $idOc }}</span></div>
        @endif
        @if($codigoOc === 'Pendiente')
            <div><span class="text-muted">Código OC:</span> <span class="text-warning">Pendiente</span></div>
        @elseif($codigoOc !== '—')
            <div><span class="text-muted">Código OC:</span> <span class="font-monospace">{{ $codigoOc }}</span></div>
        @elseif($idOc)
            <div><span class="text-muted">Código OC:</span> <span class="text-muted">—</span></div>
        @endif
    </div>
@endif
