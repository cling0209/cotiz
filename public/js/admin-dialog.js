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
    let isConfirm = false;

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
        isConfirm = false;
        btnCancel.classList.add('d-none');
        btnOk.classList.remove('btn-danger');
        btnOk.classList.add('btn-primary');
    }

    function show(options) {
        return new Promise((resolve) => {
            resolvePromise = resolve;
            isConfirm = !!options.confirm;

            titleEl.textContent = options.title || document.title.split('—')[0]?.trim() || 'Cotiz';
            bodyEl.textContent = options.message || '';
            setDialogType(options.type || (isConfirm ? 'warning' : 'info'));

            btnOk.textContent = options.okText || (isConfirm ? 'Confirmar' : 'Aceptar');
            if (isConfirm) {
                btnCancel.classList.remove('d-none');
                btnCancel.textContent = options.cancelText || 'Cancelar';
                if (options.type === 'danger') {
                    btnOk.classList.remove('btn-primary');
                    btnOk.classList.add('btn-danger');
                }
            }

            bsModal.show();
        });
    }

    btnOk.addEventListener('click', () => {
        bsModal.hide();
        if (resolvePromise) {
            resolvePromise(true);
            resolvePromise = null;
        }
    });

    btnCancel.addEventListener('click', () => {
        bsModal.hide();
        if (resolvePromise) {
            resolvePromise(false);
            resolvePromise = null;
        }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        if (resolvePromise) {
            resolvePromise(false);
            resolvePromise = null;
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
