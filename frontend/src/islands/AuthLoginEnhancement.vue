<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useSessionStore } from '../stores/session';

const props = defineProps<{ csrfToken: string }>();
const root = ref<HTMLElement | null>(null);
const session = useSessionStore();
const submitting = ref(false);
const validationError = ref('');
let form: HTMLFormElement | null = null;
let passwordInput: HTMLInputElement | null = null;

const validationErrorId = 'login-password-validation-error';

function setPasswordInvalid(invalid: boolean): void {
    if (!passwordInput) {
        return;
    }

    const describedBy = new Set((passwordInput.getAttribute('aria-describedby') ?? '')
        .split(/\s+/)
        .filter(Boolean));
    if (invalid) {
        passwordInput.setAttribute('aria-invalid', 'true');
        describedBy.add(validationErrorId);
    } else {
        passwordInput.removeAttribute('aria-invalid');
        describedBy.delete(validationErrorId);
    }

    if (describedBy.size > 0) {
        passwordInput.setAttribute('aria-describedby', [...describedBy].join(' '));
    } else {
        passwordInput.removeAttribute('aria-describedby');
    }
}

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

    if (new TextEncoder().encode(password).length > 72) {
        validationError.value = 'La contraseña no puede superar 72 bytes.';
        setPasswordInvalid(true);
        if (passwordInput) {
            passwordInput.value = '';
        }
        await nextTick();
        passwordInput?.focus();
        return;
    }
    validationError.value = '';
    setPasswordInvalid(false);

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
        if (passwordInput) {
            passwordInput.value = '';
        }
        // El store conserva el error para mostrarlo junto al formulario.
        await nextTick();
        root.value?.querySelector<HTMLElement>('#login-request-error')?.focus();
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
    passwordInput = form?.querySelector<HTMLInputElement>('#login-password') ?? null;
    form?.addEventListener('submit', submitAsJson);
    passwordInput?.addEventListener('input', clearValidationError);
});

onBeforeUnmount(() => {
    form?.removeEventListener('submit', submitAsJson);
    passwordInput?.removeEventListener('input', clearValidationError);
});

function clearValidationError(): void {
    validationError.value = '';
    setPasswordInvalid(false);
}

</script>

<template>
    <div ref="root" class="session-login-enhancement">
        <p v-if="submitting" class="visually-hidden" role="status" aria-live="polite">
            Iniciando sesión…
        </p>
        <p v-if="session.error" id="login-request-error" class="message message-error" role="alert" tabindex="-1">
            {{ session.error }}
        </p>
        <p v-if="validationError" :id="validationErrorId" class="message message-error" role="alert">
            {{ validationError }}
        </p>
    </div>
</template>
