(function () {
    const loader = document.getElementById('page-loader');
    if (!loader) return;

    const PENDING_KEY = 'page-loader-pending';
    let downloadUntil = 0;

    function markNavigationPending() {
        try {
            sessionStorage.setItem(PENDING_KEY, '1');
        } catch (error) {
            // sessionStorage unavailable
        }
    }

    function clearNavigationPending() {
        try {
            sessionStorage.removeItem(PENDING_KEY);
        } catch (error) {
            // sessionStorage unavailable
        }
    }

    function isNavigationPending() {
        try {
            return sessionStorage.getItem(PENDING_KEY) === '1';
        } catch (error) {
            return false;
        }
    }

    function showLoader() {
        if (Date.now() < downloadUntil) {
            return;
        }

        loader.classList.add('is-active');
        loader.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('page-loader-active');
        document.body.classList.add('is-loading');
    }

    function hideLoader() {
        loader.classList.remove('is-active');
        loader.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('page-loader-active');
        document.body.classList.remove('is-loading');
        clearNavigationPending();
    }

    function markDownloadIntent() {
        downloadUntil = Date.now() + 8000;
        hideLoader();
    }

    function isDownloadLink(link) {
        if (link.dataset.noLoader !== undefined || link.hasAttribute('download')) {
            return true;
        }

        const href = link.getAttribute('href') || '';

        return /\/(exportar|export|plantilla)(\/|\?|$)/i.test(href);
    }

    function beginNavigation() {
        markNavigationPending();
        showLoader();
    }

    window.PageLoader = { show: showLoader, hide: hideLoader };

    if (document.readyState !== 'complete' || isNavigationPending()) {
        showLoader();
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a');

        if (!link) {
            return;
        }

        if (isDownloadLink(link)) {
            markDownloadIntent();

            return;
        }

        if (link.target === '_blank') {
            return;
        }

        const href = link.getAttribute('href');

        if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
            return;
        }

        if (link.origin !== window.location.origin) {
            return;
        }

        beginNavigation();
    });

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) {
            return;
        }

        const form = event.target;

        if (form instanceof HTMLFormElement && form.dataset.noLoader !== undefined) {
            return;
        }

        beginNavigation();
    });

    window.addEventListener('beforeunload', () => {
        if (Date.now() < downloadUntil) {
            return;
        }

        markNavigationPending();
        showLoader();
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            hideLoader();
        }
    });

    window.addEventListener('focus', () => {
        if (Date.now() < downloadUntil) {
            hideLoader();
        }
    });

    window.addEventListener('load', hideLoader);
})();
