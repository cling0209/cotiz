@if(!empty($filtros['fechadesde']))
    <input type="hidden" name="fechadesde" value="{{ $filtros['fechadesde'] }}">
@endif
@if(!empty($filtros['fechahasta']))
    <input type="hidden" name="fechahasta" value="{{ $filtros['fechahasta'] }}">
@endif
@if(!empty($filtros['nronota']))
    <input type="hidden" name="nronota" value="{{ $filtros['nronota'] }}">
@endif
@if(!empty($filtros['cotizacion']))
    <input type="hidden" name="cotizacion" value="{{ $filtros['cotizacion'] }}">
@endif
@if(!empty($filtros['estado_mp']))
    <input type="hidden" name="estado_mp" value="{{ $filtros['estado_mp'] }}">
@endif
<input type="hidden" name="orden_campo" value="{{ $filtros['orden_campo'] ?? 'nronota' }}">
<input type="hidden" name="orden_dir" value="{{ $filtros['orden_dir'] ?? 'DESC' }}">
@if(!empty($page))
    <input type="hidden" name="page" value="{{ $page }}">
@endif
