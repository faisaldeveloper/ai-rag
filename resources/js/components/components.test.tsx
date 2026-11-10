import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { Citation, Message } from '../types';
import AnswerContent from './AnswerContent';
import DropZone, { formatLimit, partitionFiles } from './DropZone';
import MessageItem from './MessageItem';
import SourcePanel, { sourceLocation } from './SourcePanel';
import StatusBadge from './StatusBadge';

const citation: Citation = {
    label: 'S1',
    document_id: 7,
    document_title: 'Refund Policy',
    heading: 'Policy > Refunds',
    page: null,
    chunk_id: 99,
    score: 0.91,
};

const file = (name: string, size = 10) => new File(['x'.repeat(size)], name, { type: 'text/plain' });

describe('StatusBadge', () => {
    it.each([
        ['pending', 'Pending'],
        ['processing', 'Processing'],
        ['completed', 'Completed'],
        ['failed', 'Failed'],
    ] as const)('spells out the %s status', (status, label) => {
        render(<StatusBadge status={status} />);

        expect(screen.getByText(label)).toBeInTheDocument();
    });
});

describe('DropZone', () => {
    it('accepts txt and md files and rejects other types, oversized and empty files', () => {
        const [accepted, rejected] = partitionFiles([file('notes.md'), file('a.TXT'), file('report.pdf'), file('big.txt', 3 * 1024), file('empty.md', 0)], 2);

        expect(accepted.map((accepted) => accepted.name)).toEqual(['notes.md', 'a.TXT']);
        expect(rejected).toEqual([
            { name: 'report.pdf', reason: 'Only .txt and .md files can be uploaded.' },
            { name: 'big.txt', reason: 'Files can be at most 2 KB.' },
            { name: 'empty.md', reason: 'The file is empty.' },
        ]);
    });

    it('describes the upload limit in MB or KB', () => {
        expect(formatLimit(5120)).toBe('5 MB');
        expect(formatLimit(512)).toBe('512 KB');
    });

    it('passes dropped files on', () => {
        const onFiles = vi.fn();
        render(<DropZone maxUploadKilobytes={5120} onFiles={onFiles} />);

        fireEvent.drop(screen.getByTestId('drop-zone'), { dataTransfer: { files: [file('guide.md')] } });

        expect(onFiles).toHaveBeenCalledWith([expect.objectContaining({ name: 'guide.md' })], []);
    });

    it('is a labelled file input that keyboard users can reach', async () => {
        const onFiles = vi.fn();
        render(<DropZone maxUploadKilobytes={5120} onFiles={onFiles} />);
        const input = screen.getByLabelText(/choose files/i);

        await userEvent.tab();
        expect(input).toHaveFocus();

        await userEvent.upload(input, file('faq.txt'));
        expect(onFiles).toHaveBeenCalledWith([expect.objectContaining({ name: 'faq.txt' })], []);
    });
});

describe('AnswerContent', () => {
    it('turns cited labels into buttons and leaves unknown labels as text', async () => {
        const onSelect = vi.fn();
        render(<AnswerContent content="Refunds take 30 days [S1]. Shipping is free [S4]." citations={[citation]} activeLabel={null} onSelect={onSelect} />);

        await userEvent.click(screen.getByRole('button', { name: 'Source S1: Refund Policy' }));

        expect(onSelect).toHaveBeenCalledWith(citation);
        expect(screen.getAllByRole('button')).toHaveLength(1);
        expect(screen.getByText(/Shipping is free \[S4\]\./)).toBeInTheDocument();
    });

    it('renders markup in an answer as text, never as HTML', () => {
        const { container } = render(<AnswerContent content={'<img src=x onerror="alert(1)"> [S1]'} citations={[citation]} activeLabel="S1" onSelect={() => {}} />);

        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByRole('button', { name: /S1/ })).toHaveAttribute('aria-pressed', 'true');
    });
});

describe('MessageItem', () => {
    const answer: Message = { id: 2, role: 'assistant', content: 'Refunds take 30 days [S1].', fallback: false, citations: [citation], created_at: '' };

    it('shows fallback answers clearly as not found in the knowledge base', () => {
        render(
            <ul>
                <MessageItem
                    message={{ ...answer, content: 'The knowledge base does not contain enough information to answer this question.', fallback: true, citations: [] }}
                    activeLabel={null}
                    onSelectCitation={() => {}}
                />
            </ul>,
        );

        expect(screen.getByText('Not found in the knowledge base')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('passes the message and citation on when a source chip is chosen', async () => {
        const onSelectCitation = vi.fn();
        render(
            <ul>
                <MessageItem message={answer} activeLabel={null} onSelectCitation={onSelectCitation} />
            </ul>,
        );

        await userEvent.click(screen.getByRole('button', { name: /Source S1/ }));

        expect(onSelectCitation).toHaveBeenCalledWith(answer, citation);
    });
});

describe('SourcePanel', () => {
    const source = { ...citation, chunk_index: 0, page: 3, content: 'Refunds are issued within 30 days.' };

    it('shows the document title, location and chunk text, and moves focus to the title', () => {
        render(<SourcePanel citation={citation} source={source} error={null} onClose={() => {}} />);

        expect(screen.getByRole('heading', { name: 'Refund Policy' })).toHaveFocus();
        expect(screen.getByText('Policy > Refunds · Page 3')).toBeInTheDocument();
        expect(screen.getByText('Refunds are issued within 30 days.')).toBeInTheDocument();
    });

    it('shows a loading state, then errors, and closes with Escape', async () => {
        const onClose = vi.fn();
        const { rerender } = render(<SourcePanel citation={citation} source={null} error={null} onClose={onClose} />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading source…');

        rerender(<SourcePanel citation={citation} source={null} error="This source is no longer in the knowledge base." onClose={onClose} />);
        expect(screen.getByRole('alert')).toHaveTextContent('This source is no longer in the knowledge base.');

        await userEvent.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalled();
    });

    it('describes where the chunk is from its heading, page, or both', () => {
        expect(sourceLocation({ heading: 'Guide', page: null })).toBe('Guide');
        expect(sourceLocation({ heading: null, page: 2 })).toBe('Page 2');
        expect(sourceLocation({ heading: null, page: null })).toBeNull();
    });
});
