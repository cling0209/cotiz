(function () {
    const STORAGE_PREFIX = 'cotiz_por_pagina_';
    const OPCIONES = [20, 40, 60];
    const DEFAULT = 20;

    function normalizar(valor) {
        const n = Number.parseInt(String(valor), 10);
        return OPCIONES.includes(n) ? n : DEFAULT;
    }

    function claveStorage(screenKey) {
        return STORAGE_PREFIX + String(screenKey || 'default').replace(/[^a-zA-Z0-9._-]+/g, '_');
    }

    function paramsUrl() {
        return new URLSearchParams(window.location.search);
    }

    document.querySelectorAll('[data-listado-por-pagina]').forEach((root) => {
        const screenKey = root.getAttribute('data-screen-key') || 'default';
        const storageKey = claveStorage(screenKey);
        const actual = normalizar(root.getAttribute('data-por-pagina'));
        const params = paramsUrl();

        if (!params.has('por_pagina')) {
            let guardado = null;
            try {
                guardado = localStorage.getItem(storageKey);
            } catch (_e) {
                guardado = null;
            }

            if (guardado !== null) {
                const preferido = normalizar(guardado);
                if (preferido !== actual) {
                    params.set('por_pagina', String(preferido));
                    params.set('page', '1');
                    window.location.replace(window.location.pathname + '?' + params.toString());
                    return;
                }
            }
        }

        try {
            localStorage.setItem(storageKey, String(actual));
        } catch (_e) {
            // Ignorar quota / modo privado
        }

        const select = root.querySelector('select[name="por_pagina"]');
        if (select) {
            select.addEventListener('change', () => {
                try {
                    localStorage.setItem(storageKey, String(normalizar(select.value)));
                } catch (_e) {
                    // Ignorar
                }
            });
        }
    });
})();
