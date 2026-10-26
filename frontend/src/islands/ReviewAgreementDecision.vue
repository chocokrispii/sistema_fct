<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import {
    useAgreementReviewStore,
    type AgreementReviewDecision,
    type AgreementReviewPayload,
} from '../stores/agreementReview';

const props = defineProps<{ csrf: string; convenioId: string }>();
const store = useAgreementReviewStore();
const root = ref<HTMLElement | null>(null);
const errorId = `review-agreement-${props.convenioId}-error`;
let form: HTMLFormElement | null = null;
let reasonField: HTMLInputElement | null = null;

function setReasonInvalid(invalid: boolean): void {
    if (!reasonField) {
        return;
    }

    const describedBy = new Set((reasonField.getAttribute('aria-describedby') ?? '')
        .split(/\s+/)
        .filter(Boolean));
    if (invalid) {
        reasonField.setAttribute('aria-invalid', 'true');
        describedBy.add(errorId);
    } else {
        reasonField.removeAttribute('aria-invalid');
        describedBy.delete(errorId);
    }
    if (describedBy.size > 0) {
        reasonField.setAttribute('aria-describedby', [...describedBy].join(' '));
    } else {
        reasonField.removeAttribute('aria-describedby');
    }
}

async function focusError(): Promise<void> {
    await nextTick();
    root.value?.querySelector<HTMLElement>('[role="alert"]')?.focus();
}

function onReasonInput(): void {
    setReasonInvalid(false);
}

function setSubmitButtonsDisabled(disabled: boolean): void {
    form?.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => {
        button.disabled = disabled;
    });
}

async function onSubmit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (store.status === 'loading' || store.status === 'success' || !form) {
        return;
    }

    setReasonInvalid(false);
    const submitter = event.submitter;
    if (!(submitter instanceof HTMLButtonElement) || !/^[1-9]\d*$/.test(props.convenioId)) {
        store.status = 'error';
        store.message = 'No se pudo identificar la decisión de revisión.';
        await focusError();
        return;
    }

    const decision = submitter.value;
    if (decision !== 'Aprobado' && decision !== 'Rechazado') {
        store.status = 'error';
        store.message = 'Selecciona una decisión válida.';
        await focusError();
        return;
    }

    const motivo = reasonField?.value.trim() ?? '';
    if (decision === 'Rechazado' && motivo === '') {
        store.status = 'error';
        store.message = 'Indica el motivo del rechazo.';
        setReasonInvalid(true);
        await nextTick();
        reasonField?.focus();
        return;
    }

    const payload: AgreementReviewPayload = {
        convenio_id: Number(props.convenioId),
        decision: decision satisfies AgreementReviewDecision,
        motivo,
    };
    setSubmitButtonsDisabled(true);
    const resolved = await store.resolve(payload, props.csrf);
    if (resolved) {
        setSubmitButtonsDisabled(true);
    } else {
        setSubmitButtonsDisabled(false);
        await focusError();
    }
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de revisión del convenio.');
    }
    reasonField = form.querySelector<HTMLInputElement>('input[name="motivo"]');
    form.addEventListener('submit', onSubmit);
    reasonField?.addEventListener('input', onReasonInput);
});

onUnmounted(() => {
    form?.removeEventListener('submit', onSubmit);
    reasonField?.removeEventListener('input', onReasonInput);
    store.reset();
});
</script>

<template>
    <div ref="root" aria-live="polite" :aria-busy="store.status === 'loading'">
        <p v-if="store.status === 'loading'" role="status">Guardando decisión…</p>
        <p v-else-if="store.status === 'success'" role="status">{{ store.message }}</p>
        <p v-else-if="store.status === 'error'" :id="errorId" class="message message-error" role="alert" tabindex="-1">{{ store.message }}</p>
    </div>
</template>
