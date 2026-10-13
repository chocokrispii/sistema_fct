(() => {
    const dialog = document.querySelector('[data-user-delete-dialog]');
    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }
    const idInput = dialog.querySelector('[data-delete-user-id]');
    const emailLabel = dialog.querySelector('[data-delete-user-email]');
    if (!(idInput instanceof HTMLInputElement) || !(emailLabel instanceof HTMLElement)) {
        return;
    }

    document.querySelectorAll('[data-delete-user]').forEach((button) => {
        button.addEventListener('click', () => {
            idInput.value = button.getAttribute('data-delete-user') || '';
            emailLabel.textContent = button.getAttribute('data-delete-email') || '';
            dialog.showModal();
        });
    });
    dialog.querySelector('[data-delete-cancel]')?.addEventListener('click', () => dialog.close());
})();
