import type { ReactNode } from 'react';

interface AlertProps {
    tone?: 'error' | 'success' | 'info';
    children: ReactNode;
    onDismiss?: () => void;
}

const tones = {
    error: 'border-red-300 bg-red-50 text-red-900',
    success: 'border-green-300 bg-green-50 text-green-900',
    info: 'border-blue-300 bg-blue-50 text-blue-900',
};

/** Errors use role="alert" so screen readers announce them immediately; other notices are polite. */
export default function Alert({ tone = 'info', children, onDismiss }: AlertProps) {
    return (
        <div role={tone === 'error' ? 'alert' : 'status'} className={`flex items-start justify-between gap-4 rounded-md border px-4 py-3 text-sm ${tones[tone]}`}>
            <div>{children}</div>
            {onDismiss && (
                <button type="button" onClick={onDismiss} className="shrink-0 rounded font-medium underline underline-offset-2">
                    Dismiss
                </button>
            )}
        </div>
    );
}
