export type ApiResponse<T> =
    | {
          success: true;
          data: T;
      }
    | {
          success: false;
          error: string;
      };

export interface ApiPaginationMeta {
    page: number;
    per_page: number;
    total: number;
}

export interface ApiPaginatedData<T> {
    items: T[];
    meta: ApiPaginationMeta;
}

export type RequestOptions = Omit<RequestInit, 'credentials'> & {
    params?: Record<string, string | number | boolean | undefined>;
};
