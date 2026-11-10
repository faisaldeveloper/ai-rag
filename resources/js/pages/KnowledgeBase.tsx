import { useState } from 'react';
import Alert from '../components/Alert';
import ConfirmDialog from '../components/ConfirmDialog';
import DocumentsTable from '../components/DocumentsTable';
import DropZone, { type RejectedFile } from '../components/DropZone';
import { useDocuments } from '../hooks/useDocuments';
import AppLayout from '../layouts/AppLayout';
import { api, friendlyError } from '../lib/api';
import type { KnowledgeDocument } from '../types';

interface UploadResult {
    name: string;
    error: string | null;
}

export default function KnowledgeBase({ maxUploadKilobytes }: { maxUploadKilobytes: number }) {
    const [page, setPage] = useState(1);
    const { documents, lastPage, isLoading, isPolling, error, refresh } = useDocuments(page);
    const [uploading, setUploading] = useState<string | null>(null);
    const [results, setResults] = useState<UploadResult[]>([]);
    const [actionError, setActionError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [pendingDelete, setPendingDelete] = useState<KnowledgeDocument | null>(null);

    async function upload(accepted: File[], rejected: RejectedFile[]) {
        const outcome: UploadResult[] = rejected.map(({ name, reason }) => ({ name, error: reason }));
        setResults(outcome);
        setNotice(null);

        for (const file of accepted) {
            setUploading(file.name);
            const body = new FormData();
            body.append('file', file);

            try {
                await api('/documents', { method: 'POST', body });
                outcome.push({ name: file.name, error: null });
            } catch (caught) {
                outcome.push({ name: file.name, error: friendlyError(caught) });
            }
            setResults([...outcome]);
        }

        setUploading(null);
        if (accepted.length > 0) {
            setPage(1);
            await refresh();
        }
    }

    async function retry(document: KnowledgeDocument) {
        setBusyId(document.id);
        setActionError(null);
        try {
            await api(`/documents/${document.id}/retry`, { method: 'POST' });
            setNotice(`“${document.title}” was queued for processing again.`);
            await refresh();
        } catch (caught) {
            setActionError(friendlyError(caught));
        } finally {
            setBusyId(null);
        }
    }

    async function confirmDelete() {
        if (pendingDelete === null) {
            return;
        }
        const document = pendingDelete;
        setBusyId(document.id);
        setActionError(null);
        try {
            await api(`/documents/${document.id}`, { method: 'DELETE' });
            setNotice(`“${document.title}” was deleted.`);
            setPendingDelete(null);
            if (documents.length === 1 && page > 1) {
                setPage(page - 1);
            } else {
                await refresh();
            }
        } catch (caught) {
            setPendingDelete(null);
            setActionError(friendlyError(caught));
        } finally {
            setBusyId(null);
        }
    }

    return (
        <AppLayout title="Knowledge Base">
            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-2xl font-semibold text-slate-900">Knowledge Base</h1>
                    <p className="mt-1 text-slate-700">Upload documents for the assistant to answer from. New documents are processed in the background.</p>
                </div>

                <section aria-labelledby="upload-heading" className="flex flex-col gap-3">
                    <h2 id="upload-heading" className="sr-only">
                        Upload documents
                    </h2>
                    <DropZone maxUploadKilobytes={maxUploadKilobytes} disabled={uploading !== null} onFiles={(accepted, rejected) => void upload(accepted, rejected)} />
                    <div aria-live="polite" className="flex flex-col gap-2">
                        {uploading && <p className="text-sm text-slate-700">Uploading {uploading}…</p>}
                        {results.map((result, index) => (
                            <Alert key={`${result.name}-${index}`} tone={result.error ? 'error' : 'success'}>
                                <strong className="font-semibold">{result.name}:</strong> {result.error ?? 'Uploaded. It will be processed shortly.'}
                            </Alert>
                        ))}
                    </div>
                </section>

                <section aria-labelledby="documents-heading" className="flex flex-col gap-3">
                    <div className="flex items-center justify-between gap-4">
                        <h2 id="documents-heading" className="text-lg font-semibold text-slate-900">
                            Documents
                        </h2>
                        <p aria-live="polite" className="text-sm text-slate-700">
                            {isPolling ? 'Checking processing status every few seconds…' : ''}
                        </p>
                    </div>

                    {notice && (
                        <Alert tone="success" onDismiss={() => setNotice(null)}>
                            {notice}
                        </Alert>
                    )}
                    {(actionError ?? error) && (
                        <Alert tone="error" onDismiss={actionError ? () => setActionError(null) : undefined}>
                            {actionError ?? error}
                        </Alert>
                    )}

                    {isLoading ? (
                        <p role="status" className="text-slate-700">
                            Loading documents…
                        </p>
                    ) : (
                        <DocumentsTable documents={documents} busyId={busyId} onRetry={(document) => void retry(document)} onDelete={setPendingDelete} />
                    )}

                    {lastPage > 1 && (
                        <nav aria-label="Documents pages" className="flex items-center justify-end gap-3 text-sm">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                                className="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium disabled:opacity-50"
                            >
                                Previous
                            </button>
                            <span className="text-slate-700">
                                Page {page} of {lastPage}
                            </span>
                            <button
                                type="button"
                                disabled={page >= lastPage}
                                onClick={() => setPage(page + 1)}
                                className="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium disabled:opacity-50"
                            >
                                Next
                            </button>
                        </nav>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={pendingDelete !== null}
                title="Delete document?"
                message={`“${pendingDelete?.title ?? ''}” and everything learned from it will be removed from the knowledge base. This cannot be undone.`}
                confirmLabel="Delete"
                busy={busyId !== null && busyId === pendingDelete?.id}
                onConfirm={() => void confirmDelete()}
                onCancel={() => setPendingDelete(null)}
            />
        </AppLayout>
    );
}
