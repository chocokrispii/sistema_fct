document.querySelectorAll('[data-dismissible]').forEach((box) => {
    const btn = box.querySelector('[data-dismiss]');
    if (btn) btn.addEventListener('click', () => box.remove());
});
