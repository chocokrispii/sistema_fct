document.querySelectorAll('[data-app-menu]').forEach((menu) => {
    document.addEventListener('click', (e) => {
        if (menu.open && !menu.contains(e.target)) menu.open = false;
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && menu.open) {
            menu.open = false;
            menu.querySelector('summary').focus();
        }
    });
});
