<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { requestApi } from '../api/client';

const props = defineProps<{ csrf: string }>();
const root = ref<HTMLElement | null>(null);
const status = ref<'idle' | 'submitting' | 'success' | 'error'>('idle');
const message = ref('');
let form: HTMLFormElement | null = null;

function normalizedDocument(value: string): string {
    return value.trim().toUpperCase().replace(/[\s-]+/g, '');
}

function isDniNie(value: string): boolean {
    const document = normalizedDocument(value);
    let number: number;
    let letter: string;
    if (/^\d{8}[A-Z]$/.test(document)) {
        number = Number(document.slice(0, 8));
        letter = document[8];
    } else if (/^[XYZ]\d{7}[A-Z]$/.test(document)) {
        const prefix = document[0] === 'X' ? '0' : document[0] === 'Y' ? '1' : '2';
        number = Number(prefix + document.slice(1, 8));
        letter = document[8];
    } else {
        return false;
    }

    return letter === 'TRWAGMYFPDXBNJZSQVHLCKE'[number % 23];
}

function isPersonName(value: string, maxLength: number): boolean {
    const name = value.trim();
    return Array.from(name).length <= maxLength
        && /^[\p{L}\p{M}][\p{L}\p{M} \t.'’\-]*$/u.test(name);
}

function isSpanishTaxId(value: string): boolean {
    const taxId = normalizedDocument(value);
    if (isDniNie(taxId)) {
        return true;
    }
    if (!/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/.test(taxId)) {
        return false;
    }

    const digits = taxId.slice(1, 8);
    let sum = 0;
    for (let index = 0; index < digits.length; index += 1) {
        const digit = Number(digits[index]);
        if (index % 2 === 0) {
            const doubled = digit * 2;
            sum += Math.floor(doubled / 10) + (doubled % 10);
        } else {
            sum += digit;
        }
    }

    const controlDigit = (10 - (sum % 10)) % 10;
    const controlLetter = 'JABCDEFGHI'[controlDigit];
    const control = taxId[8];
    if ('ABEH'.includes(taxId[0])) {
        return control === String(controlDigit);
    }
    if ('PQS'.includes(taxId[0])) {
        return control === controlLetter;
    }
    return control === String(controlDigit) || control === controlLetter;
}

function fieldErrorId(field: string): string {
    return `company-${field}-error`;
}

function setFieldError(field: string, error: string): void {
    const input = form?.querySelector<HTMLInputElement>(`[name="${field}"]`);
    const errorElement = form?.querySelector<HTMLElement>(`#${fieldErrorId(field)}`);
    if (!input || !errorElement) {
        return;
    }

    errorElement.textContent = error;
    errorElement.setAttribute('role', 'alert');
    input.setAttribute('aria-invalid', 'true');
    const describedBy = new Set((input.getAttribute('aria-describedby') ?? '')
        .split(/\s+/)
        .filter(Boolean));
    describedBy.add(fieldErrorId(field));
    input.setAttribute('aria-describedby', [...describedBy].join(' '));
}

function clearFieldError(field: string): void {
    const input = form?.querySelector<HTMLInputElement>(`[name="${field}"]`);
    const errorElement = form?.querySelector<HTMLElement>(`#${fieldErrorId(field)}`);
    if (!input) {
        return;
    }

    input.removeAttribute('aria-invalid');
    const describedBy = (input.getAttribute('aria-describedby') ?? '')
        .split(/\s+/)
        .filter((id) => id && id !== fieldErrorId(field));
    if (describedBy.length > 0) {
        input.setAttribute('aria-describedby', describedBy.join(' '));
    } else {
        input.removeAttribute('aria-describedby');
    }
    if (errorElement) {
        errorElement.textContent = '';
        errorElement.removeAttribute('role');
    }
}

function clearInputError(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
        clearFieldError(event.target.name);
        if (status.value === 'error') {
            status.value = 'idle';
            message.value = '';
        }
    }
}

async function onSubmit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!form || status.value === 'submitting' || status.value === 'success') {
        return;
    }

    const password = form.querySelector<HTMLInputElement>('input[name="password"]');
    const taxId = form.querySelector<HTMLInputElement>('input[name="cif"]');
    const representative = form.querySelector<HTMLInputElement>('input[name="representante_legal"]');
    const representativeDni = form.querySelector<HTMLInputElement>('input[name="dni_representante"]');
    if (password && new TextEncoder().encode(password.value).length > 72) {
        status.value = 'error';
        message.value = 'La contraseña no puede superar 72 bytes.';
        setFieldError('password', message.value);
        await nextTick();
        password.focus();
        return;
    }
    if (taxId && !isSpanishTaxId(taxId.value)) {
        status.value = 'error';
        message.value = 'El CIF/NIF no tiene un formato válido.';
        setFieldError('cif', message.value);
        await nextTick();
        taxId.focus();
        return;
    }
    if (representative && !isPersonName(representative.value, 150)) {
        status.value = 'error';
        message.value = 'Introduce un nombre válido para el representante legal.';
        setFieldError('representante_legal', message.value);
        await nextTick();
        representative.focus();
        return;
    }
    if (representativeDni && (!representativeDni.value.trim() || !isDniNie(representativeDni.value))) {
        status.value = 'error';
        message.value = 'El DNI/NIE del representante es obligatorio y debe tener un formato válido.';
        setFieldError('dni_representante', message.value);
        await nextTick();
        representativeDni.focus();
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
        const result = await requestApi<{ message: string }>('api/public/empresas/solicitud', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrf },
            body: new FormData(form),
        });
        status.value = 'success';
        message.value = result.message;
        if (password) {
            password.value = '';
        }
        if (representativeDni) {
            representativeDni.value = '';
        }
        form.hidden = true;
        await nextTick();
        root.value?.querySelector<HTMLElement>('[role="status"]')?.focus();
    } catch (cause) {
        status.value = 'error';
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo enviar la solicitud. Inténtalo de nuevo.';
        if (password) {
            password.value = '';
        }
        if (representativeDni) {
            representativeDni.value = '';
        }
        clearFieldError('password');
        clearFieldError('dni_representante');
        await nextTick();
        if (message.value === 'El correo o el CIF/NIF ya está registrado.') {
            setFieldError('email', message.value);
            setFieldError('cif', message.value);
            await nextTick();
            form.querySelector<HTMLInputElement>('input[name="email"]')?.focus();
        } else {
            root.value?.querySelector<HTMLElement>('[role="alert"]')?.focus();
        }
    } finally {
        form?.removeAttribute('aria-busy');
        if (submit && status.value !== 'success') {
            submit.disabled = false;
        }
    }
}

onMounted(() => {
    form = root.value?.closest<HTMLElement>('.company-registration')?.querySelector<HTMLFormElement>('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de alta de empresa.');
    }
    form.addEventListener('submit', onSubmit);
    form.addEventListener('input', clearInputError);
});

onBeforeUnmount(() => {
    form?.removeEventListener('submit', onSubmit);
    form?.removeEventListener('input', clearInputError);
});
</script>

<template>
    <div ref="root" class="company-registration__feedback" aria-live="polite" :aria-busy="status === 'submitting'">
        <p v-if="status === 'submitting'" class="message" role="status">Enviando solicitud…</p>
        <p v-else-if="status === 'success'" class="message message-success" role="status" tabindex="-1">{{ message }}</p>
        <p v-else-if="status === 'error'" class="message message-error" role="alert" tabindex="-1">{{ message }}</p>
    </div>
</template>
