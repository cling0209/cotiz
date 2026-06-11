@push('head')
<script>
(function () {
    try {
        if (sessionStorage.getItem('page-loader-pending') === '1' || document.readyState !== 'complete') {
            document.documentElement.classList.add('page-loader-active');
        }
    } catch (e) {}
})();
</script>
@endpush

<div id="page-loader" aria-hidden="true" aria-live="polite" role="status">
    <div class="page-loader__scene">
        <span class="page-loader__track" aria-hidden="true"></span>
        <img
            src="{{ asset('images/cart-loader.svg') }}"
            class="page-loader__cart"
            alt=""
            width="88"
            height="56"
        >
    </div>
</div>
