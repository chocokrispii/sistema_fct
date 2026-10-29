/**
 * @param {unknown} value
 * @returns {value is import('../../../frontend/src/types/api').ApiResponse<unknown>}
 */
export function isApiResponse(value) {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }

    const keys = Object.keys(value);
    if (keys.length !== 2) {
        return false;
    }

    if (value.success === true) {
        return keys.includes('data') && !keys.includes('error');
    }

    return value.success === false
        && typeof value.error === 'string'
        && keys.includes('error')
        && !keys.includes('data');
}
