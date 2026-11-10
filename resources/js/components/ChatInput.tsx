import { useId, type FormEvent, type KeyboardEvent } from 'react';
import { formatSeconds } from '../lib/api';

export const MaxQuestionLength = 1000;

interface ChatInputProps {
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    isSending: boolean;
    retryIn: number;
}

/** Enter sends; Shift+Enter adds a new line. */
export default function ChatInput({ value, onChange, onSubmit, isSending, retryIn }: ChatInputProps) {
    const inputId = useId();
    const hintId = useId();
    const isBlocked = isSending || retryIn > 0;
    const canSend = !isBlocked && value.trim().length >= 3;

    function submit(event: FormEvent) {
        event.preventDefault();
        if (canSend) {
            onSubmit();
        }
    }

    function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            submit(event);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <label htmlFor={inputId} className="sr-only">
                Ask a question
            </label>
            <div className="flex items-end gap-2">
                <textarea
                    id={inputId}
                    rows={2}
                    maxLength={MaxQuestionLength}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    onKeyDown={onKeyDown}
                    aria-describedby={hintId}
                    placeholder="Ask a question about the knowledge base…"
                    className="min-h-12 flex-1 resize-y rounded-md border border-slate-400 bg-white px-3 py-2 text-slate-900 placeholder:text-slate-600"
                />
                <button
                    type="submit"
                    disabled={!canSend}
                    className="rounded-md bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {isSending ? 'Sending…' : 'Send'}
                </button>
            </div>
            <p id={hintId} className="text-xs text-slate-700">
                {retryIn > 0 ? `You can send again in ${formatSeconds(retryIn)}.` : 'Press Enter to send, Shift+Enter for a new line.'}
            </p>
        </form>
    );
}
