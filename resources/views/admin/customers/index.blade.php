@extends('layouts.admin')

@section('title', 'Clientes')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Clientes</h1>
            <p class="text-muted mb-0">Cuentas registradas en la tienda. Eliminar una cuenta no borra sus pedidos anteriores.</p>
        </div>
    </div>

    <div class="card admin-card mb-4">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Buscar</label>
                    <input type="search" name="q" class="form-control" placeholder="Nombre o correo..."
                           value="{{ request('q') }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-outline-primary">Filtrar</button>
                    <a href="{{ route('admin.customers.index') }}" class="btn btn-link">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card admin-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 admin-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Pedidos</th>
                        <th>Registro</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($customers as $customer)
                        <tr>
                            <td class="fw-semibold">{{ $customer->name }}</td>
                            <td>{{ $customer->email }}</td>
                            <td>
                                @if($customer->orders_count > 0)
                                    <a href="{{ route('admin.orders.index', ['q' => $customer->email]) }}"
                                       class="text-decoration-none">
                                        {{ $customer->orders_count }}
                                    </a>
                                @else
                                    <span class="text-muted">0</span>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $customer->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="text-end">
                                <form method="post" action="{{ route('admin.customers.destroy', $customer) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('¿Eliminar la cuenta de {{ $customer->name }} ({{ $customer->email }})? Sus pedidos anteriores se conservarán.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        Eliminar
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No hay clientes registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($customers->hasPages())
            <div class="card-body border-top">
                {{ $customers->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
