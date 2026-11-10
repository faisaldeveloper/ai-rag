import type { KnowledgeDocument } from '../types';
import StatusBadge from './StatusBadge';

interface DocumentsTableProps {
    documents: KnowledgeDocument[];
    busyId: number | null;
    onRetry: (document: KnowledgeDocument) => void;
    onDelete: (document: KnowledgeDocument) => void;
}

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

export default function DocumentsTable({ documents, busyId, onRetry, onDelete }: DocumentsTableProps) {
    if (documents.length === 0) {
        return <p className="rounded-lg border border-slate-200 bg-white px-4 py-8 text-center text-slate-700">No documents yet. Upload one above to get started.</p>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                <caption className="sr-only">Documents in the knowledge base</caption>
                <thead className="bg-slate-50 text-slate-800">
                    <tr>
                        <th scope="col" className="px-4 py-3 font-semibold">Title</th>
                        <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" className="px-4 py-3 font-semibold">Uploaded</th>
                        <th scope="col" className="px-4 py-3 font-semibold"><span className="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                    {documents.map((document) => (
                        <tr key={document.id}>
                            <th scope="row" className="px-4 py-3 font-medium text-slate-900">
                                {document.title}
                                <span className="block font-normal text-slate-600">{document.original_filename}</span>
                            </th>
                            <td className="px-4 py-3">
                                <StatusBadge status={document.status} />
                                {document.error && <span className="mt-1 block text-red-800">{document.error}</span>}
                            </td>
                            <td className="px-4 py-3 whitespace-nowrap text-slate-700">{dateFormat.format(new Date(document.created_at))}</td>
                            <td className="px-4 py-3">
                                <div className="flex justify-end gap-2">
                                    {document.status === 'failed' && (
                                        <button
                                            type="button"
                                            onClick={() => onRetry(document)}
                                            disabled={busyId === document.id}
                                            aria-label={`Retry ${document.title}`}
                                            className="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-900 hover:bg-slate-100 disabled:opacity-60"
                                        >
                                            Retry
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => onDelete(document)}
                                        disabled={busyId === document.id}
                                        aria-label={`Delete ${document.title}`}
                                        className="rounded-md border border-red-300 px-3 py-1.5 font-medium text-red-800 hover:bg-red-50 disabled:opacity-60"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
