(() => {
    document.querySelectorAll('[data-edit-student]').forEach((button) => {
        button.addEventListener('click', () => {
            const studentId = button.getAttribute('data-edit-student');
            const dialog = studentId
                ? document.getElementById(`student-edit-dialog-${studentId}`)
                : null;
            if (dialog instanceof HTMLDialogElement) {
                dialog.showModal();
            }
        });
    });

    document.querySelectorAll('[data-student-edit-dialog]').forEach((dialog) => {
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }
        dialog.querySelectorAll('[data-close-student-dialog]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
})();
