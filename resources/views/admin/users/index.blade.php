@extends('layouts.admin')

@section('title', 'Usuarios')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Mantenedor de usuarios</h1>
            <p class="text-muted mb-0 small">Superadministradores y ejecutivos del panel.</p>
        </div>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-person-plus"></i> Nuevo usuario
        </a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Buscar</label>
                    <input type="search" name="q" class="form-control form-control-sm" placeholder="Usuario, nombre o correo..."
                           value="{{ request('q') }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-link btn-sm">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Perfil</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($usuarios as $u)
                        <tr>
                            <td>
                                <code>{{ $u->username }}</code>
                                @if($u->id === auth()->id())
                                    <span class="badge text-bg-primary ms-1">T&uacute;</span>
                                @endif
                            </td>
                            <td>{{ $u->fullName() ?: '—' }}</td>
                            <td class="small">{{ $u->correo ?: '—' }}</td>
                            <td>
                                <span @class([
                                    'badge',
                                    'text-bg-dark' => $u->isSuperAdmin(),
                                    'text-bg-secondary' => $u->isEjecutivo(),
                                ])>{{ $u->perfilLabel() }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.users.edit', $u) }}" class="btn btn-outline-primary btn-sm py-0">Editar</a>
                                @if($u->id !== auth()->id())
                                    <form method="post" action="{{ route('admin.users.destroy', $u) }}" class="d-inline"
                                          data-confirm="¿Eliminar usuario {{ $u->username }}?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0">Eliminar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Sin usuarios.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($usuarios->hasPages())
            <div class="card-body border-top py-2">{{ $usuarios->links() }}</div>
        @endif
    </div>
</div>
@endsection
