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

    function isExportHref(href) {
        return /\/(exportar|export|plantilla)(\/|\?|$)/i.test(href || '');
    }

    function isDownloadLink(link) {
        if (link.dataset.noLoader !== undefined || link.hasAttribute('download')) {
            return true;
        }

        return isExportHref(link.getAttribute('href') || '');
    }

    function parseFilename(disposition, fallback) {
        if (!disposition) {
            return fallback;
        }

        const utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8) {
            try {
                return decodeURIComponent(utf8[1].trim());
            } catch (error) {
                return utf8[1].trim();
            }
        }

        const plain = disposition.match(/filename="?([^";]+)"?/i);
        return plain ? plain[1].trim() : fallback;
    }

    function beginNavigation() {
        markNavigationPending();
        showLoader();
    }

    async function downloadWithLoader(link) {
        const href = link.getAttribute('href');
        if (!href) {
            return;
        }

        downloadUntil = 0;
        beginNavigation();

        try {
            const res = await fetch(href, {
                credentials: 'same-origin',
                headers: { Accept: '*/*' },
            });

            if (!res.ok) {
                const errorText = (await res.text()).trim();
                throw new Error(errorText || ('HTTP ' + res.status));
            }

            const blob = await res.blob();
            const filename = parseFilename(
                res.headers.get('Content-Disposition'),
                'descarga_' + Date.now()
            );

            const objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = filename;
            anchor.style.display = 'none';
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(objectUrl);
        } catch (error) {
            const message = error instanceof Error && error.message
                ? error.message
                : 'No se pudo completar la descarga. Intente nuevamente.';
            alert(message);
        } finally {
            downloadUntil = Date.now() + 1500;
            hideLoader();
        }
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

        const href = link.getAttribute('href') || '';

        if (isExportHref(href) && link.dataset.noLoader === undefined) {
            event.preventDefault();
            downloadWithLoader(link);

            return;
        }

        if (isDownloadLink(link)) {
            markDownloadIntent();

            return;
        }

        if (link.target === '_blank') {
            return;
        }

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
