import { defineStore } from 'pinia';
import { ref } from 'vue';
import { requestApi } from '../api/client';

export type AgreementReviewDecision = 'Aprobado' | 'Rechazado';

export interface AgreementReviewPayload {
    convenio_id: number;
    decision: AgreementReviewDecision;
    motivo: string;
}

interface AgreementReviewResult {
    message: string;
    decision: AgreementReviewDecision;
}

export const useAgreementReviewStore = defineStore('agreement-review', () => {
    const status = ref<'idle' | 'loading' | 'success' | 'error'>('idle');
    const message = ref('');

    async function resolve(payload: AgreementReviewPayload, csrfToken: string): Promise<boolean> {
        status.value = 'loading';
        message.value = '';

        try {
            const result = await requestApi<AgreementReviewResult>('index.php?action=resolver-convenio', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload),
            });
            status.value = 'success';
            message.value = result.message;
            return true;
        } catch (cause) {
            status.value = 'error';
            message.value = cause instanceof Error
                ? cause.message
                : 'No se pudo revisar el convenio. Inténtalo de nuevo.';
            return false;
        }
    }

    function reset(): void {
        status.value = 'idle';
        message.value = '';
    }

    return { status, message, resolve, reset };
});
