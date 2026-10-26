<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { requestApi } from '../api/client';

const props = defineProps<{ csrfToken: string; token: string }>();
const root = ref<HTMLElement | null>(null);
const status = ref<'idle' | 'submitting' | 'error'>('idle');
const message = ref('');
let form: HTMLFormElement | null = null;

function resetPasswordFields(): void {
    form?.querySelectorAll<HTMLInputElement>('input[type="password"]').forEach((input) => {
        input.value = '';
    });
}

function validatePassword(password: string, confirmation: string): string | null {
    const bytes = new TextEncoder().encode(password).length;
    if (bytes < 12 || bytes > 72 || password.trim() === '') {
        return 'La contraseña debe tener entre 12 y 72 bytes y no puede ser solo espacios.';
    }
    if (!/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/[0-9]/.test(password)) {
        return 'La contraseña debe incluir al menos una minúscula, una mayúscula y un número.';
    }
    if (password !== confirmation) {
        return 'Las contraseñas no coinciden.';
    }
    return null;
}

async function submitReset(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!form || status.value === 'submitting') {
        return;
    }

    const passwordInput = form.querySelector<HTMLInputElement>('input[name="password"]');
    const confirmationInput = form.querySelector<HTMLInputElement>('input[name="confirm_password"]');
    if (!passwordInput || !confirmationInput) {
        return;
    }
    const error = validatePassword(passwordInput.value, confirmationInput.value);
    if (error !== null) {
        status.value = 'error';
        message.value = error;
        resetPasswordFields();
        await nextTick();
        root.value?.querySelector<HTMLElement>('[role="alert"]')?.focus();
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
        await requestApi<{ message: string }>('api/auth/confirmar-reset-password', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrfToken },
            body: JSON.stringify({
                token: props.token,
                password: passwordInput.value,
                confirm_password: confirmationInput.value,
            }),
        });
        resetPasswordFields();
        window.location.assign('index.php?action=login&restablecida=1');
    } catch (cause) {
        resetPasswordFields();
        status.value = 'error';
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo restablecer la contraseña.';
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
        throw new Error('No se encontró el formulario para establecer la nueva contraseña.');
    }
    form.addEventListener('submit', submitReset);
});

onBeforeUnmount(() => {
    form?.removeEventListener('submit', submitReset);
});
</script>

<template>
    <div ref="root" class="reset-password__feedback" aria-live="polite">
        <p v-if="status === 'submitting'" class="message" role="status">Guardando la nueva contraseña…</p>
        <p v-else-if="status === 'error'" class="message message-error" role="alert" tabindex="-1">{{ message }}</p>
    </div>
</template>
