import type { ApiResponse, RequestOptions } from '../types/api';

function isApiSuccess<T>(value: unknown): value is { success: true; data: T } {
    return typeof value === 'object'
        && value !== null
        && 'success' in value
        && value.success === true
        && 'data' in value
        && !('error' in value)
        && Object.keys(value).length === 2;
}

function isApiFailure(value: unknown): value is { success: false; error: string } {
    return typeof value === 'object'
        && value !== null
        && 'success' in value
        && value.success === false
        && 'error' in value
        && typeof value.error === 'string'
        && !('data' in value)
        && Object.keys(value).length === 2;
}

function withQueryParams(url: string, params: RequestOptions['params']): string {
    if (!params) {
        return url;
    }

    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined) {
            query.set(key, String(value));
        }
    });

    const queryString = query.toString();
    if (queryString === '') {
        return url;
    }

    const hashIndex = url.indexOf('#');
    const path = hashIndex === -1 ? url : url.slice(0, hashIndex);
    const hash = hashIndex === -1 ? '' : url.slice(hashIndex);
    return `${path}${path.includes('?') ? '&' : '?'}${queryString}${hash}`;
}

function isFormData(body: BodyInit | null | undefined): body is FormData {
    return typeof FormData !== 'undefined' && body instanceof FormData;
}

function csrfTokenFromDocument(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export async function requestApi<T>(url: string, options: RequestOptions = {}): Promise<T> {
    const { params, headers: requestHeaders, ...init } = options;
    const headers = new Headers(requestHeaders);
    headers.set('Accept', 'application/json');

    if (init.body !== undefined && !isFormData(init.body) && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const method = (init.method ?? 'GET').toUpperCase();
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && !headers.has('X-CSRF-Token')) {
        const csrfToken = csrfTokenFromDocument();
        if (csrfToken === '') {
            throw new Error('No se encontró el token CSRF para completar la solicitud.');
        }
        headers.set('X-CSRF-Token', csrfToken);
    }

    const response = await fetch(withQueryParams(url, params), {
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

export type { ApiResponse, RequestOptions };
