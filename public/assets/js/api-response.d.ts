import type { ApiResponse } from '../../../frontend/src/types/api';

export function isApiResponse(value: unknown): value is ApiResponse<unknown>;
