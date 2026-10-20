<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { requestApi } from '../api/client';
import type {
    OfferReviewDecision,
    OfferReviewPayload,
    OfferReviewResult,
} from '../types/offerReview';

const props = defineProps<{ csrf: string; offerId: string }>();
const status = ref<'idle' | 'submitting' | 'success' | 'error'>('idle');
const message = ref('');
const root = ref<HTMLElement | null>(null);
let form: HTMLFormElement | null = null;

function onSubmit(event: SubmitEvent): void {
    event.preventDefault();
    if (status.value === 'submitting' || status.value === 'success' || !form) {
        return;
    }

    const submitter = event.submitter;
    if (!(submitter instanceof HTMLButtonElement) || !/^\d+$/.test(props.offerId)) {
        status.value = 'error';
        message.value = 'No se pudo identificar la decisión de revisión.';
        return;
    }

    const decision = submitter.value;
    if (decision !== 'Aprobada' && decision !== 'Rechazada') {
        status.value = 'error';
        message.value = 'Selecciona una decisión válida.';
        return;
    }

    const reasonField = form.querySelector<HTMLInputElement>('input[name="motivo"]');
    const motivo = reasonField?.value.trim() ?? '';
    if (decision === 'Rechazada' && motivo === '') {
        status.value = 'error';
        message.value = 'Indica el motivo del rechazo.';
        reasonField?.focus();
        return;
    }
    const payload: OfferReviewPayload = {
        oferta_id: Number(props.offerId),
        decision: decision satisfies OfferReviewDecision,
        motivo,
    };
    void resolveOffer(payload);
}

function setSubmitButtonsDisabled(disabled: boolean): void {
    form?.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => {
        button.disabled = disabled;
    });
}

async function resolveOffer(payload: OfferReviewPayload): Promise<void> {
    status.value = 'submitting';
    message.value = '';
    setSubmitButtonsDisabled(true);

    try {
        const result = await requestApi<OfferReviewResult>('index.php?action=revisar-oferta', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrf },
            body: JSON.stringify(payload),
        });
        status.value = 'success';
        message.value = result.message;
        form?.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => {
            button.disabled = true;
        });
    } catch (cause) {
        status.value = 'error';
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo revisar la oferta. Inténtalo de nuevo.';
        setSubmitButtonsDisabled(false);
    }
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de revisión de la oferta.');
    }
    form.addEventListener('submit', onSubmit);
});

onBeforeUnmount(() => {
    form?.removeEventListener('submit', onSubmit);
});
</script>

<template>
    <div ref="root" aria-live="polite" :aria-busy="status === 'submitting'">
        <p v-if="status === 'submitting'" class="message" role="status">Guardando decisión…</p>
        <p v-else-if="status === 'success'" class="message message-success" role="status">{{ message }}</p>
        <p v-else-if="status === 'error'" class="message message-error" role="alert">{{ message }}</p>
    </div>
</template>
