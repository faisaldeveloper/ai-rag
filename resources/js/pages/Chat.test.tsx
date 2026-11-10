import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockFetch, on, page } from '../test/fetch';
import type { ChatReply, Citation, Conversation, Message } from '../types';
import Chat from './Chat';

vi.mock('@inertiajs/react', () => import('../test/inertia'));

const citation: Citation = { label: 'S1', document_id: 7, document_title: 'Refund Policy', heading: 'Refunds', page: null, chunk_id: 99, score: 0.9 };

const message = (id: number, role: Message['role'], content: string, extra: Partial<Message> = {}): Message => ({
    id,
    role,
    content,
    fallback: false,
    citations: [],
    created_at: '2026-10-28T10:00:00Z',
    ...extra,
});

const conversation = (id: number, title: string, messages?: Message[]): Conversation => ({
    id,
    title,
    messages,
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T10:00:00Z',
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('Chat', () => {
    it('sends a question, shows a loading state, then the answer with a source chip that opens the source panel', async () => {
        let resolveChat: (value: unknown) => void = () => {};
        const reply: ChatReply = {
            conversation_id: 10,
            fallback: false,
            user_message: message(1, 'user', 'How long do refunds take?'),
            assistant_message: message(2, 'assistant', 'Refunds take 30 days [S1].', { citations: [citation] }),
        };
        let conversations: Conversation[] = [];
        mockFetch(
            on('GET', '/api/conversations?page=1', () => ({ body: page(conversations) })),
            on('POST', '/api/chat', (init) => {
                expect(JSON.parse(String(init.body))).toEqual({ message: 'How long do refunds take?', conversation_id: null });
                conversations = [conversation(10, 'How long do refunds take?')];

                return { status: 201, body: { data: reply } };
            }),
            on('GET', '/api/conversations/10/messages/2/sources/S1', () => ({
                body: { data: { ...citation, chunk_index: 0, content: 'Refunds are issued within 30 days of purchase.' } },
            })),
        );
        const fetchGate = globalThis.fetch;
        vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
            if (String(input) === '/api/chat') {
                await new Promise((resolve) => (resolveChat = resolve));
            }

            return fetchGate(input, init);
        });
        render(<Chat />);

        await userEvent.type(screen.getByLabelText('Ask a question'), 'How long do refunds take?{Enter}');

        expect(await screen.findByText('Searching the knowledge base…')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sending…' })).toBeDisabled();
        resolveChat(undefined);

        const chip = await screen.findByRole('button', { name: 'Source S1: Refund Policy' });
        expect(screen.queryByText('Searching the knowledge base…')).toBeNull();
        expect(await screen.findByRole('button', { name: 'How long do refunds take?' })).toHaveAttribute('aria-current', 'true');

        await userEvent.click(chip);

        const panel = await screen.findByRole('complementary', { name: 'Refund Policy' });
        expect(await within(panel).findByText('Refunds are issued within 30 days of purchase.')).toBeInTheDocument();
        expect(within(panel).getByText('Refunds')).toBeInTheDocument();

        await userEvent.click(within(panel).getByRole('button', { name: 'Close source' }));
        expect(screen.queryByRole('complementary')).toBeNull();
        expect(chip).toHaveFocus();
    });

    it('loads an earlier conversation and marks its fallback answer as not found', async () => {
        mockFetch(
            on('GET', '/api/conversations?page=1', () => ({ body: page([conversation(5, 'CEO salary?')]) })),
            on('GET', '/api/conversations/5', () => ({
                body: {
                    data: conversation(5, 'CEO salary?', [
                        message(1, 'user', 'What is the CEO salary?'),
                        message(2, 'assistant', 'The knowledge base does not contain enough information to answer this question.', { fallback: true }),
                    ]),
                },
            })),
        );
        render(<Chat />);

        await userEvent.click(await screen.findByRole('button', { name: 'CEO salary?' }));

        expect(await screen.findByText('Not found in the knowledge base')).toBeInTheDocument();
        expect(screen.getByText('What is the CEO salary?')).toBeInTheDocument();
    });

    it('keeps the question and blocks sending until Retry-After passes when rate limited', async () => {
        mockFetch(
            on('GET', '/api/conversations?page=1', () => ({ body: page([]) })),
            on('POST', '/api/chat', () => ({ status: 429, body: { message: 'Too Many Attempts.' }, headers: { 'Retry-After': '42' } })),
        );
        render(<Chat />);
        const input = screen.getByLabelText('Ask a question');

        await userEvent.type(input, 'Any news?{Enter}');

        expect(await screen.findByRole('alert')).toHaveTextContent("You're sending requests too quickly. Please try again in 42 seconds.");
        expect(input).toHaveValue('Any news?');
        expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
        expect(screen.getByText('You can send again in 42 seconds.')).toBeInTheDocument();
    });

    it.each([
        [503, { 'Retry-After': '30' }, 'The assistant is temporarily unavailable. Please try again in 30 seconds.'],
        [422, {}, 'The message field must be at least 3 characters.'],
    ])('shows a friendly message for a %s response', async (status, headers, expected) => {
        mockFetch(
            on('GET', '/api/conversations?page=1', () => ({ body: page([]) })),
            on('POST', '/api/chat', () => ({
                status,
                headers,
                body: { message: 'Error', errors: status === 422 ? { message: ['The message field must be at least 3 characters.'] } : undefined },
            })),
        );
        render(<Chat />);

        await userEvent.type(screen.getByLabelText('Ask a question'), 'Hello?{Enter}');

        expect(await screen.findByRole('alert')).toHaveTextContent(expected);
    });

    it('shows when a cited source is no longer available', async () => {
        mockFetch(
            on('GET', '/api/conversations?page=1', () => ({ body: page([conversation(5, 'Refunds')]) })),
            on('GET', '/api/conversations/5', () => ({
                body: { data: conversation(5, 'Refunds', [message(1, 'user', 'Refunds?'), message(2, 'assistant', 'Thirty days [S1].', { citations: [citation] })]) },
            })),
            on('GET', '/api/conversations/5/messages/2/sources/S1', () => ({ status: 404, body: { message: 'This source is no longer in the knowledge base.' } })),
        );
        render(<Chat />);

        await userEvent.click(await screen.findByRole('button', { name: 'Refunds' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Source S1: Refund Policy' }));

        const panel = screen.getByRole('complementary');
        expect(await within(panel).findByRole('alert')).toHaveTextContent('This source is no longer in the knowledge base.');
    });

    it('sends with Enter but adds a new line with Shift+Enter', async () => {
        const fetchMock = mockFetch(on('GET', '/api/conversations?page=1', () => ({ body: page([]) })));
        render(<Chat />);
        const input = screen.getByLabelText('Ask a question');

        await userEvent.type(input, 'First line{Shift>}{Enter}{/Shift}second');

        expect(input).toHaveValue('First line\nsecond');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
