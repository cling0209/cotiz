@extends('layouts.admin')

@section('title', 'Cerradas — Resultados Compra Ágil')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('admin.compra-agil.resultados.index') }}" class="btn btn-outline-secondary btn-sm" data-no-loader>
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <h1 class="h3 mb-0">Cerradas</h1>
        <span class="badge text-bg-secondary">{{ $cerradas->total() }}</span>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nota</th>
                        <th>Código CA</th>
                        <th>Publicación</th>
                        <th>Organismo</th>
                        <th>Estado MP</th>
                        <th>Seguimiento</th>
                        <th>Ganador</th>
                        <th class="text-end">Monto</th>
                        <th>Consultado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cerradas as $seg)
                        <tr>
                            <td>{{ $seg->nronota }}</td>
                            <td class="font-monospace small">{{ $seg->codigo_proceso }}</td>
                            <td class="small text-muted">{{ $seg->fecha_publicacion?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="small">{{ Str::limit($seg->organismo, 40) }}</td>
                            <td class="small">{{ $seg->estado_mp_glosa ?: $seg->estado_mp_codigo }}</td>
                            <td>@include('admin.compra-agil.partials.resultado-badge', ['resultado' => $seg->resultado_propio])</td>
                            <td class="small">
                                @if($seg->razon_social_ganador)
                                    {{ Str::limit($seg->razon_social_ganador, 30) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end small">
                                @if($seg->monto_total_ganador)
                                    ${{ number_format($seg->monto_total_ganador, 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small text-muted">{{ $seg->ultimo_consultado_en?->format('d/m/Y H:i') }}</td>
                            <td>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-detalle-mp" data-nronota="{{ $seg->nronota }}">Detalle</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">Sin procesos cerrados registrados aún.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($cerradas->hasPages())
            <div class="card-footer py-2 d-flex justify-content-center">
                {{ $cerradas->links() }}
            </div>
        @endif
    </div>
</div>

@include('admin.compra-agil.partials.modal-detalle-mp')
@endsection
