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
