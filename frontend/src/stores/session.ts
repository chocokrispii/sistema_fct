import { defineStore } from 'pinia';
import { ref } from 'vue';
import { requestApi } from '../api/client';
import type { Rol, Usuario } from '../types/domain';

interface SessionPayload {
    user: Usuario | null;
    csrfToken: string;
}

interface LoginPayload {
    email: string;
    password: string;
}

function errorMessage(error: unknown): string {
    return error instanceof Error
        ? error.message
        : 'No se pudo completar la solicitud.';
}

export const useSessionStore = defineStore('session', () => {
    const user = ref<Usuario | null>(null);
    const csrfToken = ref('');
    const status = ref<'idle' | 'loading' | 'authenticated' | 'anonymous' | 'error'>('idle');
    const error = ref('');

    function setCsrfToken(token: string): void {
        csrfToken.value = token;
    }

    async function initialize(initialCsrfToken = ''): Promise<void> {
        if (initialCsrfToken !== '') {
            csrfToken.value = initialCsrfToken;
        }
        status.value = 'loading';
        error.value = '';

        try {
            const session = await requestApi<SessionPayload>('index.php?action=session');
            user.value = session.user;
            csrfToken.value = session.csrfToken;
            status.value = user.value ? 'authenticated' : 'anonymous';
        } catch (cause) {
            status.value = 'error';
            error.value = errorMessage(cause);
        }
    }

    async function login(credentials: LoginPayload): Promise<Usuario> {
        error.value = '';
        try {
            const session = await requestApi<SessionPayload>('index.php?action=login', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken.value },
                body: JSON.stringify(credentials),
            });
            user.value = session.user;
            csrfToken.value = session.csrfToken;
            status.value = 'authenticated';
            if (!user.value) {
                throw new Error('El servidor no devolvió el usuario autenticado.');
            }
            return user.value;
        } catch (cause) {
            status.value = 'anonymous';
            error.value = errorMessage(cause);
            throw cause;
        }
    }

    async function logout(): Promise<void> {
        error.value = '';
        try {
            await requestApi<{ user: null }>('index.php?action=logout', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken.value },
                body: JSON.stringify({}),
            });
            user.value = null;
            status.value = 'anonymous';
        } catch (cause) {
            error.value = errorMessage(cause);
            throw cause;
        }
    }

    function dashboardAction(role: Rol): string {
        switch (role) {
            case 'Alumno':
                return 'portal-alumno';
            case 'Empresa':
                return 'publicar-oferta';
            case 'Observador':
                return 'asignaciones';
            case 'Administrador':
            case 'Coordinador':
                return 'revisar-ofertas';
        }
    }

    return { user, csrfToken, status, error, setCsrfToken, initialize, login, logout, dashboardAction };
});
