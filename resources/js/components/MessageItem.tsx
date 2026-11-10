import type { Citation, Message } from '../types';
import AnswerContent from './AnswerContent';

interface MessageItemProps {
    message: Message;
    activeLabel: string | null;
    onSelectCitation: (message: Message, citation: Citation) => void;
}

export default function MessageItem({ message, activeLabel, onSelectCitation }: MessageItemProps) {
    if (message.role === 'user') {
        return (
            <li className="flex justify-end">
                <div className="max-w-[85%] rounded-lg bg-blue-800 px-4 py-2 text-white">
                    <span className="sr-only">You: </span>
                    <p className="whitespace-pre-wrap">{message.content}</p>
                </div>
            </li>
        );
    }

    if (message.fallback) {
        return (
            <li className="flex">
                <div className="max-w-[85%] rounded-lg border border-amber-400 bg-amber-50 px-4 py-3 text-amber-950">
                    <p className="font-semibold">Not found in the knowledge base</p>
                    <p className="mt-1 text-sm">{message.content}</p>
                </div>
            </li>
        );
    }

    return (
        <li className="flex">
            <div className="max-w-[85%] rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-900">
                <span className="sr-only">Assistant: </span>
                <AnswerContent
                    content={message.content}
                    citations={message.citations}
                    activeLabel={activeLabel}
                    onSelect={(citation) => onSelectCitation(message, citation)}
                />
            </div>
        </li>
    );
}
