document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    if (!(toggle instanceof HTMLInputElement) || toggle.type !== 'checkbox') {
        return;
    }

    const password = document.getElementById(toggle.dataset.passwordToggle || '');
    if (!(password instanceof HTMLInputElement) || password.type !== 'password') {
        return;
    }

    toggle.addEventListener('change', () => {
        password.type = toggle.checked ? 'text' : 'password';
    });
});
