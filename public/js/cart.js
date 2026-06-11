(function () {
    const syncForm = document.getElementById('js-cart-sync-form');
    const stockNotice = document.getElementById('js-cart-stock-notice');

    function formatClp(amount) {
        return '$' + Math.round(amount).toLocaleString('es-CL');
    }

    function getMaxStock(row) {
        return Math.max(0, parseInt(row.dataset.stock, 10) || 0);
    }

    function clampQuantity(value, maxStock) {
        const parsed = parseInt(value, 10);

        if (Number.isNaN(parsed) || parsed < 1) {
            return 1;
        }

        if (maxStock < 1) {
            return parsed;
        }

        return Math.min(parsed, 99, maxStock);
    }

    function productName(row) {
        return row.dataset.productName || 'este producto';
    }

    function showStockNotice(messages) {
        if (!stockNotice) {
            return;
        }

        if (!messages.length) {
            stockNotice.classList.add('d-none');
            stockNotice.textContent = '';

            return;
        }

        stockNotice.textContent = messages.join(' ');
        stockNotice.classList.remove('d-none');
        stockNotice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function rowStockMessage(row, quantity, maxStock) {
        const name = productName(row);

        if (maxStock < 1) {
            return 'Lamentamos informarte que ' + name + ' ya no tiene stock disponible.';
        }

        if (quantity > maxStock) {
            return 'Lamentamos informarte que solo hay ' + maxStock + ' unidad(es) disponible(s) de ' + name + '.';
        }

        return '';
    }

    function setRowAlert(row, message) {
        const cell = row.querySelector('td');

        if (!cell) {
            return;
        }

        let alert = row.querySelector('.js-cart-stock-alert');

        if (!message) {
            if (alert) {
                alert.remove();
            }

            return;
        }

        if (!alert) {
            alert = document.createElement('div');
            alert.className = 'js-cart-stock-alert small text-danger mt-1';
            cell.querySelector('div > div:last-child')?.appendChild(alert);
        }

        alert.textContent = message;
    }

    function recalculateCart() {
        let subtotal = 0;
        let itemCount = 0;

        document.querySelectorAll('[data-cart-row]').forEach(function (row) {
            const unitPrice = parseFloat(row.dataset.unitPrice) || 0;
            const maxStock = getMaxStock(row);
            const input = row.querySelector('.js-cart-quantity');
            const lineTotalCell = row.querySelector('.js-cart-line-total');

            if (!input || !lineTotalCell || input.disabled) {
                return;
            }

            const rawValue = input.value;
            const quantity = clampQuantity(rawValue, maxStock);

            if (String(quantity) !== String(rawValue)) {
                input.value = quantity;
                setRowAlert(row, rowStockMessage(row, parseInt(rawValue, 10) || quantity, maxStock));
            }

            const lineTotal = Math.round(unitPrice * quantity);

            lineTotalCell.textContent = formatClp(lineTotal);
            subtotal += lineTotal;
            itemCount += quantity;
        });

        document.querySelectorAll('.js-cart-subtotal').forEach(function (el) {
            el.textContent = formatClp(subtotal);
        });

        const countLabel = document.querySelector('.js-cart-item-count');

        if (countLabel) {
            countLabel.textContent = itemCount + ' ítems';
        }
    }

    function validateCartRows() {
        const messages = [];

        document.querySelectorAll('[data-cart-row]').forEach(function (row) {
            const maxStock = getMaxStock(row);
            const input = row.querySelector('.js-cart-quantity');

            if (!input) {
                return;
            }

            if (input.disabled || maxStock < 1) {
                messages.push('Lamentamos informarte que ' + productName(row) + ' ya no tiene stock disponible.');

                return;
            }

            const quantity = clampQuantity(input.value, maxStock);
            input.value = quantity;

            const message = rowStockMessage(row, quantity, maxStock);

            if (message) {
                messages.push(message);
                setRowAlert(row, message);
            } else {
                setRowAlert(row, '');
            }
        });

        recalculateCart();

        return messages;
    }

    document.querySelectorAll('.js-cart-quantity').forEach(function (input) {
        input.addEventListener('input', function () {
            const row = input.closest('[data-cart-row]');

            if (!row) {
                return;
            }

            const maxStock = getMaxStock(row);
            const rawValue = input.value;
            const quantity = clampQuantity(rawValue, maxStock);

            if (String(quantity) !== String(rawValue)) {
                input.value = quantity;
                setRowAlert(row, rowStockMessage(row, parseInt(rawValue, 10) || quantity, maxStock));
            } else {
                setRowAlert(row, '');
            }

            showStockNotice([]);
            recalculateCart();
        });

        input.addEventListener('change', function () {
            const row = input.closest('[data-cart-row]');

            if (!row) {
                return;
            }

            const maxStock = getMaxStock(row);
            const quantity = clampQuantity(input.value, maxStock);
            const previous = input.value;
            input.value = quantity;

            const message = rowStockMessage(row, parseInt(previous, 10) || quantity, maxStock);
            setRowAlert(row, message);
            showStockNotice(message ? [message] : []);
            recalculateCart();
        });
    });

    if (syncForm) {
        syncForm.addEventListener('submit', function (event) {
            const messages = validateCartRows();

            if (messages.length) {
                event.preventDefault();
                showStockNotice(Array.from(new Set(messages)));
            }
        });
    }
})();
