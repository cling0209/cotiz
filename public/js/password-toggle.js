(function () {
    document.querySelectorAll('.js-password-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById(button.dataset.target);

            if (!input) {
                return;
            }

            const icon = button.querySelector('i');
            const show = input.type === 'password';

            input.type = show ? 'text' : 'password';

            if (icon) {
                icon.classList.toggle('bi-eye', !show);
                icon.classList.toggle('bi-eye-slash', show);
            }

            button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });
})();
