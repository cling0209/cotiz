@extends('layouts.admin')

@section('title', 'Reportes — Resultados Compra Ágil')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('admin.compra-agil.resultados.index') }}" class="btn btn-outline-secondary btn-sm" data-no-loader>
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <h1 class="h3 mb-0">Reportes</h1>
    </div>

    <p class="text-muted small mb-4">
        Descargue reportes en CSV según los filtros de cada tipo. Los datos provienen de las ofertas consultadas en Mercado Público.
    </p>

    <div class="d-flex flex-column gap-3">
        @include('admin.compra-agil.partials.reporte-productos-ganados')
        @include('admin.compra-agil.partials.reporte-match-agile-maestro')
    </div>
</div>
@endsection
