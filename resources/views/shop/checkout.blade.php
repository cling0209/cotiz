@extends('layouts.shop')

@section('title', 'Finalizar compra')

@section('content')
<section class="container py-4 py-lg-5">
    <h1 class="h3 fw-bold mb-4">Finalizar compra</h1>

    @if($isLoggedIn)
        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <span>Hola, <strong>{{ $userName }}</strong>. Tus datos están precargados para esta compra.</span>
            <form method="post" action="{{ route('account.logout') }}" class="mb-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-success">Cerrar sesión</button>
            </form>
        </div>
    @else
        <div class="alert alert-light border mb-4">
            ¿Ya tienes cuenta?
            <a href="{{ route('account.login') }}">Ingresa aquí</a> para no volver a escribir tus datos.
        </div>
    @endif

    <form action="{{ route('checkout.store') }}" method="post">
        @csrf
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="checkout-card card p-4 mb-4">
                    <h2 class="h5 fw-bold mb-3">Datos de contacto</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nombre completo *</label>
                            <input type="text" name="customer_name" class="form-control @error('customer_name') is-invalid @enderror"
                                   value="{{ $defaults['customer_name'] }}" required>
                            @error('customer_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Correo electrónico *</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ $defaults['email'] }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="checkout-card card p-4 mb-4">
                    <h2 class="h5 fw-bold mb-3">Dirección de envío</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Destinatario (quien recibe la compra) *</label>
                            <input type="text" name="recipient_name" class="form-control @error('recipient_name') is-invalid @enderror"
                                   value="{{ $defaults['recipient_name'] }}" required>
                            @error('recipient_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Teléfono *</label>
                            <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror"
                                   value="{{ $defaults['phone'] }}" placeholder="+56 9..." required>
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Región *</label>
                            <select name="region" id="region" class="form-select @error('region') is-invalid @enderror" required>
                                <option value="">Selecciona región</option>
                                @foreach($regions as $region)
                                    <option value="{{ $region['region'] }}" @selected($defaults['region'] === $region['region'])>{{ $region['region'] }}</option>
                                @endforeach
                            </select>
                            @error('region')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Comuna *</label>
                            <select name="comuna" id="comuna" class="form-select @error('comuna') is-invalid @enderror" required>
                                <option value="">Selecciona comuna</option>
                            </select>
                            @error('comuna')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Calle *</label>
                            <input type="text" name="street" class="form-control @error('street') is-invalid @enderror"
                                   value="{{ $defaults['street'] }}" required>
                            @error('street')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Número</label>
                            <input type="text" name="street_number" class="form-control" value="{{ $defaults['street_number'] }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Depto</label>
                            <input type="text" name="apartment" class="form-control" value="{{ $defaults['apartment'] }}">
                        </div>
                    </div>
                </div>

                @unless($isLoggedIn)
                    <div class="checkout-card card p-4">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="create_account" id="create_account"
                                   value="1" @checked(old('create_account'))>
                            <label class="form-check-label fw-semibold" for="create_account">
                                Crear cuenta con estos datos
                            </label>
                            <div class="form-text">En tu próxima compra no tendrás que volver a completar el formulario.</div>
                        </div>
                        <div id="create-account-fields" class="row g-3 mt-3 @unless(old('create_account')) d-none @endunless">
                            <div class="col-md-6">
                                <label class="form-label" for="password">Contraseña *</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="password"
                                           class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary js-password-toggle"
                                            data-target="password" aria-label="Mostrar contraseña">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="password_confirmation">Confirmar contraseña *</label>
                                <div class="input-group">
                                    <input type="password" name="password_confirmation" id="password_confirmation"
                                           class="form-control" autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary js-password-toggle"
                                            data-target="password_confirmation" aria-label="Mostrar contraseña">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endunless
            </div>

            <div class="col-lg-5">
                <div class="checkout-card card p-4 sticky-top" style="top:5rem">
                    <h2 class="h5 fw-bold mb-3">Tu pedido</h2>
                    <ul class="list-unstyled mb-3">
                        @foreach($formatted['items'] as $item)
                            <li class="d-flex justify-content-between py-2 border-bottom">
                                <span>{{ data_get($item, 'product.name', 'Producto') }} × {{ $item['quantity'] }}</span>
                                <span>{{ clp($item['line_total']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal</span>
                        <span id="summary-subtotal">{{ clp($formatted['subtotal']) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 text-muted small">
                        <span>Envío</span>
                        <span id="summary-shipping">Selecciona región y comuna</span>
                    </div>
                    <div class="d-flex justify-content-between fs-5 fw-bold mb-4 border-top pt-3">
                        <span>Total</span>
                        <span class="text-primary" id="summary-total">—</span>
                    </div>
                    <div id="shipping-error" class="alert alert-warning small d-none"></div>
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-credit-card"></i> Serás redirigido a <strong>Webpay Plus</strong> para pagar con tarjeta de crédito o débito.
                    </div>
                    <div id="checkout-submit-hint" class="alert alert-warning border-warning small mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        El botón de pago se activará cuando ingreses todos los <strong>datos obligatorios</strong> y se calcule el envío.
                    </div>
                    <button type="submit" class="btn btn-webpay-pay btn-lg rounded-pill w-100" id="checkout-submit" disabled>
                        Pagar con Webpay <i class="bi bi-lock-fill"></i>
                    </button>
                </div>
            </div>
        </div>
    </form>
</section>
@endsection

@push('scripts')
<script>
const regions = @json($regions);
const regionSelect = document.getElementById('region');
const comunaSelect = document.getElementById('comuna');
const savedComuna = @json($defaults['comuna']);
const quoteUrl = @json(route('checkout.shipping.quote'));
const subtotalAmount = {{ (float) $formatted['subtotal'] }};

const summaryShipping = document.getElementById('summary-shipping');
const summaryTotal = document.getElementById('summary-total');
const shippingError = document.getElementById('shipping-error');
const checkoutSubmit = document.getElementById('checkout-submit');
const checkoutSubmitHint = document.getElementById('checkout-submit-hint');
const checkoutForm = checkoutSubmit.closest('form');

let shippingReady = false;

const createAccountCheckbox = document.getElementById('create_account');
const createAccountFields = document.getElementById('create-account-fields');
const passwordInput = document.getElementById('password');
const passwordConfirmInput = document.getElementById('password_confirmation');

function formatClp(amount) {
    return new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 }).format(amount);
}

function isRmRegion(regionName) {
    return regionName.toLowerCase().includes('metropolitana');
}

function toggleCreateAccountFields() {
    if (!createAccountCheckbox || !createAccountFields) return;
    const show = createAccountCheckbox.checked;
    createAccountFields.classList.toggle('d-none', !show);
    if (passwordInput) passwordInput.required = show;
    if (passwordConfirmInput) passwordConfirmInput.required = show;
    updateCheckoutSubmitState();
}

function requiredFieldsComplete() {
    if (!checkoutForm) return false;

    return Array.from(checkoutForm.querySelectorAll('[required]')).every((field) => {
        if (field.offsetParent === null || field.closest('.d-none')) {
            return true;
        }

        return String(field.value ?? '').trim() !== '';
    });
}

function updateCheckoutSubmitState() {
    const ready = shippingReady && requiredFieldsComplete();
    checkoutSubmit.disabled = !ready;

    if (checkoutSubmitHint) {
        checkoutSubmitHint.classList.toggle('d-none', ready);
    }
}

function loadComunas() {
    const regionName = regionSelect.value;
    comunaSelect.innerHTML = '<option value="">Selecciona comuna</option>';
    const region = regions.find(r => r.region === regionName);
    if (!region) {
        quoteShipping();
        return;
    }
    region.comunas.forEach(c => {
        const name = typeof c === 'string' ? c : (c.nombre || '');
        if (!name) return;
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        if (name === savedComuna) opt.selected = true;
        comunaSelect.appendChild(opt);
    });
    quoteShipping();
}

async function quoteShipping() {
    const region = regionSelect.value;
    const comuna = comunaSelect.value;
    shippingError.classList.add('d-none');

    if (!region) {
        summaryShipping.textContent = 'Selecciona región y comuna';
        summaryTotal.textContent = '—';
        shippingReady = false;
        updateCheckoutSubmitState();
        return;
    }

    if (!isRmRegion(region) && !comuna) {
        summaryShipping.textContent = 'Selecciona comuna';
        summaryTotal.textContent = '—';
        shippingReady = false;
        updateCheckoutSubmitState();
        return;
    }

    shippingReady = false;
    updateCheckoutSubmitState();
    summaryShipping.textContent = 'Calculando...';
    summaryTotal.textContent = '—';

    try {
        const params = new URLSearchParams({ region });
        if (comuna) params.set('comuna', comuna);
        const response = await fetch(`${quoteUrl}?${params.toString()}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'No se pudo calcular el envío.');
        }

        summaryShipping.textContent = formatClp(data.shipping.amount);
        summaryTotal.textContent = formatClp(data.total);
        shippingReady = true;
        updateCheckoutSubmitState();
    } catch (error) {
        summaryShipping.textContent = '—';
        summaryTotal.textContent = '—';
        shippingError.textContent = error.message;
        shippingError.classList.remove('d-none');
        shippingReady = false;
        updateCheckoutSubmitState();
    }
}

if (createAccountCheckbox) {
    createAccountCheckbox.addEventListener('change', toggleCreateAccountFields);
    toggleCreateAccountFields();
}

regionSelect.addEventListener('change', loadComunas);
comunaSelect.addEventListener('change', quoteShipping);

if (checkoutForm) {
    checkoutForm.addEventListener('input', updateCheckoutSubmitState);
    checkoutForm.addEventListener('change', updateCheckoutSubmitState);
}

if (regionSelect.value) loadComunas();
updateCheckoutSubmitState();
</script>
<script src="{{ asset('js/password-toggle.js') }}" defer></script>
@endpush
