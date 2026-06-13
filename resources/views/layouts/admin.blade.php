<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Cotizaciones') — {{ config('app.name', 'Cotiz') }}</title>
    <x-favicon />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/admin.css') }}" rel="stylesheet">
    <link href="{{ asset('css/page-loader.css') }}?v=calc" rel="stylesheet">
    @stack('head')
</head>
<body class="admin-body">
<x-page-loader />
@if(auth()->check())
<nav class="navbar navbar-dark admin-navbar">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-white" href="{{ route('admin.cotizaciones.index') }}">
            {{ config('app.name', 'Cotiz') }}
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="{{ route('admin.cotizaciones.index') }}" class="nav-link-admin {{ request()->routeIs('admin.cotizaciones.index') ? 'active' : '' }}">
                <i class="bi bi-list-ul"></i> Listado
            </a>
            @if($cotizacionPendienteSinNumero ?? null)
                <a href="{{ route('admin.cotizaciones.edit', $cotizacionPendienteSinNumero->nronota) }}" class="nav-link-admin {{ request()->routeIs('admin.cotizaciones.edit') && (int) request()->route('nronota') === $cotizacionPendienteSinNumero->nronota ? 'active' : '' }}" title="Complete el número de cotización antes de crear otra">
                    <i class="bi bi-exclamation-circle"></i> Pendiente #{{ $cotizacionPendienteSinNumero->nronota }}
                </a>
            @else
                <a href="{{ route('admin.cotizaciones.create') }}" class="nav-link-admin">
                    <i class="bi bi-plus-circle"></i> Nueva
                </a>
            @endif
            <a href="{{ route('admin.cotizaciones.retomar') }}" class="nav-link-admin">
                <i class="bi bi-arrow-repeat"></i> Retomar
            </a>
            <a href="{{ route('admin.agile.index') }}" class="nav-link-admin {{ request()->routeIs('admin.agile.*') ? 'active' : '' }}">
                <i class="bi bi-cloud-download"></i> Recepción Agile
            </a>
            @if(auth()->user()->isSuperAdmin())
                <a href="{{ route('admin.productos.index') }}" class="nav-link-admin {{ request()->routeIs('admin.productos.*') ? 'active' : '' }}">
                    <i class="bi bi-box-seam"></i> Productos
                </a>
                <a href="{{ route('admin.users.index') }}" class="nav-link-admin {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i> Usuarios
                </a>
            @endif
            <span class="text-white-50 small d-none d-md-inline">{{ auth()->user()->fullName() ?: auth()->user()->username }}</span>
            <a href="{{ route('admin.account.password') }}" class="nav-link-admin {{ request()->routeIs('admin.account.password') ? 'active' : '' }}" title="Cambiar contraseña">
                <i class="bi bi-key"></i> Contraseña
            </a>
            <form action="{{ route('admin.logout') }}" method="post" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-light btn-sm">Salir</button>
            </form>
        </div>
    </div>
</nav>
@endif

@if(session('success'))
    <div class="container-fluid mt-3">
        <div class="alert alert-success alert-dismissible fade show mb-0">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
@endif
@if(session('error'))
    <div class="container-fluid mt-3">
        <div class="alert alert-danger alert-dismissible fade show mb-0">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
@endif
@if(session('info'))
    <div class="container-fluid mt-3">
        <div class="alert alert-info alert-dismissible fade show mb-0">
            {{ session('info') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
@endif

@if(session('warning'))
    <div class="container-fluid mt-3">
        <div class="alert alert-warning alert-dismissible fade show mb-0">
            {{ session('warning') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
@endif

<main class="admin-main">@yield('content')</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/page-loader.js') }}?v=exportmsg" defer></script>
<script src="{{ asset('js/product-image.js') }}" defer></script>
<script src="{{ asset('js/password-toggle.js') }}" defer></script>
@stack('scripts')
</body>
</html>
