import { describe, expect, it } from 'vitest';
import { isApiResponse } from '../../../../public/assets/js/api-response.js';

describe('REST API response envelope', () => {
    it('accepts success responses with data', () => {
        expect(isApiResponse({ success: true, data: { id: 1 } })).toBe(true);
    });

    it('accepts error responses with a string message', () => {
        expect(isApiResponse({ success: false, error: 'Forbidden' })).toBe(true);
    });

    it.each([
        null,
        [],
        { success: true },
        { success: true, data: {}, error: 'unexpected' },
        { success: false, error: 403 },
        { success: false, error: 'Forbidden', data: null },
        { success: 'true', data: {} },
        { success: true, data: {}, extra: true },
    ])('rejects invalid envelopes: %j', (value) => {
        expect(isApiResponse(value)).toBe(false);
    });
});
