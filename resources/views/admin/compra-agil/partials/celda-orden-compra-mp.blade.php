@php
    /** @var \App\Models\NotaMpSeguimiento|null $seg */
    $seg = $seg ?? null;
    $textoOc = $seg?->textoOrdenCompraMp() ?? '—';
@endphp
@if($textoOc === 'Pendiente')
    <span class="text-warning" title="OC emitida en MP{{ $seg?->id_orden_compra ? ' (ID '.$seg->id_orden_compra.')' : '' }}; pendiente código AG">Pendiente</span>
@elseif($textoOc !== '—')
    <span class="font-monospace">{{ $textoOc }}</span>
@else
    —
@endif
