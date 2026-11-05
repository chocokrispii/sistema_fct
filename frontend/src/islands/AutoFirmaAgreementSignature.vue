<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { requestApi } from '../api/client';
import { base64PdfBlob, signPdfWithAutoFirma } from '../lib/autoFirmaClient';

const props = defineProps<{ csrf: string; convenioId: string }>();
const root = ref<HTMLElement | null>(null);
const status = ref<'idle' | 'preparing' | 'ready' | 'signing' | 'success' | 'error'>('idle');
const message = ref('');
let form: HTMLFormElement | null = null;
let submitHandler: ((event: SubmitEvent) => void) | null = null;
const preparedSignature = ref<PreparedSignature | null>(null);

interface PreparedSignature {
    challenge: string;
    pdf_base64: string;
    filename: string;
}

interface CompletionResult {
    notificacion_enviada: boolean;
}

async function submitAutoFirma(event: SubmitEvent): Promise<void> {
    const submitter = event.submitter;
    if (submitter instanceof HTMLButtonElement && submitter.value === 'manual') {
        return;
    }
    event.preventDefault();
    if (status.value === 'preparing' || status.value === 'signing' || !/^[1-9]\d*$/.test(props.convenioId)) {
        return;
    }
    status.value = 'preparing';
    message.value = 'Preparando el documento para AutoFirma…';

    try {
        preparedSignature.value = await requestApi<PreparedSignature>('index.php?action=preparar-firma-autofirma', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrf },
            body: JSON.stringify({ convenio_id: Number(props.convenioId) }),
        });
        status.value = 'ready';
        message.value = 'El documento está preparado. Pulsa «Abrir AutoFirma» para elegir el certificado y firmar.';
    } catch (cause) {
        status.value = 'error';
        message.value = cause instanceof Error ? cause.message : 'No se pudo preparar el PDF.';
    }
}

async function signAndSubmit(): Promise<void> {
    const prepared = preparedSignature.value;
    if (!prepared || status.value !== 'ready') {
        return;
    }
    status.value = 'signing';
    message.value = 'Confirma la firma PAdES en la aplicación AutoFirma.';
    form?.querySelectorAll<HTMLButtonElement>('button[type="submit"], button[type="button"]').forEach((button) => {
        button.disabled = true;
    });

    try {
        const signedPdf = await signPdfWithAutoFirma(prepared.pdf_base64);
        const formData = new FormData();
        formData.append('challenge', prepared.challenge);
        formData.append('documento_firmado', base64PdfBlob(signedPdf), 'convenio-firmado.pdf');
        const result = await requestApi<CompletionResult>('index.php?action=completar-firma-autofirma', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrf },
            body: formData,
        });
        status.value = 'success';
        message.value = result.notificacion_enviada
            ? 'Firma validada. Convenio aprobado y empresa notificada.'
            : 'Firma validada y convenio aprobado; no se pudo enviar el correo a la empresa.';
        window.setTimeout(() => {
            window.location.assign(
                `index.php?action=revisar-convenios&firma=${result.notificacion_enviada ? 'completada' : 'correo_pendiente'}`,
            );
        }, 1200);
    } catch (cause) {
        status.value = 'error';
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo completar la firma. El convenio sigue pendiente.';
        form?.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => {
            button.disabled = false;
        });
        form?.querySelectorAll<HTMLButtonElement>('button[type="button"]').forEach((button) => {
            button.disabled = false;
        });
    }
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de firma de convenio.');
    }
    submitHandler = (event) => {
        void submitAutoFirma(event);
    };
    form.addEventListener('submit', submitHandler);
});

onUnmounted(() => {
    if (form && submitHandler) {
        form.removeEventListener('submit', submitHandler);
    }
});
</script>

<template>
    <div ref="root" aria-live="polite" :aria-busy="status === 'preparing' || status === 'signing'">
        <p v-if="status === 'preparing' || status === 'signing'" role="status">{{ message }}</p>
        <p v-else-if="status === 'ready'" role="status">{{ message }}</p>
        <p v-else-if="status === 'success'" role="status">{{ message }}</p>
        <p v-else-if="status === 'error'" role="alert">{{ message }}</p>
        <button v-if="status === 'ready'" type="button" @click="signAndSubmit">Abrir AutoFirma y firmar</button>
    </div>
</template>
