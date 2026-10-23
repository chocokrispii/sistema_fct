<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useSessionStore } from '../stores/session';

const props = defineProps<{ csrfToken: string }>();
const session = useSessionStore();
const root = ref<HTMLElement | null>(null);
const submitting = ref(false);
const error = ref('');
onMounted(() => {
    session.setCsrfToken(props.csrfToken);
    const fallback = root.value?.closest('.app-menu__panel')?.querySelector<HTMLFormElement>('.session-fallback')
        ?? root.value?.closest('nav')?.querySelector<HTMLFormElement>('.session-fallback');
    if (fallback) {
        fallback.hidden = true;
    }
});

async function logout(): Promise<void> {
    if (submitting.value) {
        return;
    }
    submitting.value = true;
    error.value = '';
    try {
        await session.logout();
        window.location.assign('index.php?action=login');
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'No se pudo cerrar la sesión.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <span ref="root" class="session-controls">
        <button type="button" :disabled="submitting" @click="logout">Cerrar sesión</button>
        <span v-if="error" class="message message-error" role="alert">{{ error }}</span>
    </span>
</template>
