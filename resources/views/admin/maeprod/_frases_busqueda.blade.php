                @if($puedeGestionarFrases)
                <div class="card shadow-sm mt-3">
                    <div class="card-header py-2 small fw-semibold">Palabras clave MP</div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">
                            Palabra clave opcional asociada a este producto para <strong>encontrar</strong> líneas en Compra Ágil.
                            El mantenedor principal (sin código) está en Mantenedores → Palabras clave MP.
                            No reemplazan las frases de vincular Agile ni las palabras clave de Oportunidades.
                        </p>
                        <form method="post"
                              action="{{ route('admin.producto-mp.frases.store') }}"
                              class="mb-3"
                              data-no-loader>
                            @csrf
                            <input type="hidden" name="prod_item" value="{{ $producto->prod_item }}">
                            <input type="hidden" name="redirect_producto" value="1">
                            <label class="form-label small mb-1" for="frase_busqueda_mp">Nueva palabra clave</label>
                            <div class="input-group input-group-sm">
                                <input type="text" name="frase" id="frase_busqueda_mp"
                                       class="form-control @error('frase') is-invalid @enderror"
                                       maxlength="200" required
                                       placeholder="Ej: barra pritt"
                                       autocomplete="off">
                                <button type="submit" class="btn btn-primary">Agregar</button>
                            </div>
                            @error('frase')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </form>
                        @if($producto->frasesBusqueda->isEmpty())
                            <p class="small text-muted mb-0">Sin palabras clave aún.</p>
                        @else
                            <ul class="list-group list-group-flush">
                                @foreach($producto->frasesBusqueda as $fraseBusqueda)
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                        <span class="small">{{ $fraseBusqueda->frase }}</span>
                                        <form method="post"
                                              action="{{ route('admin.producto-mp.frases.destroy', $fraseBusqueda) }}"
                                              data-confirm="¿Eliminar la palabra clave «{{ $fraseBusqueda->frase }}»?">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="redirect_producto" value="1">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Eliminar">&times;</button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                @endif
