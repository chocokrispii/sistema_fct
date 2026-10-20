(() => {
    const root = document.documentElement;
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    let savedTheme = null;

    try {
        savedTheme = window.localStorage.getItem('fct-theme');
    } catch (cause) {
        console.error('No se pudo leer la preferencia de tema guardada.', cause);
    }

    root.dataset.theme = savedTheme === 'light' || savedTheme === 'dark'
        ? savedTheme
        : prefersDark ? 'dark' : 'light';
})();
