/**
 * Vista previa de PDF en panel flotante (canvas vía PDF.js; evita iframe/plugin en gris).
 */
(function () {
    const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';

    let renderToken = 0;
    let activePdfDoc = null;

    function ensurePdfJs() {
        if (typeof pdfjsLib === 'undefined') {
            return Promise.reject(new Error('No se pudo cargar el visor PDF.'));
        }
        if (!pdfjsLib.GlobalWorkerOptions.workerSrc) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_CDN + 'pdf.worker.min.js';
        }

        return Promise.resolve(pdfjsLib);
    }

    window.CotizAdjuntoPdfPreview = {
        cancel() {
            renderToken += 1;
        },

        reset(container) {
            this.cancel();
            if (activePdfDoc) {
                try {
                    activePdfDoc.destroy();
                } catch (_e) {
                    // documento ya liberado
                }
                activePdfDoc = null;
            }
            if (container) {
                container.innerHTML = '';
            }
        },

        /**
         * @param {HTMLElement} container
         * @param {string} url
         */
        async render(container, url) {
            if (!container || !url) {
                return;
            }
            const token = ++renderToken;
            container.innerHTML = '';

            const lib = await ensurePdfJs();
            const task = lib.getDocument({ url, withCredentials: true });
            const pdf = await task.promise;

            if (token !== renderToken) {
                pdf.destroy();
                return;
            }

            activePdfDoc = pdf;

            await new Promise((resolve) => {
                requestAnimationFrame(() => resolve());
            });

            const width = container.clientWidth > 0 ? container.clientWidth : 560;

            for (let pageNum = 1; pageNum <= pdf.numPages; pageNum += 1) {
                if (token !== renderToken) {
                    return;
                }
                const page = await pdf.getPage(pageNum);
                const base = page.getViewport({ scale: 1 });
                const scale = Math.min(2.5, Math.max(0.5, (width - 20) / base.width));
                const viewport = page.getViewport({ scale });
                const canvas = document.createElement('canvas');
                canvas.className = 'cotiz-adjunto-pdf-canvas d-block mx-auto mb-2';
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                container.appendChild(canvas);
                await page.render({
                    canvasContext: canvas.getContext('2d'),
                    viewport,
                }).promise;
            }
        },
    };
})();
