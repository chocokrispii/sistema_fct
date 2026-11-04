(() => {
    const resizeTextarea = (textarea) => {
        textarea.style.height = 'auto';

        const styles = window.getComputedStyle(textarea);
        const borderHeight = Number.parseFloat(styles.borderTopWidth) + Number.parseFloat(styles.borderBottomWidth);
        const maxHeight = Number.parseFloat(styles.maxHeight);
        const contentHeight = textarea.scrollHeight + borderHeight;
        const height = Number.isFinite(maxHeight) ? Math.min(contentHeight, maxHeight) : contentHeight;

        textarea.style.height = `${height}px`;
        textarea.style.overflowY = contentHeight > height ? 'auto' : 'hidden';
    };

    document.querySelectorAll('textarea').forEach(resizeTextarea);

    document.addEventListener('input', (event) => {
        if (event.target instanceof HTMLTextAreaElement) {
            resizeTextarea(event.target);
        }
    });

    window.addEventListener('resize', () => {
        document.querySelectorAll('textarea').forEach(resizeTextarea);
    });
})();
