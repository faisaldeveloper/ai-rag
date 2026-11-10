import { vi } from 'vitest';

export interface FakeResponse {
    status?: number;
    body?: unknown;
    headers?: Record<string, string>;
}

export type Route = (url: string, init: RequestInit) => FakeResponse | undefined;

/**
 * Replace fetch with a router over the given handlers. The first handler that
 * returns a response wins; an unhandled request fails the test loudly.
 */
export function mockFetch(...routes: Route[]) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
        const url = String(input);

        for (const route of routes) {
            const response = route(url, init);
            if (response !== undefined) {
                const status = response.status ?? 200;

                return new Response(status === 204 ? null : JSON.stringify(response.body ?? {}), {
                    status,
                    headers: { 'Content-Type': 'application/json', ...response.headers },
                });
            }
        }

        throw new Error(`Unexpected request: ${init.method ?? 'GET'} ${url}`);
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

/** Match a request by method and exact URL (query string included). */
export const on =
    (method: string, url: string, respond: (init: RequestInit) => FakeResponse): Route =>
    (requestUrl, init) =>
        (init.method ?? 'GET') === method && requestUrl === url ? respond(init) : undefined;

export const page = <T>(data: T[], currentPage = 1, lastPage = 1) => ({
    data,
    meta: { current_page: currentPage, last_page: lastPage, total: data.length },
});
