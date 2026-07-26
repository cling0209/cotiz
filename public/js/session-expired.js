/**
 * Sesión vencida en llamadas AJAX del panel: avisa y lleva al login.
 *
 * En navegación normal Laravel redirige al login (redirectGuestsTo), pero los
 * fetch del admin piden JSON, así que reciben 401 (sin sesión) o 419 (token CSRF
 * vencido) y cada pantalla mostraba el número crudo. Acá se centraliza el aviso.
 */
(function (global) {
    'use strict';

    const originalFetch = global.fetch;
    if (typeof originalFetch !== 'function') {
        return;
    }

    const ESPERA_MAX_MS = 10000;
    let enCurso = false;

    function config() {
        return global.CotizSesionConfig || {};
    }

    function loginUrl() {
        return String(config().loginUrl || '/admin/login').trim();
    }

    function urlDeEntrada(input) {
        if (typeof input === 'string') {
            return input;
        }
        if (typeof URL === 'function' && input instanceof URL) {
            return input.href;
        }
        if (input && typeof input.url === 'string') {
            return input.url;
        }
        return '';
    }

    function esMismoOrigen(input) {
        const cruda = urlDeEntrada(input);
        if (cruda === '') {
            return false;
        }
        try {
            return new URL(cruda, global.location.href).origin === global.location.origin;
        } catch (e) {
            return false;
        }
    }

    function destinoLogin() {
        const base = loginUrl();
        const volver = global.location.pathname + global.location.search;
        return base + (base.includes('?') ? '&' : '?') + 'volver=' + encodeURIComponent(volver);
    }

    function esperaMaxima() {
        return new Promise(function (resolve) {
            global.setTimeout(resolve, ESPERA_MAX_MS);
        });
    }

    /**
     * Muestra el aviso y ejecuta la salida. Si el diálogo no está disponible o el
     * usuario no responde, sale igual pasado ESPERA_MAX_MS.
     */
    function avisar(mensaje, salir) {
        if (enCurso) {
            return;
        }
        enCurso = true;

        let aviso = null;
        try {
            if (global.AdminDialog && typeof global.AdminDialog.alert === 'function') {
                aviso = global.AdminDialog.alert(mensaje, {
                    title: 'Sesión vencida',
                    type: 'warning',
                    okText: 'Continuar',
                });
            }
        } catch (e) {
            aviso = null;
        }

        const listo = aviso ? Promise.race([aviso, esperaMaxima()]) : Promise.resolve();
        listo.then(salir);
    }

    function manejarRespuesta(res, input) {
        if (res.status !== 401 && res.status !== 419) {
            return;
        }
        if (!esMismoOrigen(input)) {
            return;
        }

        if (res.status === 401) {
            const destino = destinoLogin();
            avisar(
                'Su sesión venció. Lo llevamos al inicio de sesión; al ingresar de nuevo volverá a esta misma pantalla.',
                function () {
                    global.location.href = destino;
                },
            );
            return;
        }

        // 419: el token CSRF caducó. Recargar renueva el token si la sesión sigue
        // viva, y si no, Laravel manda al login.
        avisar(
            'La página estuvo abierta demasiado tiempo y su token de seguridad venció. Se va a recargar para continuar.',
            function () {
                global.location.reload();
            },
        );
    }

    global.fetch = function (input, init) {
        return originalFetch.call(this, input, init).then(function (res) {
            try {
                manejarRespuesta(res, input);
            } catch (e) {
                // Un fallo del aviso no debe romper la llamada original.
            }
            return res;
        });
    };
})(typeof window !== 'undefined' ? window : this);
