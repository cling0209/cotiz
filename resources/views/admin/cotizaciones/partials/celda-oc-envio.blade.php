@php
$codigoOc = $nota->ocompraEfectiva();
$fechaEnvioOc = $nota->fechaEnvioOcEfectiva();
@endphp
@if($codigoOc === '' && $fechaEnvioOc === null)
&mdash;
@else
@if($codigoOc !== '')
<div>{{ $codigoOc }}@if($nota->ocompraDesdeApi()) <span class="text-muted">MP</span>@endif</div>
@endif
@if($fechaEnvioOc)
<div class="text-muted">
    {{ $nota->formatoFechaEnvioOcEfectiva() }}@if($nota->fechaEnvioOcDesdeApi()) <span class="text-muted">MP</span>@endif
</div>
@endif
@endif
