<script setup lang="ts">
import { onUnmounted, ref } from 'vue';
import { requestApi } from '../api/client';
import { base64PdfBlob, signPdfWithAutoFirma } from '../lib/autoFirmaClient';

const props = defineProps<{ pdfBase64: string; challenge: string; csrf: string }>();
const status = ref<'idle' | 'signing' | 'verifying' | 'success' | 'error'>('idle');
const message = ref('');
const signedPdfUrl = ref('');
const signer = ref('');

interface VerificationResult {
    signer: string;
    signature_count: number;
    persisted: false;
    approved: false;
}

async function testAutoFirma(): Promise<void> {
    status.value = 'signing';
    message.value = 'Confirma la prueba de firma en AutoFirma. No se modificará ningún convenio.';
    signedPdfUrl.value = '';
    signer.value = '';
    try {
        const signedPdf = await signPdfWithAutoFirma(props.pdfBase64);
        status.value = 'verifying';
        message.value = 'La firma se generó. El servidor está comprobando la integridad y el certificado de prueba…';
        const formData = new FormData();
        formData.append('challenge', props.challenge);
        formData.append('documento_firmado', base64PdfBlob(signedPdf), 'autofirma-prueba-firmada.pdf');
        const result = await requestApi<VerificationResult>('index.php?action=verificar-prueba-autofirma', {
            method: 'POST',
            headers: { 'X-CSRF-Token': props.csrf },
            body: formData,
        });
        if (result.persisted || result.approved) {
            throw new Error('La prueba no debe guardar documentos ni aprobar convenios.');
        }
        signedPdfUrl.value = URL.createObjectURL(base64PdfBlob(signedPdf));
        signer.value = result.signer || 'Certificado de prueba';
        status.value = 'success';
        message.value = `Firma PAdES válida (${result.signature_count} firma/s). No se guardó ni aprobó ningún convenio.`;
    } catch (cause) {
        status.value = 'error';
        message.value = cause instanceof Error ? cause.message : 'No se pudo probar AutoFirma.';
    }
}

onUnmounted(() => {
    if (signedPdfUrl.value) {
        URL.revokeObjectURL(signedPdfUrl.value);
    }
});
</script>

<template>
    <section aria-labelledby="autofirma-test-title">
        <h2 id="autofirma-test-title">Prueba de AutoFirma (desarrollo)</h2>
        <p>Se abrirá AutoFirma para firmar un PDF de prueba. El resultado no se guarda ni modifica convenios.</p>
        <button type="button" :disabled="status === 'signing' || status === 'verifying'" @click="testAutoFirma">
            {{ status === 'signing' ? 'Esperando a AutoFirma…' : status === 'verifying' ? 'Verificando firma…' : 'Probar firma PAdES' }}
        </button>
        <p v-if="status === 'signing' || status === 'verifying'" role="status">{{ message }}</p>
        <p v-else-if="status === 'success'" role="status">{{ message }}</p>
        <p v-else-if="status === 'error'" role="alert">{{ message }}</p>
        <p v-if="signer">Firmante: {{ signer }}</p>
        <a v-if="signedPdfUrl" :href="signedPdfUrl" download="autofirma-prueba-firmada.pdf">Descargar firma de prueba</a>
    </section>
</template>
