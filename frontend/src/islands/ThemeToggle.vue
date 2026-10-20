<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import { useThemeStore } from '../stores/theme';

const theme = useThemeStore();

onMounted(theme.initialize);
onBeforeUnmount(theme.dispose);
</script>

<template>
    <button
        type="button"
        class="theme-toggle"
        :aria-label="theme.theme === 'light' ? 'Activar modo oscuro' : 'Activar modo claro'"
        :aria-pressed="theme.theme === 'dark'"
        :title="theme.theme === 'light' ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro'"
        @click="theme.toggle"
    >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9" fill="currentColor" />
            <path d="M12 3a9 9 0 0 1 0 18V3Z" class="theme-toggle__opposite" />
            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5" />
            <path d="M12 3v18" fill="none" stroke="currentColor" stroke-width="1.5" />
        </svg>
    </button>
</template>
