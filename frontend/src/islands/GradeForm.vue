<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { requestApi } from '../api/client';

const props = defineProps<{ csrf: string }>();
const root = ref<HTMLElement | null>(null);
const error = ref('');
const submitting = ref(false);
let form: HTMLFormElement | null = null;
let gradeInput: HTMLInputElement | null = null;
let submitButton: HTMLButtonElement | null = null;
let lastValidValue = '';

function isValidEdit(value: string): boolean {
    if (value === '') {
        return true;
    }
    return /^\d{0,2}(?:\.\d{0,2})?$/.test(value) && Number(value) <= 10;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.ctrlKey || event.metaKey || event.altKey || event.key.length !== 1) {
        return;
    }
    if (!/^[0-9.,]$/.test(event.key)) {
        event.preventDefault();
        return;
    }
    if ((event.key === '.' || event.key === ',') && /[.,]/.test(gradeInput?.value ?? '')) {
        event.preventDefault();
        return;
    }
    const input = gradeInput;
    if (!input) {
        return;
    }
    const next = input.value.slice(0, input.selectionStart ?? input.value.length)
        + (event.key === ',' ? '.' : event.key)
        + input.value.slice(input.selectionEnd ?? input.value.length);
    if (!isValidEdit(next)) {
        event.preventDefault();
    }
}

function onInput(): void {
    if (!gradeInput) {
        return;
    }
    const normalized = gradeInput.value.replace(',', '.');
    if (!isValidEdit(normalized)) {
        gradeInput.value = lastValidValue;
        error.value = 'La nota debe estar entre 0 y 10, con un máximo de dos decimales.';
        return;
    }
    gradeInput.value = normalized;
    lastValidValue = normalized;
    error.value = '';
}

function onPaste(event: ClipboardEvent): void {
    event.preventDefault();
    const input = gradeInput;
    const pasted = event.clipboardData?.getData('text') ?? '';
    if (!input) {
        return;
    }
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const next = (input.value.slice(0, start) + pasted.replace(',', '.') + input.value.slice(end));
    if (!isValidEdit(next)) {
        error.value = 'El texto pegado no es una nota válida entre 0 y 10.';
        return;
    }
    input.value = next;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

async function onSubmit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!form || submitting.value) {
        return;
    }
    const data = new FormData(form);
    submitting.value = true;
    error.value = '';
    if (submitButton) {
        submitButton.disabled = true;
    }
    try {
        await requestApi('index.php?action=guardar-calificacion', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrf },
            body: JSON.stringify({
                asignacion_id: Number(data.get('asignacion_id')),
                nota: String(data.get('nota') ?? ''),
                observaciones: String(data.get('observaciones') ?? ''),
            }),
        });
        window.location.assign('index.php?action=calificaciones&guardada=1');
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'No se pudo guardar la calificación.';
    } finally {
        submitting.value = false;
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    gradeInput = form?.querySelector<HTMLInputElement>('input[name="nota"]') ?? null;
    submitButton = form?.querySelector<HTMLButtonElement>('button[type="submit"]') ?? null;
    if (!form || !gradeInput) {
        throw new Error('No se encontró el formulario de calificación.');
    }
    lastValidValue = gradeInput.value;
    gradeInput.addEventListener('keydown', onKeydown);
    gradeInput.addEventListener('input', onInput);
    gradeInput.addEventListener('paste', onPaste);
    form.addEventListener('submit', onSubmit);
});

onBeforeUnmount(() => {
    gradeInput?.removeEventListener('keydown', onKeydown);
    gradeInput?.removeEventListener('input', onInput);
    gradeInput?.removeEventListener('paste', onPaste);
    form?.removeEventListener('submit', onSubmit);
});
</script>

<template>
    <div ref="root">
        <p v-if="error" class="message message-error" role="alert">{{ error }}</p>
    </div>
</template>
