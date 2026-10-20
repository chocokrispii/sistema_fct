<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useSessionStore } from '../stores/session';

const props = defineProps<{ csrfToken: string }>();
const root = ref<HTMLElement | null>(null);
const session = useSessionStore();
const submitting = ref(false);
let form: HTMLFormElement | null = null;

async function submitAsJson(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!form || submitting.value) {
        return;
    }

    const fields = new FormData(form);
    const email = fields.get('email');
    const password = fields.get('password');
    if (typeof email !== 'string' || typeof password !== 'string') {
        return;
    }

    submitting.value = true;
    form.setAttribute('aria-busy', 'true');
    const submitButton = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
    }

    try {
        const user = await session.login({ email, password });
        window.location.assign(`index.php?action=${session.dashboardAction(user.rol)}`);
    } catch {
        // El store conserva el error para mostrarlo junto al formulario.
    } finally {
        submitting.value = false;
        form?.removeAttribute('aria-busy');
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
}

onMounted(() => {
    session.setCsrfToken(props.csrfToken);
    form = root.value?.closest<HTMLFormElement>('form') ?? null;
    form?.addEventListener('submit', submitAsJson);
});

onBeforeUnmount(() => {
    form?.removeEventListener('submit', submitAsJson);
});
</script>

<template>
    <div ref="root" class="session-login-enhancement">
        <p v-if="submitting" class="visually-hidden" role="status" aria-live="polite">
            Iniciando sesión…
        </p>
        <p v-if="session.error" class="message message-error" role="alert">
            {{ session.error }}
        </p>
    </div>
</template>
