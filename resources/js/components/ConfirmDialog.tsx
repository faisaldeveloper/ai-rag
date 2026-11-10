import { useEffect, useId, useRef } from 'react';

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    message: string;
    confirmLabel: string;
    busy?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * A modal confirmation built on <dialog>: the browser traps focus inside it,
 * Escape cancels, and focus returns to the opener when it closes.
 * Cancel is focused first so Enter never confirms a destructive action by accident.
 */
export default function ConfirmDialog({ open, title, message, confirmLabel, busy = false, onConfirm, onCancel }: ConfirmDialogProps) {
    const dialog = useRef<HTMLDialogElement>(null);
    const cancelButton = useRef<HTMLButtonElement>(null);
    const titleId = useId();
    const messageId = useId();

    useEffect(() => {
        const element = dialog.current;
        if (element === null) {
            return;
        }

        if (open && !element.open) {
            element.showModal();
            cancelButton.current?.focus();
        } else if (!open && element.open) {
            element.close();
        }
    }, [open]);

    return (
        <dialog
            ref={dialog}
            aria-labelledby={titleId}
            aria-describedby={messageId}
            onCancel={(event) => {
                event.preventDefault();
                onCancel();
            }}
            className="m-auto w-full max-w-md rounded-lg p-6 shadow-xl backdrop:bg-slate-900/50"
        >
            <h2 id={titleId} className="text-lg font-semibold text-slate-900">
                {title}
            </h2>
            <p id={messageId} className="mt-2 text-sm text-slate-700">
                {message}
            </p>
            <div className="mt-6 flex justify-end gap-3">
                <button
                    ref={cancelButton}
                    type="button"
                    onClick={onCancel}
                    className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    onClick={onConfirm}
                    disabled={busy}
                    className="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60"
                >
                    {busy ? 'Deleting…' : confirmLabel}
                </button>
            </div>
        </dialog>
    );
}
