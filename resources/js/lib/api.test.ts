import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockFetch, on } from '../test/fetch';
import { api, ApiError, friendlyError } from './api';

afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('api', () => {
    it('sends JSON headers and the decoded XSRF token from the cookie', async () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('token/with=chars')}`;
        const fetchMock = mockFetch(on('POST', '/api/chat', () => ({ status: 201, body: { data: { ok: true } } })));

        await expect(api('/chat', { method: 'POST', body: JSON.stringify({}) })).resolves.toEqual({ data: { ok: true } });

        const headers = new Headers(fetchMock.mock.calls[0][1]?.headers);
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('Content-Type')).toBe('application/json');
        expect(headers.get('X-XSRF-TOKEN')).toBe('token/with=chars');
    });

    it('lets the browser set the multipart content type for uploads', async () => {
        const fetchMock = mockFetch(on('POST', '/api/documents', () => ({ status: 201, body: {} })));

        await api('/documents', { method: 'POST', body: new FormData() });

        expect(new Headers(fetchMock.mock.calls[0][1]?.headers).has('Content-Type')).toBe(false);
    });

    it('turns error responses into ApiError with validation errors and Retry-After', async () => {
        mockFetch(
            on('POST', '/api/chat', () => ({
                status: 503,
                body: { message: 'The assistant is temporarily unavailable. Please try again later.' },
                headers: { 'Retry-After': '30' },
            })),
        );

        const error = await api('/chat', { method: 'POST' }).catch((caught: unknown) => caught);

        expect(error).toBeInstanceOf(ApiError);
        expect(error).toMatchObject({ status: 503, retryAfter: 30 });
    });

    it('reports network failures as status 0', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        await expect(api('/documents')).rejects.toMatchObject({ status: 0 });
    });
});

describe('friendlyError', () => {
    it('shows the first validation message for 422', () => {
        expect(friendlyError(new ApiError(422, 'Invalid.', { file: ['This file has already been uploaded.'] }))).toBe('This file has already been uploaded.');
    });

    it('explains a 403 without server details', () => {
        expect(friendlyError(new ApiError(403, 'Only administrators can manage documents.'))).toBe("You don't have permission to do that.");
    });

    it('says when to retry after 429 and 503 if the server gave Retry-After', () => {
        expect(friendlyError(new ApiError(429, 'Too Many Attempts.', {}, 42))).toBe("You're sending requests too quickly. Please try again in 42 seconds.");
        expect(friendlyError(new ApiError(503, 'Unavailable', {}, 1))).toBe('The assistant is temporarily unavailable. Please try again in 1 second.');
        expect(friendlyError(new ApiError(503, 'Unavailable'))).toBe('The assistant is temporarily unavailable. Please try again shortly.');
    });

    it('never shows a raw server error message for 500', () => {
        expect(friendlyError(new ApiError(500, 'SQLSTATE[42P01] relation missing'))).toBe('Something went wrong on the server. Please try again.');
    });
});
