<div class="col-auto">
    <label for="f-usuario" class="form-label small mb-0">Ejecutivo</label>
    <select class="form-select form-select-sm" id="f-usuario" name="usuario" style="width:12rem">
        <option value="">Todos</option>
        @foreach(($ejecutivosFiltro ?? []) as $ejecutivo)
            <option value="{{ $ejecutivo->username }}" @selected(($filtros['usuario'] ?? '') === $ejecutivo->username)>
                {{ $ejecutivo->fullName() ?: $ejecutivo->username }}
            </option>
        @endforeach
    </select>
</div>
