import { useEffect, useRef } from 'react';
import type { Citation, Source } from '../types';

interface SourcePanelProps {
    citation: Citation;
    source: Source | null;
    error: string | null;
    onClose: () => void;
}

/** Where in the document the chunk is: its heading trail, its page, or both. */
export function sourceLocation(source: { heading: string | null; page: number | null }): string | null {
    const parts = [source.heading, source.page !== null ? `Page ${source.page}` : null].filter((part): part is string => part !== null && part !== '');

    return parts.length > 0 ? parts.join(' · ') : null;
}

/**
 * Shows the chunk an answer cites. Focus moves to the panel when it opens so
 * keyboard and screen reader users land on the source; Escape closes it.
 * The chunk text is untrusted document content and is rendered as plain text.
 */
export default function SourcePanel({ citation, source, error, onClose }: SourcePanelProps) {
    const heading = useRef<HTMLHeadingElement>(null);

    useEffect(() => {
        heading.current?.focus();
    }, [citation.label, citation.chunk_id]);

    const location = sourceLocation(source ?? citation);

    return (
        <aside
            aria-labelledby="source-panel-title"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    onClose();
                }
            }}
            className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4"
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold tracking-wide text-blue-900 uppercase">Source {citation.label}</p>
                    <h2 id="source-panel-title" ref={heading} tabIndex={-1} className="text-base font-semibold text-slate-900">
                        {source?.document_title ?? citation.document_title}
                    </h2>
                    {location && <p className="text-sm text-slate-700">{location}</p>}
                </div>
                <button type="button" onClick={onClose} aria-label="Close source" className="rounded-md px-2 py-1 text-sm font-medium text-slate-800 hover:bg-slate-100">
                    Close
                </button>
            </div>

            {error ? (
                <p role="alert" className="text-sm text-red-800">
                    {error}
                </p>
            ) : source === null ? (
                <p role="status" className="text-sm text-slate-700">
                    Loading source…
                </p>
            ) : (
                <blockquote className="max-h-[60vh] overflow-y-auto rounded-md border-l-4 border-blue-700 bg-slate-50 px-4 py-3 text-sm whitespace-pre-wrap text-slate-900">
                    {source.content}
                </blockquote>
            )}
        </aside>
    );
}
