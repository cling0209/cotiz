@if($nota?->esCopiaDeCotizacion())
    <span class="badge text-bg-secondary ms-1" title="Copia {{ $nota->correlativo }} del mismo c&oacute;digo de Mercado P&uacute;blico">
        Copia {{ $nota->correlativo }}
    </span>
@endif
