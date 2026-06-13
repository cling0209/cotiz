(function () {
    const root = document.querySelector('.agile-recepcion');
    if (!root) return;

    const nronota = root.dataset.nronota;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let filaActual = null;

    function parseFactor(text) {
        const t = String(text || '').trim().replace(',', '.');
        if (!/^\d+(?:\.\d{1,2})?$/.test(t)) return null;
        const n = parseFloat(t);
        return n > 0 ? n : null;
    }

    function parseEntero(text) {
        return parseInt(String(text || '0').replace(/\D/g, ''), 10) || 0;
    }

    function formatMoney(n) {
        return Math.round(Number(n) || 0).toLocaleString('es-CL');
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        }).then(async (r) => {
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.error || data.message || 'Error de servidor');
            return data;
        });
    }

    function getRow(fila) {
        return document.querySelector(`#tabla-agile-detalle tr[data-fila="${fila}"]`);
    }

    function findRowByLinea(orden, prodItemAgile) {
        return Array.from(document.querySelectorAll('#tabla-agile-detalle tbody tr')).find(
            (tr) => String(tr.dataset.orden) === String(orden)
                && String(tr.dataset.prodItemAgile) === String(prodItemAgile)
        ) || null;
    }

    function flashPrecioActualizado(el) {
        if (!el) return;
        el.classList.remove('agile-precio-actualizado');
        void el.offsetWidth;
        el.classList.add('agile-precio-actualizado');
        window.setTimeout(() => el.classList.remove('agile-precio-actualizado'), 1400);
    }

    function mostrarFactorOk(texto) {
        const ok = document.getElementById('factorAplicadoOk');
        if (!ok) return;
        ok.textContent = texto;
        ok.style.display = 'inline';
        window.clearTimeout(mostrarFactorOk._timer);
        mostrarFactorOk._timer = window.setTimeout(() => {
            ok.style.display = 'none';
        }, 5000);
    }

    function actualizarAvisoPrecio() {
        const celdas = document.querySelectorAll('#tabla-agile-detalle .fecha-precio-cell');
        let hay = false;
        celdas.forEach((td) => {
            if (td.getAttribute('data-fecha-precio-antigua') === '1') hay = true;
        });
        const hid = document.getElementById('detalle_hay_precio_no_actualizado');
        if (hid) hid.value = hay ? '1' : '0';
    }

    function setCostoFila(fila, costo) {
        const hidden = document.getElementById(`valor_costo_${fila}`);
        if (hidden) hidden.value = costo;
        const row = getRow(fila);
        const cell = row?.querySelector('.costo-cell');
        if (cell) cell.textContent = formatMoney(costo);
    }

    function guardarPrecioFila(fila, extra = {}) {
        const row = getRow(fila);
        if (!row) return Promise.reject(new Error('Fila no encontrada'));

        const orden = parseInt(row.dataset.orden, 10);
        const prodItemAgile = row.dataset.prodItemAgile;
        const prodItem = document.getElementById(`prod_item_${fila}`)?.value?.trim() || '';
        const ventaInput = document.getElementById(`valor_venta_${fila}`);
        const prodValor = ventaInput ? parseEntero(ventaInput.value) : 0;
        const factor = document.getElementById('porcentaje')?.value;

        return postJson(`/admin/agile-recepcion/${nronota}/lineas/precio`, {
            orden,
            prod_item_agile: prodItemAgile,
            prod_item: prodItem,
            prod_valor: prodValor,
            factor_precio_venta: factor,
            ...extra,
        }).then((j) => {
            if (ventaInput && j.prod_valor !== undefined) {
                ventaInput.value = j.prod_valor;
            }
            if (j.prod_valor_costo !== undefined) {
                setCostoFila(fila, j.prod_valor_costo);
            }
            const subCell = row.querySelector('.subtotal-cell');
            if (subCell) subCell.textContent = formatMoney(j.subtotal);
            if (j.prod_item) {
                const inp = document.getElementById(`prod_item_${fila}`);
                if (inp) inp.value = j.prod_item;
            }
            const fechaCell = row.querySelector('.fecha-precio-cell');
            if (fechaCell && j.prod_valor_fecha_fmt) {
                fechaCell.textContent = j.prod_valor_fecha_fmt;
                const antigua = j.prod_valor_fecha_antigua === 1;
                fechaCell.classList.toggle('text-danger', antigua);
                fechaCell.classList.toggle('fw-bold', antigua);
                fechaCell.setAttribute('data-fecha-precio-antigua', antigua ? '1' : '0');
                actualizarAvisoPrecio();
            }
            return j;
        });
    }

    document.querySelectorAll('.venta-input').forEach((input) => {
        input.addEventListener('change', function () {
            const fila = this.id.replace('valor_venta_', '');
            guardarPrecioFila(fila).catch((e) => alert(e.message));
        });
    });

    const btnFactor = document.getElementById('btnAceptarPorcentaje');
    if (btnFactor) {
        const btnFactorLabel = btnFactor.textContent;

        btnFactor.addEventListener('click', () => {
            const val = document.getElementById('porcentaje')?.value;
            const err = document.getElementById('porcentajeError');
            const ok = document.getElementById('factorAplicadoOk');
            if (parseFactor(val) === null) {
                if (err) err.style.display = 'inline';
                if (ok) ok.style.display = 'none';
                return;
            }
            if (err) err.style.display = 'none';
            if (ok) ok.style.display = 'none';

            btnFactor.disabled = true;
            btnFactor.textContent = 'Aplicando…';

            postJson(`/admin/agile-recepcion/${nronota}/factor`, { factor_precio_venta: val })
                .then((j) => {
                    const m = document.getElementById('factor_precio_venta_mostrado');
                    if (m) m.textContent = j.factor_precio_venta_fmt;

                    let preciosCambiados = 0;
                    let lineasSinCosto = 0;

                    (j.lineas || []).forEach((linea) => {
                        const row = findRowByLinea(linea.orden, linea.prod_item_agile);
                        if (!row) return;

                        const fila = row.dataset.fila;
                        const ventaInput = document.getElementById(`valor_venta_${fila}`);
                        const subCell = row.querySelector('.subtotal-cell');
                        const costo = parseEntero(document.getElementById(`valor_costo_${fila}`)?.value);

                        if (costo <= 0) {
                            lineasSinCosto += 1;
                        }

                        const ventaAnterior = ventaInput ? parseEntero(ventaInput.value) : 0;
                        const ventaNueva = parseEntero(linea.prod_valor);
                        const cambio = ventaNueva !== ventaAnterior;

                        if (ventaInput) ventaInput.value = ventaNueva;
                        if (subCell) subCell.textContent = formatMoney(linea.subtotal);

                        if (cambio) {
                            preciosCambiados += 1;
                            flashPrecioActualizado(ventaInput?.closest('td') || ventaInput);
                            flashPrecioActualizado(subCell);
                        }
                    });

                    let mensaje = `Factor ${j.factor_precio_venta_fmt} guardado.`;
                    if (preciosCambiados > 0) {
                        mensaje += ` ${preciosCambiados} precio${preciosCambiados === 1 ? '' : 's'} actualizado${preciosCambiados === 1 ? '' : 's'}.`;
                    } else {
                        mensaje += ' Los precios ya coincidían con ese factor.';
                    }
                    if (lineasSinCosto > 0) {
                        mensaje += ` ${lineasSinCosto} línea${lineasSinCosto === 1 ? '' : 's'} sin costo (no se recalcula venta).`;
                    }
                    mostrarFactorOk(mensaje);
                })
                .catch((e) => alert(e.message))
                .finally(() => {
                    btnFactor.disabled = false;
                    btnFactor.textContent = btnFactorLabel;
                });
        });
    }

    document.querySelectorAll('.btn-buscar-prod').forEach((btn) => {
        btn.addEventListener('click', () => abrirPopup(btn.dataset.fila));
    });

    function abrirPopup(fila) {
        filaActual = fila;
        const row = getRow(fila);
        const popup = document.getElementById('popupProducto');
        const desc = row?.children[1]?.textContent?.trim() || '';
        document.getElementById('popupTextoOriginal').textContent = desc;
        document.getElementById('textoBusqueda').value = desc;
        document.getElementById('resultadosProductos').innerHTML = '';
        if (popup) popup.style.display = 'flex';
        buscarProductosPopup();
    }

    function cerrarPopup() {
        const popup = document.getElementById('popupProducto');
        if (popup) popup.style.display = 'none';
        filaActual = null;
    }

    document.getElementById('cerrarPopupProducto')?.addEventListener('click', cerrarPopup);
    document.getElementById('btnBuscarProductoPopup')?.addEventListener('click', buscarProductosPopup);
    document.getElementById('btnLimpiarBusquedaPopup')?.addEventListener('click', () => {
        document.getElementById('textoBusqueda').value = '';
        document.getElementById('resultadosProductos').innerHTML = '';
    });

    function buscarProductosPopup() {
        const q = document.getElementById('textoBusqueda')?.value?.trim() || '';
        const cont = document.getElementById('resultadosProductos');
        if (!cont) return;

        fetch(`/admin/agile-recepcion/productos/buscar?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data) => {
                const items = data.productos || [];
                if (!items.length) {
                    cont.innerHTML = '<p class="text-muted small">Sin resultados.</p>';
                    return;
                }
                let html = '<table class="table table-sm table-hover"><thead><tr><th>Código</th><th>Nombre</th><th>Costo</th><th>Venta</th><th></th></tr></thead><tbody>';
                items.forEach((p) => {
                    html += `<tr>
                        <td>${p.codigo}</td>
                        <td>${p.nombre}</td>
                        <td>${formatMoney(p.precio_costo)}</td>
                        <td>${formatMoney(p.precio_venta)}</td>
                        <td><button type="button" class="btn btn-sm btn-primary btn-seleccionar-prod" data-codigo="${p.codigo}" data-costo="${p.precio_costo}" data-venta="${p.precio_venta}" data-nombre="${p.nombre.replace(/"/g, '&quot;')}">Seleccionar</button></td>
                    </tr>`;
                });
                html += '</tbody></table>';
                cont.innerHTML = html;
                cont.querySelectorAll('.btn-seleccionar-prod').forEach((b) => {
                    b.addEventListener('click', () => seleccionarProducto(b.dataset.codigo, b.dataset.costo, b.dataset.venta, b.dataset.nombre));
                });
            })
            .catch((e) => {
                cont.innerHTML = `<p class="text-danger small">${e.message}</p>`;
            });
    }

    function seleccionarProducto(codigo, costo, venta, nombre) {
        if (filaActual === null) return;
        const inp = document.getElementById(`prod_item_${filaActual}`);
        const ventaInput = document.getElementById(`valor_venta_${filaActual}`);
        if (inp) inp.value = codigo;
        setCostoFila(filaActual, parseEntero(costo));
        if (ventaInput) ventaInput.value = parseEntero(venta);
        const row = getRow(filaActual);
        const nombreCell = row?.querySelector('.prod-nombre-cell');
        if (nombreCell) nombreCell.textContent = nombre;

        guardarPrecioFila(filaActual)
            .then(() => cerrarPopup())
            .catch((e) => alert(e.message));
    }

    const btnAprobar = document.getElementById('btnAprobar');
    if (btnAprobar) {
        btnAprobar.addEventListener('click', () => {
            const filas = document.querySelectorAll('#tabla-agile-detalle tbody tr');
            const sinPrecio = [];
            filas.forEach((row) => {
                const ventaInput = row.querySelector('.venta-input');
                const pv = ventaInput ? ventaInput.value : row.children[6]?.textContent || '0';
                const n = parseEntero(pv);
                if (n <= 0) {
                    sinPrecio.push(row.children[1]?.textContent?.trim() || 'producto');
                }
            });
            if (sinPrecio.length) {
                alert('No se puede aprobar: precio venta en cero:\n' + sinPrecio.join('\n'));
                return;
            }

            const hayAntigua = document.getElementById('detalle_hay_precio_no_actualizado')?.value === '1';
            if (hayAntigua) {
                const m = document.getElementById('detalle_umbral_precio_meses')?.value || '?';
                if (!confirm(`Hay precios con fecha antigua (umbral ${m} mes/es). ¿Aprobar de todas formas?`)) return;
            }

            if (!confirm('¿Está seguro de aprobar? No se podrán hacer cambios.')) return;

            postJson(`/admin/agile-recepcion/${nronota}/aprobar`, {})
                .then((j) => {
                    window.location.href = `/admin/cotizaciones/${j.nronota}`;
                })
                .catch((e) => alert(e.message));
        });
    }
})();
