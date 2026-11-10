import type { DocumentStatus } from '../types';

const styles: Record<DocumentStatus, { label: string; className: string }> = {
    pending: { label: 'Pending', className: 'bg-slate-100 text-slate-800 ring-slate-300' },
    processing: { label: 'Processing', className: 'bg-blue-100 text-blue-900 ring-blue-300' },
    completed: { label: 'Completed', className: 'bg-green-100 text-green-900 ring-green-300' },
    failed: { label: 'Failed', className: 'bg-red-100 text-red-900 ring-red-300' },
};

/** The status is always spelled out, so it never depends on colour alone. */
export default function StatusBadge({ status }: { status: DocumentStatus }) {
    const { label, className } = styles[status];

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-sm font-medium ring-1 ring-inset ${className}`}>
            {status === 'processing' && <span aria-hidden="true" className="size-2 animate-pulse rounded-full bg-blue-700" />}
            {label}
        </span>
    );
}
