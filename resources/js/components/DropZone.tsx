import { useId, useState, type DragEvent } from 'react';

export const AcceptedExtensions = ['txt', 'md'];

export interface RejectedFile {
    name: string;
    reason: string;
}

interface DropZoneProps {
    maxUploadKilobytes: number;
    disabled?: boolean;
    onFiles: (accepted: File[], rejected: RejectedFile[]) => void;
}

/** Describe an upload limit in the largest whole unit, e.g. "5 MB" or "512 KB". */
export function formatLimit(kilobytes: number): string {
    return kilobytes >= 1024 && kilobytes % 1024 === 0 ? `${kilobytes / 1024} MB` : `${kilobytes} KB`;
}

/**
 * Check type and size before uploading so obvious mistakes fail instantly.
 * The server validates again; this is only a convenience.
 */
export function partitionFiles(files: File[], maxUploadKilobytes: number): [File[], RejectedFile[]] {
    const accepted: File[] = [];
    const rejected: RejectedFile[] = [];

    for (const file of files) {
        const extension = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : '';

        if (!AcceptedExtensions.includes(extension)) {
            rejected.push({ name: file.name, reason: 'Only .txt and .md files can be uploaded.' });
        } else if (file.size > maxUploadKilobytes * 1024) {
            rejected.push({ name: file.name, reason: `Files can be at most ${formatLimit(maxUploadKilobytes)}.` });
        } else if (file.size === 0) {
            rejected.push({ name: file.name, reason: 'The file is empty.' });
        } else {
            accepted.push(file);
        }
    }

    return [accepted, rejected];
}

/**
 * A drop target that is also a real file input: keyboard users Tab to it and
 * press Enter or Space to open the file picker.
 */
export default function DropZone({ maxUploadKilobytes, disabled = false, onFiles }: DropZoneProps) {
    const [isDragging, setIsDragging] = useState(false);
    const inputId = useId();
    const hintId = useId();

    function handle(files: FileList | null) {
        if (files === null || files.length === 0) {
            return;
        }
        onFiles(...partitionFiles(Array.from(files), maxUploadKilobytes));
    }

    function onDrop(event: DragEvent) {
        event.preventDefault();
        setIsDragging(false);
        if (!disabled) {
            handle(event.dataTransfer.files);
        }
    }

    return (
        <div
            onDragOver={(event) => {
                event.preventDefault();
                setIsDragging(true);
            }}
            onDragLeave={() => setIsDragging(false)}
            onDrop={onDrop}
            data-testid="drop-zone"
            className={`relative rounded-lg border-2 border-dashed px-6 py-8 text-center has-[:focus-visible]:outline-3 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-blue-700 ${
                isDragging ? 'border-blue-700 bg-blue-50' : 'border-slate-400 bg-white'
            }`}
        >
            <label htmlFor={inputId} className="cursor-pointer">
                <span className="block font-medium text-slate-900">
                    <span className="text-blue-800 underline underline-offset-2">Choose files</span> or drag and drop them here
                </span>
                <span id={hintId} className="mt-1 block text-sm text-slate-700">
                    .txt or .md, up to {formatLimit(maxUploadKilobytes)} each
                </span>
            </label>
            <input
                id={inputId}
                type="file"
                multiple
                accept=".txt,.md,text/plain,text/markdown"
                disabled={disabled}
                aria-describedby={hintId}
                className="sr-only"
                onChange={(event) => {
                    handle(event.target.files);
                    event.target.value = '';
                }}
            />
        </div>
    );
}
