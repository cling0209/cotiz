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
    <link href="{{ asset('css/admin.css') }}?v=oportunidades-visto-20260718" rel="stylesheet">
    <link href="{{ asset('css/page-loader.css') }}?v=levanta-servicio" rel="stylesheet">
    @stack('head')
</head>
<body class="admin-body">
<x-page-loader />
@if(auth()->check())
<nav class="navbar navbar-dark admin-navbar sticky-top">
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
            <a href="{{ route('admin.cotizaciones.carga-archivo.index') }}" class="nav-link-admin {{ request()->routeIs('admin.cotizaciones.carga-archivo.*') ? 'active' : '' }}">
                <i class="bi bi-upload"></i> Cargar cotización
            </a>
            @if(auth()->user()->isSuperAdmin())
                <a href="{{ route('admin.cotizaciones.adjudicadas.index') }}" class="nav-link-admin {{ request()->routeIs('admin.cotizaciones.adjudicadas.*') ? 'active' : '' }}">
                    <i class="bi bi-check2-circle"></i> Adjudicadas
                </a>
                @if(auth()->user()->canAccessCompraAgilAnalisis())
                    <a href="{{ route('admin.compra-agil.analisis.index') }}" class="nav-link-admin {{ request()->routeIs('admin.compra-agil.analisis.*') ? 'active' : '' }}">
                        <i class="bi bi-graph-up"></i> Análisis MP
                    </a>
                @endif
                @if(auth()->user()->canAccessCompraAgilResultados())
                    <a href="{{ route('admin.compra-agil.resultados.index') }}" class="nav-link-admin {{ request()->routeIs('admin.compra-agil.resultados.*') ? 'active' : '' }}">
                        <i class="bi bi-trophy"></i> Resultados Compra Ágil
                    </a>
                @endif
                <div class="dropdown">
                    <a href="#" class="nav-link-admin dropdown-toggle {{ request()->routeIs('admin.productos.*') || request()->routeIs('admin.users.*') || request()->routeIs('admin.oportunidades.palabras-clave.*') ? 'active' : '' }}"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear"></i> Mantenedores
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item {{ request()->routeIs('admin.productos.*') ? 'active' : '' }}"
                               href="{{ route('admin.productos.index') }}">
                                <i class="bi bi-box-seam"></i> Productos
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
                               href="{{ route('admin.users.index') }}">
                                <i class="bi bi-people"></i> Usuarios
                            </a>
                        </li>
                        @if(auth()->user()->canAccessPalabrasClave())
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('admin.oportunidades.palabras-clave.*') ? 'active' : '' }}"
                                   href="{{ route('admin.oportunidades.palabras-clave.index') }}">
                                    <i class="bi bi-tags"></i> Palabras clave
                                </a>
                            </li>
                        @endif
                    </ul>
                </div>
            @elseif(auth()->user()->isEjecutivo())
                <a href="{{ route('admin.productos.index') }}" class="nav-link-admin {{ request()->routeIs('admin.productos.*') ? 'active' : '' }}">
                    <i class="bi bi-box-seam"></i> Productos
                </a>
            @endif
            @if(auth()->user()->canVerOportunidades())
                <a href="{{ route('admin.oportunidades.para-cotizar.index') }}" class="nav-link-admin {{ request()->routeIs('admin.oportunidades.para-cotizar.*') ? 'active' : '' }}">
                    <i class="bi bi-lightning-charge"></i> Oportunidades
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

<div class="modal fade" id="adminDialogModal" tabindex="-1" aria-labelledby="adminDialogTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-dialog-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <i id="adminDialogIcon" class="bi bi-info-circle-fill admin-dialog-icon admin-dialog-icon--info" aria-hidden="true"></i>
                    <h5 class="modal-title mb-0" id="adminDialogTitle">Cotiz</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="adminDialogBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="adminDialogBtnCancel">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="adminDialogBtnOk">Aceptar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/admin-dialog.js') }}?v=3"></script>
<script src="{{ asset('js/page-loader.js') }}?v=levanta-servicio" defer></script>
<script src="{{ asset('js/product-image.js') }}" defer></script>
<script src="{{ asset('js/password-toggle.js') }}?v=2" defer></script>
@stack('scripts')
</body>
</html>
