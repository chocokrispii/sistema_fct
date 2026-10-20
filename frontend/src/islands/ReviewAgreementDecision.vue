<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import {
    useAgreementReviewStore,
    type AgreementReviewDecision,
    type AgreementReviewPayload,
} from '../stores/agreementReview';

const props = defineProps<{ csrf: string; convenioId: string }>();
const store = useAgreementReviewStore();
const root = ref<HTMLElement | null>(null);
let form: HTMLFormElement | null = null;

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

    const submitter = event.submitter;
    if (!(submitter instanceof HTMLButtonElement) || !/^[1-9]\d*$/.test(props.convenioId)) {
        store.status = 'error';
        store.message = 'No se pudo identificar la decisión de revisión.';
        return;
    }

    const decision = submitter.value;
    if (decision !== 'Aprobado' && decision !== 'Rechazado') {
        store.status = 'error';
        store.message = 'Selecciona una decisión válida.';
        return;
    }

    const reasonField = form.querySelector<HTMLInputElement>('input[name="motivo"]');
    const motivo = reasonField?.value.trim() ?? '';
    if (decision === 'Rechazado' && motivo === '') {
        store.status = 'error';
        store.message = 'Indica el motivo del rechazo.';
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
    }
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de revisión del convenio.');
    }
    form.addEventListener('submit', onSubmit);
});

onUnmounted(() => {
    form?.removeEventListener('submit', onSubmit);
    store.reset();
});
</script>

<template>
    <div ref="root" aria-live="polite" :aria-busy="store.status === 'loading'">
        <p v-if="store.status === 'loading'" role="status">Guardando decisión…</p>
        <p v-else-if="store.status === 'success'" role="status">{{ store.message }}</p>
        <p v-else-if="store.status === 'error'" role="alert">{{ store.message }}</p>
    </div>
</template>
