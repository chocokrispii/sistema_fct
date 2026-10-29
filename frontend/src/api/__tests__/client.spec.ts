import { beforeEach, describe, expect, it, vi } from 'vitest';
import { requestApi } from '../client';

describe('requestApi', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        vi.stubGlobal('document', {
            querySelector: vi.fn().mockReturnValue({ content: 'mock-csrf-token-123' }),
        });
    });

    it('devuelve los datos de una respuesta JSON exitosa y conserva la sesión', async () => {
        const data = { id: 1, nombre: 'Empresa de prueba' };
        const fetchMock = vi.fn().mockResolvedValue(
            Response.json({ success: true, data }),
        );
        vi.stubGlobal('fetch', fetchMock);

        await expect(requestApi('/index.php?action=empresa-asignada')).resolves.toEqual(data);

        expect(fetchMock).toHaveBeenCalledWith(
            '/index.php?action=empresa-asignada',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(new Headers(fetchMock.mock.calls[0]?.[1]?.headers).get('Accept')).toBe('application/json');
    });

    it('añade CSRF y Content-Type JSON en mutaciones y serializa parámetros', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            Response.json({ success: true, data: { status: 'ok' } }),
        );
        vi.stubGlobal('fetch', fetchMock);

        await requestApi('/index.php?action=aprobar-empresa', {
            method: 'POST',
            params: { id: 10, activo: false, omitido: undefined },
            body: JSON.stringify({ id: 10 }),
        });

        expect(fetchMock).toHaveBeenCalledWith(
            '/index.php?action=aprobar-empresa&id=10&activo=false',
            expect.any(Object),
        );
        const headers = new Headers(fetchMock.mock.calls[0]?.[1]?.headers);
        expect(headers.get('X-CSRF-Token')).toBe('mock-csrf-token-123');
        expect(headers.get('Content-Type')).toBe('application/json');
    });

    it('conserva un token CSRF actualizado proporcionado por la isla', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            Response.json({ success: true, data: null }),
        );
        vi.stubGlobal('fetch', fetchMock);

        await requestApi('/index.php?action=logout', {
            method: 'POST',
            headers: { 'X-CSRF-Token': 'rotated-csrf-token' },
        });

        expect(new Headers(fetchMock.mock.calls[0]?.[1]?.headers).get('X-CSRF-Token'))
            .toBe('rotated-csrf-token');
    });

    it('no define Content-Type al enviar FormData', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            Response.json({ success: true, data: { status: 'ok' } }),
        );
        vi.stubGlobal('fetch', fetchMock);
        const body = new FormData();
        body.set('nombre', 'Centro');

        await requestApi('/index.php?action=registro-empresa', {
            method: 'POST',
            body,
        });

        expect(new Headers(fetchMock.mock.calls[0]?.[1]?.headers).has('Content-Type')).toBe(false);
    });

    it('lanza el mensaje de error del sobre cuando success es false', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            Response.json({ success: false, error: 'Acceso denegado' }),
        ));

        await expect(requestApi('/index.php?action=disponibilidad'))
            .rejects.toThrow('Acceso denegado');
    });

    it('rechaza respuestas que no cumplen el sobre ApiResponse', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            Response.json({ success: 'yes', data: {} }),
        ));

        await expect(requestApi('/index.php?action=disponibilidad'))
            .rejects.toThrow('No se pudo completar la solicitud al servidor.');
    });

    it('rechaza respuestas que no contienen JSON válido', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            new Response('respuesta no JSON'),
        ));

        await expect(requestApi('/index.php?action=disponibilidad'))
            .rejects.toThrow('El servidor devolvió una respuesta que no es JSON válida.');
    });
});
