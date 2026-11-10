import type { Citation } from '../types';

interface AnswerContentProps {
    content: string;
    citations: Citation[];
    activeLabel: string | null;
    onSelect: (citation: Citation) => void;
}

/**
 * Render an answer as plain text, turning each [S#] marker the answer actually
 * cites into a button that opens that source. Text is never parsed as HTML.
 */
export default function AnswerContent({ content, citations, activeLabel, onSelect }: AnswerContentProps) {
    const byLabel = new Map(citations.map((citation) => [citation.label, citation]));
    const parts = content.split(/\[(S\d+)\]/);

    return (
        <p className="whitespace-pre-wrap">
            {parts.map((part, index) => {
                if (index % 2 === 0) {
                    return part;
                }

                const citation = byLabel.get(part);
                if (citation === undefined) {
                    return `[${part}]`;
                }

                return (
                    <button
                        key={index}
                        type="button"
                        onClick={() => onSelect(citation)}
                        aria-pressed={activeLabel === part}
                        aria-label={`Source ${part}: ${citation.document_title}`}
                        className={`mx-0.5 inline-flex items-center rounded px-1.5 py-0.5 align-baseline text-xs font-semibold ring-1 ring-inset ${
                            activeLabel === part ? 'bg-blue-800 text-white ring-blue-800' : 'bg-blue-50 text-blue-900 ring-blue-300 hover:bg-blue-100'
                        }`}
                    >
                        {part}
                    </button>
                );
            })}
        </p>
    );
}
