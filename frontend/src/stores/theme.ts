import { defineStore } from 'pinia';
import { ref } from 'vue';

type Theme = 'light' | 'dark';

const storageKey = 'fct-theme';

export const useThemeStore = defineStore('theme', () => {
    const theme = ref<Theme>('light');
    const initialized = ref(false);
    let mediaQuery: MediaQueryList | null = null;

    function savedTheme(): Theme | null {
        try {
            const value = localStorage.getItem(storageKey);
            return value === 'light' || value === 'dark' ? value : null;
        } catch (cause) {
            console.error('No se pudo leer la preferencia de tema guardada.', cause);
            return null;
        }
    }

    function apply(nextTheme: Theme): void {
        theme.value = nextTheme;
        document.documentElement.dataset.theme = nextTheme;
    }

    function onSystemThemeChange(event: MediaQueryListEvent): void {
        if (savedTheme() === null) {
            apply(event.matches ? 'dark' : 'light');
        }
    }

    function initialize(): void {
        if (initialized.value) {
            return;
        }
        mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        apply(savedTheme() ?? (mediaQuery.matches ? 'dark' : 'light'));
        mediaQuery.addEventListener('change', onSystemThemeChange);
        initialized.value = true;
    }

    function toggle(): void {
        const nextTheme = theme.value === 'light' ? 'dark' : 'light';
        apply(nextTheme);
        try {
            localStorage.setItem(storageKey, nextTheme);
        } catch (cause) {
            console.error('No se pudo guardar la preferencia de tema.', cause);
        }
    }

    function dispose(): void {
        mediaQuery?.removeEventListener('change', onSystemThemeChange);
        mediaQuery = null;
        initialized.value = false;
    }

    return { theme, initialize, toggle, dispose };
});
