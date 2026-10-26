<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { requestApi } from '../api/client';

const props = defineProps<{ csrfToken: string }>();
const root = ref<HTMLElement | null>(null);
const status = ref<'idle' | 'submitting' | 'sent' | 'error'>('idle');
const message = ref('');
let form: HTMLFormElement | null = null;

async function submitRequest(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!form || status.value === 'submitting') {
        return;
    }

    const emailInput = form.querySelector<HTMLInputElement>('input[name="email"]');
    if (!emailInput || !emailInput.validity.valid) {
        emailInput?.focus();
        return;
    }

    status.value = 'submitting';
    message.value = '';
    form.setAttribute('aria-busy', 'true');
    const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (submit) {
        submit.disabled = true;
    }

    try {
        const result = await requestApi<{ message: string }>('api/auth/solicitar-reset-password', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrfToken },
            body: JSON.stringify({ email: emailInput.value }),
        });
        status.value = 'sent';
        message.value = result.message;
        emailInput.value = '';
        await nextTick();
        root.value?.querySelector<HTMLElement>('[role="status"]')?.focus();
    } catch (cause) {
        status.value = 'error';
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo enviar la solicitud. Inténtalo de nuevo.';
        await nextTick();
        root.value?.querySelector<HTMLElement>('[role="alert"]')?.focus();
    } finally {
        form?.removeAttribute('aria-busy');
        if (submit) {
            submit.disabled = false;
        }
    }
}

onMounted(() => {
    form = root.value?.closest<HTMLFormElement>('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de recuperación.');
    }
    form.addEventListener('submit', submitRequest);
});

onBeforeUnmount(() => {
    form?.removeEventListener('submit', submitRequest);
});
</script>

<template>
    <div ref="root" class="password-reset-request__feedback" aria-live="polite">
        <p v-if="status === 'submitting'" class="message" role="status">Enviando solicitud…</p>
        <p v-else-if="status === 'sent'" class="message message-success" role="status" tabindex="-1">{{ message }}</p>
        <p v-else-if="status === 'error'" class="message message-error" role="alert" tabindex="-1">{{ message }}</p>
    </div>
</template>
