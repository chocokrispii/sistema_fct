import { defineStore } from 'pinia';
import { ref } from 'vue';
import { requestApi } from '../api/client';
import type { ProfileUpdatePayload, ProfileUpdateResult } from '../types/profile';

function errorMessage(error: unknown): string {
    return error instanceof Error
        ? error.message
        : 'No se pudo guardar el expediente. Inténtalo de nuevo.';
}

export const useStudentProfileStore = defineStore('student-profile', () => {
    const status = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
    const error = ref('');

    async function save(payload: ProfileUpdatePayload, csrfToken: string): Promise<void> {
        status.value = 'saving';
        error.value = '';

        try {
            await requestApi<ProfileUpdateResult>('index.php?action=perfil-alumno', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload),
            });
            status.value = 'saved';
        } catch (cause) {
            status.value = 'error';
            error.value = errorMessage(cause);
        }
    }

    return { status, error, save };
});
