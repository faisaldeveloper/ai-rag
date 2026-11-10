/**
 * A small client for the app's JSON API, authenticated with the session cookie
 * (Sanctum's stateful API). Business rules stay on the server: this only
 * sends requests and turns error responses into ApiError.
 */

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        /** Validation messages per field, for 422 responses. */
        public readonly errors: Record<string, string[]> = {},
        /** Seconds from the Retry-After header, when the server sent one. */
        public readonly retryAfter: number | null = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

/** Read the XSRF-TOKEN cookie Laravel sets, so state-changing requests pass CSRF verification. */
function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function parseRetryAfter(value: string | null): number | null {
    if (value === null || !/^\d+$/.test(value.trim())) {
        return null;
    }

    return Math.max(1, Number.parseInt(value, 10));
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    if (init.body !== undefined && !(init.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }

    const token = xsrfToken();
    if (token !== null) {
        headers.set('X-XSRF-TOKEN', token);
    }

    let response: Response;
    try {
        response = await fetch(`/api${path}`, { ...init, headers, credentials: 'same-origin' });
    } catch {
        throw new ApiError(0, 'Could not reach the server. Check your connection and try again.');
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new ApiError(
            response.status,
            typeof body.message === 'string' ? body.message : '',
            body.errors ?? {},
            parseRetryAfter(response.headers.get('Retry-After')),
        );
    }

    return body as T;
}

export const postJson = <T>(path: string, data: unknown): Promise<T> =>
    api<T>(path, { method: 'POST', body: JSON.stringify(data) });

/**
 * Turn any error into a sentence a person can act on.
 *
 * 422 shows the server's own validation messages; 429 and 503 say when to
 * try again if the server said so; server internals are never shown.
 */
export function friendlyError(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'Something went wrong. Please try again.';
    }

    const wait = error.retryAfter !== null ? ` Please try again in ${formatSeconds(error.retryAfter)}.` : ' Please try again shortly.';

    switch (error.status) {
        case 0:
            return error.message;
        case 401:
        case 419:
            return 'Your session has expired. Please refresh the page and sign in again.';
        case 403:
            return "You don't have permission to do that.";
        case 404:
            return error.message || 'That item no longer exists.';
        case 409:
            return error.message || 'That item was changed elsewhere. Refresh and try again.';
        case 413:
            return 'That file is too large to upload.';
        case 422:
            return Object.values(error.errors).flat()[0] ?? (error.message || 'Please check what you entered.');
        case 429:
            return `You're sending requests too quickly.${wait}`;
        case 503:
            return `The assistant is temporarily unavailable.${wait}`;
        default:
            return 'Something went wrong on the server. Please try again.';
    }
}

export function formatSeconds(seconds: number): string {
    return seconds === 1 ? '1 second' : `${seconds} seconds`;
}
