/**
 * Keep-alive Render free desde el browser (mismo patrón que wake al sitio par).
 * Tráfico HTTP entrante real: Image + fetch + iframe oculto a /up (+ login).
 * Usar start() mientras hay jobs/procesos largos; stop() al terminar.
 */
(function (global) {
    'use strict';

    const IFRAME_ID = 'cotiz-render-keepalive-iframe';
    const DEFAULT_INTERVAL_MS = 60000;

    let intervalId = null;
    let active = false;

    function config() {
        return global.CotizRenderKeepAliveConfig || {};
    }

    function isEnabled() {
        return config().enabled === true;
    }

    function upUrl() {
        return String(config().upUrl || '/up').trim();
    }

    function loginUrl() {
        return String(config().loginUrl || '/admin/login').trim();
    }

    function intervalMs() {
        const n = Number(config().intervalMs);
        return Number.isFinite(n) && n >= 15000 ? n : DEFAULT_INTERVAL_MS;
    }

    function withWakeParam(url) {
        if (!url) {
            return '';
        }
        const stamp = String(Date.now());
        return url + (url.includes('?') ? '&' : '?') + '_wake=' + stamp;
    }

    function pingUrl(url) {
        if (!url) {
            return;
        }
        const withTs = withWakeParam(url);
        try {
            const img = new Image();
            img.referrerPolicy = 'no-referrer';
            img.src = withTs;
        } catch (e) {
            // ignore
        }
        try {
            fetch(withTs, {
                mode: 'no-cors',
                cache: 'no-store',
                credentials: 'omit',
                keepalive: true,
            }).catch(function () {});
        } catch (e) {
            // ignore
        }
    }

    function ensureIframe(src) {
        if (!src || typeof document === 'undefined') {
            return;
        }
        let iframe = document.getElementById(IFRAME_ID);
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = IFRAME_ID;
            iframe.setAttribute('aria-hidden', 'true');
            iframe.tabIndex = -1;
            iframe.style.cssText =
                'position:absolute;width:0;height:0;border:0;opacity:0;pointer-events:none;left:-9999px;';
            document.body.appendChild(iframe);
        }
        iframe.src = withWakeParam(src);
    }

    function clearIframe() {
        if (typeof document === 'undefined') {
            return;
        }
        const iframe = document.getElementById(IFRAME_ID);
        if (iframe) {
            iframe.removeAttribute('src');
        }
    }

    function ping() {
        if (!isEnabled()) {
            return;
        }
        const wake = upUrl();
        const login = loginUrl();
        pingUrl(wake);
        ensureIframe(login || wake);
    }

    function start() {
        if (!isEnabled()) {
            return;
        }
        active = true;
        ping();
        if (intervalId) {
            clearInterval(intervalId);
        }
        intervalId = setInterval(function () {
            if (active) {
                ping();
            }
        }, intervalMs());
    }

    function stop() {
        active = false;
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
        clearIframe();
    }

    function isActive() {
        return active;
    }

    global.CotizRenderKeepAlive = {
        ping: ping,
        start: start,
        stop: stop,
        isActive: isActive,
    };
})(typeof window !== 'undefined' ? window : this);
