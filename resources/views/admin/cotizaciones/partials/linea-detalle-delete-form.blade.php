@php
    $linea = $row['linea'];
@endphp
<form method="post" action="{{ route('admin.cotizaciones.lineas.destroy', $nota->nronota) }}" class="d-none form-eliminar-linea" data-prod="{{ $linea->prod_item }}" data-orden="{{ $linea->orden }}">
    @csrf
    @method('DELETE')
    <input type="hidden" name="prod_item" value="{{ $linea->prod_item }}">
    <input type="hidden" name="orden" value="{{ $linea->orden }}">
</form>
