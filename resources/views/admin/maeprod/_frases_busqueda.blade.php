                @if($puedeGestionarFrases)
                <div class="card shadow-sm mt-3">
                    <div class="card-header py-2 small fw-semibold">Frases de búsqueda MP</div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">
                            Textos para <strong>encontrar</strong> este producto en líneas de Compra Ágil.
                            No reemplazan las frases de vincular Agile ni las palabras clave de Oportunidades.
                            Puede repetirse la misma frase en otro SKU.
                        </p>
                        <form method="post"
                              action="{{ route('admin.producto-mp.frases.store') }}"
                              class="mb-3"
                              data-no-loader>
                            @csrf
                            <input type="hidden" name="prod_item" value="{{ $producto->prod_item }}">
                            <input type="hidden" name="redirect_producto" value="1">
                            <label class="form-label small mb-1" for="frase_busqueda_mp">Nueva frase de búsqueda</label>
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
                            <p class="small text-muted mb-0">Sin frases de búsqueda aún.</p>
                        @else
                            <ul class="list-group list-group-flush">
                                @foreach($producto->frasesBusqueda as $fraseBusqueda)
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                        <span class="small">{{ $fraseBusqueda->frase }}</span>
                                        <form method="post"
                                              action="{{ route('admin.producto-mp.frases.destroy', $fraseBusqueda) }}"
                                              onsubmit="return confirm('¿Eliminar esta frase de búsqueda?');">
                                            @csrf
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
