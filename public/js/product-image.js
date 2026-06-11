(function () {
    function initProductImage(wrap) {
        if (wrap.dataset.productImageInit === '1') {
            return;
        }
        wrap.dataset.productImageInit = '1';

        if (wrap.classList.contains('is-error')) {
            return;
        }

        var img = wrap.querySelector('.product-image__img');
        if (!img) {
            wrap.classList.add('is-error');
            return;
        }

        function markLoaded() {
            wrap.classList.remove('is-error');
            wrap.classList.add('is-loaded');
        }

        function markError() {
            wrap.classList.remove('is-loaded');
            wrap.classList.add('is-error');
        }

        if (img.complete) {
            if (img.naturalWidth > 0) {
                markLoaded();
            } else {
                markError();
            }
            return;
        }

        img.addEventListener('load', markLoaded);
        img.addEventListener('error', markError);
    }

    function initAll() {
        document.querySelectorAll('[data-product-image]').forEach(initProductImage);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    document.addEventListener('turbo:load', initAll);
})();
