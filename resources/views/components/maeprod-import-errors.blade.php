@props([
    'errors' => [],
    'total' => null,
    'downloadToken' => null,
])

@if(!empty($errors))
    <div class="alert alert-warning">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <strong>Algunas filas no se importaron</strong>
            @if($downloadToken)
                <a href="{{ route('admin.productos.import.errors', $downloadToken) }}" class="btn btn-sm btn-outline-dark" data-no-loader>
                    <i class="bi bi-download"></i> Descargar errores CSV
                </a>
            @endif
        </div>

        @if($total && $total > count($errors))
            <p class="small mb-2">Se muestran {{ count($errors) }} de {{ $total }} error(es).</p>
        @endif

        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 bg-white">
                <thead class="table-light">
                    <tr>
                        <th style="width:56px">Fila</th>
                        <th style="width:110px">C&oacute;digo</th>
                        <th>Nombre</th>
                        <th style="width:90px">Familia</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($errors as $error)
                        @php
                            $row = is_array($error) ? $error : ['fila' => null, 'codigo' => '', 'nombre' => '', 'familia' => '', 'mensaje' => (string) $error, 'detalle' => null];
                        @endphp
                        <tr>
                            <td>{{ $row['fila'] ?? '—' }}</td>
                            <td><code>{{ $row['codigo'] ?? '' }}</code></td>
                            <td class="small">{{ $row['nombre'] ?? '' }}</td>
                            <td class="small">{{ $row['familia'] ?? '' }}</td>
                            <td class="small">
                                {{ $row['mensaje'] ?? '' }}
                                @if(!empty($row['detalle']))
                                    <span class="text-muted">({{ $row['detalle'] }})</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
