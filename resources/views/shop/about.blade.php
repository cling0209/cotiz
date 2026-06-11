@extends('layouts.shop')

@section('title', 'Quiénes somos')

@section('content')
<section class="container py-4 py-lg-5">
    <div class="about-hero hero-section p-4 p-lg-5 mb-5">
        <div class="row align-items-center position-relative" style="z-index:1">
            <div class="col-lg-8">
                <span class="badge bg-light text-primary mb-3">Desde 2011</span>
                <h1 class="display-5 fw-bold mb-3">Quiénes somos</h1>
                <p class="lead mb-0 opacity-90">No llegamos ayer.</p>
            </div>
            <div class="col-lg-4 d-none d-lg-block text-end">
                <i class="bi bi-building display-1 opacity-50"></i>
            </div>
        </div>
    </div>

    <div class="checkout-card card p-4 p-lg-5 mb-4">
        <p class="lead fw-semibold text-primary mb-3">Comercializadora Rómulo</p>
        <p class="mb-0 text-secondary">
            Llevamos desde 2011 trabajando cara a cara, caja por caja, pedido por pedido.
            Conocemos a nuestros clientes, sus rutinas y lo que necesitan cuando lo necesitan.
        </p>
    </div>

    <div class="mb-4">
        <h2 class="h4 fw-bold mb-3">Pero algo cambió…</h2>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="about-quote h-100">
                    <i class="bi bi-chat-quote text-primary fs-4 mb-2 d-block"></i>
                    <p class="mb-0 fw-medium">«¿Por qué no vendes también por internet?»</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="about-quote h-100">
                    <i class="bi bi-chat-quote text-primary fs-4 mb-2 d-block"></i>
                    <p class="mb-0 fw-medium">«Confío en ti, pero quiero comprarte desde mi casa.»</p>
                </div>
            </div>
        </div>
        <p class="text-center text-secondary mt-4 mb-0">Y los escuchamos.</p>
    </div>

    <div class="checkout-card card p-4 p-lg-5 mb-5 text-center">
        <h2 class="h4 fw-bold mb-3">Por eso hoy vendemos online</h2>
        <p class="fs-5 mb-2">No porque esté de moda.</p>
        <p class="fs-5 fw-semibold text-primary mb-0">Porque ustedes nos lo pidieron.</p>
    </div>

    <div class="mb-5">
        <h2 class="h4 fw-bold text-center mb-4">Seguimos siendo los mismos de siempre</h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="about-pillar checkout-card card p-4 h-100 text-center">
                    <i class="bi bi-box-seam about-pillar__icon"></i>
                    <h3 class="h6 fw-bold mt-3 mb-2">La misma seriedad</h3>
                    <p class="text-secondary small mb-0">El mismo compromiso con cada pedido, presencial u online.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="about-pillar checkout-card card p-4 h-100 text-center">
                    <i class="bi bi-clock-history about-pillar__icon"></i>
                    <h3 class="h6 fw-bold mt-3 mb-2">El mismo cumplimiento</h3>
                    <p class="text-secondary small mb-0">En tiempo y forma, como siempre lo hemos hecho.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="about-pillar checkout-card card p-4 h-100 text-center">
                    <i class="bi bi-hand-thumbs-up about-pillar__icon"></i>
                    <h3 class="h6 fw-bold mt-3 mb-2">La misma confianza</h3>
                    <p class="text-secondary small mb-0">Más de una década respaldando a quienes confían en nosotros.</p>
                </div>
            </div>
        </div>
        <p class="text-center fs-5 fw-semibold mt-4 mb-0">Solo que ahora, con un clic.</p>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-6">
            <div class="checkout-card card p-4 p-lg-5 h-100 about-mvv">
                <h2 class="h5 fw-bold mb-3">Nuestra misión <span class="text-primary">(sin rodeos)</span></h2>
                <p class="text-secondary mb-0">
                    Que comprarnos por internet se sienta tan seguro como hacerlo de siempre.
                    Productos que necesitas, entregados cuando los necesitas, desde la cotización hasta tu puerta.
                </p>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="checkout-card card p-4 p-lg-5 h-100 about-mvv">
                <h2 class="h5 fw-bold mb-3">Nuestra visión</h2>
                <p class="text-secondary mb-0">
                    Seguir creciendo junto a ti, donde tú quieras comprar.
                    Ya sea en persona o en línea, seguimos siendo ese aliado en quien se puede confiar.
                </p>
            </div>
        </div>
    </div>

    <div class="checkout-card card p-4 p-lg-5 mb-5">
        <h2 class="h4 fw-bold mb-4">¿Por qué comprarnos online?</h2>
        <ul class="list-unstyled about-checklist mb-0">
            <li>
                <i class="bi bi-check-circle-fill text-success"></i>
                <span><strong>No somos nuevos</strong> — más de 10 años en el mercado</span>
            </li>
            <li>
                <i class="bi bi-check-circle-fill text-success"></i>
                <span><strong>No improvisamos</strong> — la experiencia ya la tenemos</span>
            </li>
            <li>
                <i class="bi bi-check-circle-fill text-success"></i>
                <span><strong>Nos lo pediste</strong> — y te escuchamos</span>
            </li>
        </ul>
    </div>

    <div class="text-center py-2">
        <p class="text-secondary mb-4">Conoce nuestro catálogo y compra con la confianza de siempre.</p>
        <a href="{{ route('catalog') }}" class="btn btn-primary btn-lg rounded-pill px-5">
            Ver catálogo <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
</section>
@endsection
