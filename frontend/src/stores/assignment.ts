import { defineStore } from 'pinia';
import { ref } from 'vue';
import { requestApi } from '../api/client';

export interface AssignmentPayload {
    oferta_id: number;
    alumno_id: number;
    tutor_empresa: string;
}

interface AssignmentResult {
    message: string;
}

export const useAssignmentStore = defineStore('assignment', () => {
    const status = ref<'idle' | 'loading' | 'success' | 'error'>('idle');
    const message = ref('');

    async function assign(payload: AssignmentPayload, csrfToken: string): Promise<boolean> {
        status.value = 'loading';
        message.value = '';

        try {
            const result = await requestApi<AssignmentResult>('index.php?action=asignar-alumno', {
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
                : 'No se pudo completar la asignación. Inténtalo de nuevo.';
            return false;
        }
    }

    function reset(): void {
        status.value = 'idle';
        message.value = '';
    }

    return { status, message, assign, reset };
});
