<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urlTpl = @json(route('admin.compra-agil.resultados.consultar-individual', ['nronota' => '__NRO__']));

    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.btn-consultar-mp-individual');
        if (!btn || btn.disabled) {
            return;
        }

        const nronota = btn.dataset.nronota;
        if (!nronota) {
            return;
        }

        if (!confirm('¿Consultar la nota ' + nronota + ' en Mercado Público?')) {
            return;
        }

        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';

        try {
            const res = await fetch(urlTpl.replace('__NRO__', nronota), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            const data = await res.json().catch(function () { return {}; });

            if (!res.ok) {
                alert(data.error || 'Error al consultar Mercado Público.');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                return;
            }

            window.location.reload();
        } catch (err) {
            alert('Error de red al consultar Mercado Público.');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });
})();
</script>
