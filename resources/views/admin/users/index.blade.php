@extends('layouts.admin')

@section('title', 'Administradores')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Administradores</h1>
            <p class="text-muted mb-0">Cuentas con acceso al panel de administración.</p>
        </div>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
            <i class="bi bi-person-plus"></i> Nuevo administrador
        </a>
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
                    <a href="{{ route('admin.users.index') }}" class="btn btn-link">Limpiar</a>
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
                        <th>Registro</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($admins as $admin)
                        <tr>
                            <td class="fw-semibold">
                                {{ $admin->name }}
                                @if($admin->id === auth()->id())
                                    <span class="badge text-bg-primary ms-1">Tú</span>
                                @endif
                            </td>
                            <td>{{ $admin->email }}</td>
                            <td class="text-muted small">{{ $admin->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="text-end">
                                @if($admin->id !== auth()->id())
                                    <form method="post" action="{{ route('admin.users.destroy', $admin) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('¿Quitar permisos de administrador a {{ $admin->name }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            Quitar admin
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No hay administradores.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($admins->hasPages())
            <div class="card-body border-top">
                {{ $admins->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
