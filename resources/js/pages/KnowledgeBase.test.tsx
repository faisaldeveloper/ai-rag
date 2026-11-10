import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PollIntervalMs } from '../hooks/useDocuments';
import { mockFetch, on, page } from '../test/fetch';
import type { DocumentStatus, KnowledgeDocument } from '../types';
import KnowledgeBase from './KnowledgeBase';

vi.mock('@inertiajs/react', () => import('../test/inertia'));

const doc = (id: number, status: DocumentStatus, title = `Doc ${id}`): KnowledgeDocument => ({
    id,
    title,
    original_filename: `${title.toLowerCase().replace(/\s/g, '-')}.md`,
    mime_type: 'text/markdown',
    checksum: 'abc',
    status,
    error: status === 'failed' ? 'Processing failed. The document can be retried.' : null,
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T10:00:00Z',
});

const renderPage = () => render(<KnowledgeBase maxUploadKilobytes={5120} />);

beforeEach(() => {
    HTMLDialogElement.prototype.showModal ??= function (this: HTMLDialogElement) {
        this.open = true;
    };
    HTMLDialogElement.prototype.close ??= function (this: HTMLDialogElement) {
        this.open = false;
    };
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('KnowledgeBase', () => {
    it('lists documents with their status badges', async () => {
        mockFetch(on('GET', '/api/documents?page=1', () => ({ body: page([doc(1, 'completed', 'Refund Policy'), doc(2, 'failed', 'Old Guide')]) })));

        renderPage();

        const table = await screen.findByRole('table', { name: 'Documents in the knowledge base' });
        expect(within(table).getByRole('rowheader', { name: /Refund Policy/ })).toBeInTheDocument();
        expect(within(table).getByText('Completed')).toBeInTheDocument();
        expect(within(table).getByText('Failed')).toBeInTheDocument();
        expect(within(table).getByText('Processing failed. The document can be retried.')).toBeInTheDocument();
    });

    it('refreshes every 3 seconds while a document is in progress and stops once none are', async () => {
        vi.useFakeTimers();
        const responses = [[doc(1, 'pending')], [doc(1, 'processing')], [doc(1, 'completed')]];
        const fetchMock = mockFetch(on('GET', '/api/documents?page=1', () => ({ body: page(responses.shift() ?? [doc(1, 'completed')]) })));

        renderPage();
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(screen.getByText('Pending')).toBeInTheDocument();
        expect(screen.getByText('Checking processing status every few seconds…')).toBeInTheDocument();

        await act(() => vi.advanceTimersByTimeAsync(PollIntervalMs));
        expect(screen.getByText('Processing')).toBeInTheDocument();

        await act(() => vi.advanceTimersByTimeAsync(PollIntervalMs));
        expect(screen.getByText('Completed')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(3);

        await act(() => vi.advanceTimersByTimeAsync(PollIntervalMs * 3));
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('does not poll when every document is settled', async () => {
        vi.useFakeTimers();
        const fetchMock = mockFetch(on('GET', '/api/documents?page=1', () => ({ body: page([doc(1, 'completed'), doc(2, 'failed')]) })));

        renderPage();
        await act(() => vi.advanceTimersByTimeAsync(PollIntervalMs * 3));

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('uploads dropped files and shows the server validation error for a rejected one', async () => {
        const uploads: string[] = [];
        mockFetch(
            on('GET', '/api/documents?page=1', () => ({ body: page([]) })),
            on('POST', '/api/documents', (init) => {
                const name = ((init.body as FormData).get('file') as File).name;
                uploads.push(name);

                return name === 'dup.md'
                    ? { status: 422, body: { message: 'Invalid.', errors: { file: ['This file has already been uploaded.'] } } }
                    : { status: 201, body: { data: doc(5, 'pending', 'New') } };
            }),
        );
        renderPage();
        await screen.findByText(/No documents yet/);

        fireEvent.drop(screen.getByTestId('drop-zone'), {
            dataTransfer: { files: [new File(['a'], 'new.md'), new File(['b'], 'dup.md'), new File(['c'], 'scan.pdf')] },
        });

        expect(await screen.findByText(/This file has already been uploaded\./)).toBeInTheDocument();
        expect(screen.getByText(/Uploaded\. It will be processed shortly\./)).toBeInTheDocument();
        expect(screen.getByText(/Only \.txt and \.md files can be uploaded\./)).toBeInTheDocument();
        expect(uploads).toEqual(['new.md', 'dup.md']);
    });

    it('retries a failed document', async () => {
        let retried = false;
        mockFetch(
            on('GET', '/api/documents?page=1', () => ({ body: page([doc(3, retried ? 'completed' : 'failed', 'Old Guide')]) })),
            on('POST', '/api/documents/3/retry', () => {
                retried = true;

                return { body: { data: doc(3, 'pending', 'Old Guide') } };
            }),
        );
        renderPage();

        await userEvent.click(await screen.findByRole('button', { name: 'Retry Old Guide' }));

        expect(await screen.findByText('“Old Guide” was queued for processing again.')).toBeInTheDocument();
        expect(retried).toBe(true);
    });

    it('deletes a document only after confirmation', async () => {
        let deleted = false;
        mockFetch(
            on('GET', '/api/documents?page=1', () => ({ body: page(deleted ? [] : [doc(4, 'completed', 'Handbook')]) })),
            on('DELETE', '/api/documents/4', () => {
                deleted = true;

                return { status: 204 };
            }),
        );
        renderPage();

        await userEvent.click(await screen.findByRole('button', { name: 'Delete Handbook' }));
        const dialog = screen.getByRole('dialog', { name: 'Delete document?' });
        await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(deleted).toBe(false);

        await userEvent.click(screen.getByRole('button', { name: 'Delete Handbook' }));
        await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }));

        expect(await screen.findByText('“Handbook” was deleted.')).toBeInTheDocument();
        await waitFor(() => expect(screen.getByText(/No documents yet/)).toBeInTheDocument());
        expect(deleted).toBe(true);
    });

    it('shows a friendly message when the user is not allowed', async () => {
        mockFetch(on('GET', '/api/documents?page=1', () => ({ status: 403, body: { message: 'Only administrators can manage documents.' } })));

        renderPage();

        expect(await screen.findByRole('alert')).toHaveTextContent("You don't have permission to do that.");
    });
});
