/**
 * Diálogos modales Bootstrap para reemplazar alert() y confirm() nativos.
 */
(function () {
    const modalEl = document.getElementById('adminDialogModal');
    if (!modalEl || typeof bootstrap === 'undefined') {
        return;
    }

    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static' });
    const titleEl = document.getElementById('adminDialogTitle');
    const bodyEl = document.getElementById('adminDialogBody');
    const iconEl = document.getElementById('adminDialogIcon');
    const btnOk = document.getElementById('adminDialogBtnOk');
    const btnCancel = document.getElementById('adminDialogBtnCancel');

    let resolvePromise = null;
    let mode = 'alert'; // alert | confirm | prompt
    let promptInput = null;

    const iconMap = {
        info: ['bi-info-circle-fill', 'admin-dialog-icon--info'],
        warning: ['bi-exclamation-triangle-fill', 'admin-dialog-icon--warning'],
        danger: ['bi-exclamation-octagon-fill', 'admin-dialog-icon--danger'],
        success: ['bi-check-circle-fill', 'admin-dialog-icon--success'],
    };

    function setDialogType(type) {
        const [icon, colorClass] = iconMap[type] || iconMap.info;
        iconEl.className = 'bi ' + icon + ' admin-dialog-icon ' + colorClass;
    }

    function resetModal() {
        mode = 'alert';
        promptInput = null;
        btnCancel.classList.add('d-none');
        btnOk.classList.remove('btn-danger');
        btnOk.classList.add('btn-primary');
        bodyEl.replaceChildren();
    }

    function show(options) {
        return new Promise((resolve) => {
            resolvePromise = resolve;
            mode = options.confirm ? 'confirm' : 'alert';
            promptInput = null;

            titleEl.textContent = options.title || document.title.split('—')[0]?.trim() || 'Cotiz';
            bodyEl.replaceChildren();
            bodyEl.textContent = options.message || '';
            setDialogType(options.type || (mode === 'confirm' ? 'warning' : 'info'));

            btnOk.textContent = options.okText || (mode === 'confirm' ? 'Confirmar' : 'Aceptar');
            if (mode === 'confirm') {
                btnCancel.classList.remove('d-none');
                btnCancel.textContent = options.cancelText || 'Cancelar';
                if (options.type === 'danger') {
                    btnOk.classList.remove('btn-primary');
                    btnOk.classList.add('btn-danger');
                }
            } else {
                btnCancel.classList.add('d-none');
            }

            bsModal.show();
        });
    }

    function showPrompt(options) {
        return new Promise((resolve) => {
            resolvePromise = resolve;
            mode = 'prompt';

            titleEl.textContent = options.title || 'Cotiz';
            bodyEl.replaceChildren();

            const msg = document.createElement('p');
            msg.className = 'mb-2';
            msg.textContent = options.message || '';
            bodyEl.appendChild(msg);

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm text-uppercase';
            input.maxLength = options.maxLength || 100;
            input.placeholder = options.placeholder || '';
            input.value = options.defaultValue || '';
            input.autocomplete = 'off';
            input.id = 'adminDialogPromptInput';
            bodyEl.appendChild(input);
            promptInput = input;

            if (options.errorText) {
                const err = document.createElement('div');
                err.className = 'text-danger small mt-2';
                err.textContent = options.errorText;
                bodyEl.appendChild(err);
            }

            setDialogType(options.type || 'warning');
            btnOk.textContent = options.okText || 'Aceptar';
            btnCancel.classList.remove('d-none');
            btnCancel.textContent = options.cancelText || 'Cancelar';
            btnOk.classList.remove('btn-danger');
            btnOk.classList.add('btn-primary');

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    btnOk.click();
                }
            });

            modalEl.addEventListener('shown.bs.modal', function onShown() {
                modalEl.removeEventListener('shown.bs.modal', onShown);
                input.focus();
                input.select();
            });

            bsModal.show();
        });
    }

    function settle(value) {
        if (!resolvePromise) {
            return;
        }
        const resolve = resolvePromise;
        resolvePromise = null;
        resolve(value);
    }

    btnOk.addEventListener('click', () => {
        if (mode === 'prompt') {
            const value = String(promptInput?.value || '').trim();
            bsModal.hide();
            settle(value);
            return;
        }
        bsModal.hide();
        settle(true);
    });

    btnCancel.addEventListener('click', () => {
        bsModal.hide();
        settle(mode === 'prompt' ? null : false);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        if (resolvePromise) {
            settle(mode === 'prompt' ? null : false);
        }
        resetModal();
    });

    window.AdminDialog = {
        alert(message, opts = {}) {
            return show({ ...opts, message, confirm: false });
        },
        confirm(message, opts = {}) {
            return show({ ...opts, message, confirm: true, type: opts.type || 'warning' });
        },
        prompt(message, opts = {}) {
            return showPrompt({ ...opts, message });
        },
    };

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const msg = form.getAttribute('data-confirm');
        if (!msg || form.dataset.adminDialogConfirmed === '1') {
            return;
        }

        e.preventDefault();
        AdminDialog.confirm(msg).then((ok) => {
            if (ok) {
                form.dataset.adminDialogConfirmed = '1';
                form.submit();
            }
        });
    });
})();
