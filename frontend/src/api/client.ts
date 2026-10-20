import type { ApiResponse } from '../types/api';

function isApiSuccess<T>(value: unknown): value is { success: true; data: T } {
    return typeof value === 'object'
        && value !== null
        && 'success' in value
        && value.success === true
        && 'data' in value;
}

function isApiFailure(value: unknown): value is { success: false; error: string } {
    return typeof value === 'object'
        && value !== null
        && 'success' in value
        && value.success === false
        && 'error' in value
        && typeof value.error === 'string';
}

export async function requestApi<T>(url: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');

    if (init.body !== undefined && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const response = await fetch(url, {
        ...init,
        headers,
        credentials: 'same-origin',
    });
    let payload: unknown;
    try {
        payload = await response.json();
    } catch {
        throw new Error('El servidor devolvió una respuesta que no es JSON válida.');
    }

    if (isApiFailure(payload)) {
        throw new Error(payload.error);
    }
    if (!response.ok || !isApiSuccess<T>(payload)) {
        throw new Error('No se pudo completar la solicitud al servidor.');
    }

    return payload.data;
}

export type { ApiResponse };
