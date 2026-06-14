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

        // Filas/thumbs insertados por AJAX suelen quedar con lazy sin disparar load.
        if (img.loading === 'lazy' || img.getAttribute('loading') === 'lazy') {
            img.loading = 'eager';
        }

        function markLoaded() {
            wrap.classList.remove('is-error');
            wrap.classList.add('is-loaded');
        }

        function markError() {
            if (img.dataset.imageFallbacks) {
                try {
                    var fallbacks = JSON.parse(img.dataset.imageFallbacks);
                    var tried = img.dataset.imageFallbacksTried ? JSON.parse(img.dataset.imageFallbacksTried) : [];
                    var next = fallbacks.find(function (url) { return tried.indexOf(url) === -1; });
                    if (next) {
                        tried.push(next);
                        img.dataset.imageFallbacksTried = JSON.stringify(tried);
                        wrap.classList.remove('is-error');
                        wrap.classList.remove('is-loaded');
                        img.src = next;
                        return;
                    }
                } catch (e) {}
            }
            wrap.classList.remove('is-loaded');
            wrap.classList.add('is-error');
        }

        function checkComplete() {
            if (img.complete) {
                if (img.naturalWidth > 0) {
                    markLoaded();
                } else {
                    markError();
                }
                return true;
            }
            return false;
        }

        if (checkComplete()) {
            return;
        }

        img.addEventListener('load', markLoaded);
        img.addEventListener('error', markError);

        // Forzar carga: innerHTML en nodo detached puede perder el evento load.
        if (img.src) {
            var src = img.getAttribute('src');
            img.removeAttribute('src');
            img.src = src;
        }

        if (checkComplete()) {
            return;
        }

        window.setTimeout(function () {
            if (!wrap.classList.contains('is-loaded') && !wrap.classList.contains('is-error')) {
                checkComplete();
            }
        }, 2500);
    }

    function initAll() {
        document.querySelectorAll('[data-product-image]').forEach(initProductImage);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    window.initProductImagesIn = function (root) {
        (root || document).querySelectorAll('[data-product-image]').forEach(initProductImage);
    };

    document.addEventListener('turbo:load', initAll);
})();
